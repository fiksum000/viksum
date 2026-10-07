<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Support\Audit;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerImportExportController
{
    private const HEADER_ALIASES = [
        'customer_code' => ['customer_code', 'customer_id', 'kode_pelanggan', 'id_pelanggan', 'kode'],
        'name' => ['name', 'nama', 'nama_pelanggan', 'customer_name'],
        'phone' => ['phone', 'telephone', 'telp', 'nomor_hp', 'handphone'],
        'whatsapp_number' => ['whatsapp_number', 'whatsapp', 'wa', 'nomor_wa'],
        'email' => ['email', 'email_address'],
        'address' => ['address', 'alamat', 'alamat_pelanggan'],
        'rt' => ['rt'], 'rw' => ['rw'], 'village' => ['village', 'desa', 'kelurahan'],
        'district' => ['district', 'kecamatan'], 'city' => ['city', 'kabupaten', 'kota'],
        'service_type' => ['service_type', 'jenis_layanan'], 'status' => ['status'],
        'due_day' => ['due_day', 'tanggal_jatuh_tempo', 'jatuh_tempo'],
        'grace_days' => ['grace_days', 'masa_tenggang'], 'package' => ['package', 'paket', 'paket_internet'],
        'router' => ['router', 'router_name', 'mikrotik'],
        'pppoe_username' => ['pppoe_username', 'username_pppoe', 'username', 'user', 'secret'],
        'pppoe_password' => ['pppoe_password', 'password_pppoe', 'password', 'pass'],
        'pppoe_profile_normal' => ['pppoe_profile_normal', 'profile', 'profile_pppoe'],
        'pppoe_ip' => ['pppoe_ip', 'ip_address', 'ip'], 'pppoe_mac' => ['pppoe_mac', 'mac_address', 'mac'],
        'activated_at' => ['activated_at', 'tanggal_aktif', 'active_date'],
    ];

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return back()->with('error', 'File tidak berisi baris data.');
        }

        $headers = array_map(fn ($header) => $this->canonicalHeader((string) $header), array_shift($rows));
        if (!in_array('pppoe_username', $headers, true)) {
            return back()->with('error', 'Kolom username PPPoE tidak ditemukan. Gunakan template import Billing atau export Mikhmon.');
        }

        $count = 0;
        $duplicateWhatsapp = 0;
        $invalidWhatsapp = 0;
        DB::transaction(function () use ($rows, $headers, &$count, &$duplicateWhatsapp, &$invalidWhatsapp): void {
            foreach ($rows as $row) {
                $data = [];
                foreach ($headers as $index => $header) {
                    if ($header !== null) {
                        $data[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
                    }
                }
                $username = trim((string) ($data['pppoe_username'] ?? ''));
                if ($username === '') {
                    continue;
                }

                $code = trim((string) ($data['customer_code'] ?? '')) ?: 'MHM-'.$username;
                $whatsapp = WhatsappNumber::normalize($data['whatsapp_number'] ?? null);
                if (!WhatsappNumber::isValid($whatsapp)) {
                    $invalidWhatsapp++;
                    continue;
                }
                if ($whatsapp !== null && Customer::where('whatsapp_number', $whatsapp)
                    ->where('customer_code', '!=', $code)->exists()) {
                    $duplicateWhatsapp++;
                    continue;
                }

                $package = !empty($data['package']) ? Package::where('name', $data['package'])->first() : null;
                $router = !empty($data['router']) ? Router::where('name', $data['router'])->first() : null;
                $service = strtolower((string) ($data['service_type'] ?? 'pppoe'));
                $status = strtolower((string) ($data['status'] ?? 'active'));
                if (!in_array($service, ['pppoe', 'hotspot'], true)) $service = 'pppoe';
                if (!in_array($status, ['active', 'isolated', 'suspended', 'terminated', 'trial'], true)) $status = 'active';

                $attributes = [
                    'name' => trim((string) ($data['name'] ?? '')) ?: $username,
                    'phone' => $data['phone'] ?? null,
                    'whatsapp_number' => $whatsapp,
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'rt' => $data['rt'] ?? null,
                    'rw' => $data['rw'] ?? null,
                    'village' => $data['village'] ?? null,
                    'district' => $data['district'] ?? null,
                    'city' => $data['city'] ?? null,
                    'service_type' => $service,
                    'status' => $status,
                    'due_day' => max(1, min(28, (int) ($data['due_day'] ?? 20))),
                    'grace_days' => is_numeric($data['grace_days'] ?? null) ? (int) $data['grace_days'] : null,
                    'activated_at' => $data['activated_at'] ?: null,
                    'package_id' => $package?->id,
                    'router_id' => $router?->id,
                    'pppoe_username' => $username,
                    'pppoe_profile_normal' => $data['pppoe_profile_normal'] ?? $package?->normal_profile,
                    'pppoe_ip' => $data['pppoe_ip'] ?? null,
                    'pppoe_mac' => $data['pppoe_mac'] ?? null,
                    'is_auto_isolate' => true,
                ];
                foreach (['pppoe_password' => 'pppoe_password'] as $source => $target) {
                    if (!empty($data[$source])) $attributes[$target] = $data[$source];
                }
                Customer::updateOrCreate(['customer_code' => $code], $attributes);
                $count++;
            }
        });

        Audit::log('customers.imported', Customer::class, null, ['count' => $count, 'format' => 'xlsx/csv/mikhmon']);
        $message = "{$count} pelanggan berhasil diimpor.";
        if ($duplicateWhatsapp > 0 || $invalidWhatsapp > 0) {
            $message .= " {$duplicateWhatsapp} baris duplikat dan {$invalidWhatsapp} nomor tidak valid dilewati.";
        }

        return back()->with('success', $message);
    }

    public function export()
    {
        $headers = ['customer_code', 'name', 'whatsapp_number', 'phone', 'email', 'area', 'address', 'rt', 'rw', 'village', 'district', 'city', 'service_type', 'status', 'due_day', 'grace_days', 'package', 'router', 'pppoe_username', 'pppoe_profile_normal', 'pppoe_profile_isolir', 'pppoe_ip', 'pppoe_mac', 'activated_at', 'registered_at', 'modem_device', 'source_port', 'olt_name', 'pon_port', 'onu_id', 'onu_sn', 'latitude', 'longitude', 'notes'];
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);

        $line = 2;
        foreach (Customer::with(['package', 'router'])->cursor() as $customer) {
            $values = [$customer->customer_code, $customer->name, $customer->whatsapp_number, $customer->phone, $customer->email, $customer->area, $customer->address, $customer->rt, $customer->rw, $customer->village, $customer->district, $customer->city, $customer->service_type, $customer->status, $customer->due_day, $customer->grace_days, $customer->package?->name, $customer->router?->name, $customer->pppoe_username, $customer->pppoe_profile_normal, $customer->pppoe_profile_isolir, $customer->pppoe_ip, $customer->pppoe_mac, $customer->activated_at?->format('Y-m-d'), $customer->registered_at?->format('Y-m-d'), $customer->modem_device, $customer->source_port, $customer->olt_name, $customer->pon_port, $customer->onu_id, $customer->onu_sn, $customer->latitude, $customer->longitude, $customer->notes];
            foreach ($values as $index => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1).$line;
                $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
            }
            $line++;
        }

        $file = tempnam(sys_get_temp_dir(), 'customers_').'.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        return response()->download($file, 'customers.xlsx')->deleteFileAfterSend(true);
    }

    private function canonicalHeader(string $header): ?string
    {
        $key = Str::lower(Str::ascii(trim($header)));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            if (in_array($key, $aliases, true)) return $canonical;
        }
        return null;
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Support\Audit;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerImportExportController
{
    private const HEADER_ALIASES = [
        'customer_code' => ['customer_code', 'customer_id', 'kode_pelanggan', 'id_pelanggan', 'kode'],
        'name' => ['name', 'nama', 'nama_pelanggan', 'customer_name'],
        'phone' => ['phone', 'telephone', 'telp', 'nomor_hp', 'handphone'],
        'whatsapp_number' => ['whatsapp_number', 'whatsapp', 'wa', 'nomor_wa'],
        'email' => ['email', 'email_address'],
        'address' => ['address', 'alamat', 'alamat_pelanggan'],
        'rt' => ['rt'], 'rw' => ['rw'], 'village' => ['village', 'desa', 'kelurahan'],
        'district' => ['district', 'kecamatan'], 'city' => ['city', 'kabupaten', 'kota'],
        'service_type' => ['service_type', 'jenis_layanan'], 'status' => ['status'],
        'due_day' => ['due_day', 'tanggal_jatuh_tempo', 'jatuh_tempo'],
        'grace_days' => ['grace_days', 'masa_tenggang'], 'package' => ['package', 'paket', 'paket_internet'],
        'router' => ['router', 'router_name', 'mikrotik'],
        'pppoe_username' => ['pppoe_username', 'username_pppoe', 'username', 'user', 'secret'],
        'pppoe_password' => ['pppoe_password', 'password_pppoe', 'password', 'pass'],
        'pppoe_profile_normal' => ['pppoe_profile_normal', 'profile', 'profile_pppoe'],
        'pppoe_ip' => ['pppoe_ip', 'ip_address', 'ip'], 'pppoe_mac' => ['pppoe_mac', 'mac_address', 'mac'],
        'activated_at' => ['activated_at', 'tanggal_aktif', 'active_date'],
    ];

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return back()->with('error', 'File tidak berisi baris data.');
        }

        $headers = array_map(fn ($header) => $this->canonicalHeader((string) $header), array_shift($rows));
        if (!in_array('pppoe_username', $headers, true)) {
            return back()->with('error', 'Kolom username PPPoE tidak ditemukan. Gunakan template import Billing atau export Mikhmon.');
        }

        $count = 0;
        $duplicateWhatsapp = 0;
        $invalidWhatsapp = 0;
        DB::transaction(function () use ($rows, $headers, &$count, &$duplicateWhatsapp, &$invalidWhatsapp): void {
            foreach ($rows as $row) {
                $data = [];
                foreach ($headers as $index => $header) {
                    if ($header !== null) {
                        $data[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
                    }
                }
                $username = trim((string) ($data['pppoe_username'] ?? ''));
                if ($username === '') {
                    continue;
                }

                $code = trim((string) ($data['customer_code'] ?? '')) ?: 'MHM-'.$username;
                $whatsapp = WhatsappNumber::normalize($data['whatsapp_number'] ?? null);
                if (!WhatsappNumber::isValid($whatsapp)) {
                    $invalidWhatsapp++;
                    continue;
                }
                if ($whatsapp !== null && Customer::where('whatsapp_number', $whatsapp)
                    ->where('customer_code', '!=', $code)->exists()) {
                    $duplicateWhatsapp++;
                    continue;
                }

                $package = !empty($data['package']) ? Package::where('name', $data['package'])->first() : null;
                $router = !empty($data['router']) ? Router::where('name', $data['router'])->first() : null;
                $service = strtolower((string) ($data['service_type'] ?? 'pppoe'));
                $status = strtolower((string) ($data['status'] ?? 'active'));
                if (!in_array($service, ['pppoe', 'hotspot'], true)) $service = 'pppoe';
                if (!in_array($status, ['active', 'isolated', 'suspended', 'terminated', 'trial'], true)) $status = 'active';

                $attributes = [
                    'name' => trim((string) ($data['name'] ?? '')) ?: $username,
                    'phone' => $data['phone'] ?? null,
                    'whatsapp_number' => $whatsapp,
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'rt' => $data['rt'] ?? null,
                    'rw' => $data['rw'] ?? null,
                    'village' => $data['village'] ?? null,
                    'district' => $data['district'] ?? null,
                    'city' => $data['city'] ?? null,
                    'service_type' => $service,
                    'status' => $status,
                    'due_day' => max(1, min(28, (int) ($data['due_day'] ?? 20))),
                    'grace_days' => is_numeric($data['grace_days'] ?? null) ? (int) $data['grace_days'] : null,
                    'activated_at' => $data['activated_at'] ?: null,
                    'package_id' => $package?->id,
                    'router_id' => $router?->id,
                    'pppoe_username' => $username,
                    'pppoe_profile_normal' => $data['pppoe_profile_normal'] ?? $package?->normal_profile,
                    'pppoe_ip' => $data['pppoe_ip'] ?? null,
                    'pppoe_mac' => $data['pppoe_mac'] ?? null,
                    'is_auto_isolate' => true,
                ];
                foreach (['pppoe_password' => 'pppoe_password'] as $source => $target) {
                    if (!empty($data[$source])) $attributes[$target] = $data[$source];
                }
                Customer::updateOrCreate(['customer_code' => $code], $attributes);
                $count++;
            }
        });

        Audit::log('customers.imported', Customer::class, null, ['count' => $count, 'format' => 'xlsx/csv/mikhmon']);
        $message = "{$count} pelanggan berhasil diimpor.";
        if ($duplicateWhatsapp > 0 || $invalidWhatsapp > 0) {
            $message .= " {$duplicateWhatsapp} baris duplikat dan {$invalidWhatsapp} nomor tidak valid dilewati.";
        }

        return back()->with('success', $message);
    }

    public function export()
    {
        $headers = ['customer_code', 'name', 'whatsapp_number', 'phone', 'email', 'area', 'address', 'rt', 'rw', 'village', 'district', 'city', 'service_type', 'status', 'due_day', 'grace_days', 'package', 'router', 'pppoe_username', 'pppoe_profile_normal', 'pppoe_profile_isolir', 'pppoe_ip', 'pppoe_mac', 'activated_at', 'registered_at', 'modem_device', 'source_port', 'olt_name', 'pon_port', 'onu_id', 'onu_sn', 'latitude', 'longitude', 'notes'];
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $index => $header) $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);

        $line = 2;
        foreach (Customer::with(['package', 'router'])->cursor() as $customer) {
            $values = [$customer->customer_code, $customer->name, $customer->whatsapp_number, $customer->phone, $customer->email, $customer->area, $customer->address, $customer->rt, $customer->rw, $customer->village, $customer->district, $customer->city, $customer->service_type, $customer->status, $customer->due_day, $customer->grace_days, $customer->package?->name, $customer->router?->name, $customer->pppoe_username, $customer->pppoe_profile_normal, $customer->pppoe_profile_isolir, $customer->pppoe_ip, $customer->pppoe_mac, $customer->activated_at?->format('Y-m-d'), $customer->registered_at?->format('Y-m-d'), $customer->modem_device, $customer->source_port, $customer->olt_name, $customer->pon_port, $customer->onu_id, $customer->onu_sn, $customer->latitude, $customer->longitude, $customer->notes];
            foreach ($values as $index => $value) $sheet->setCellValueByColumnAndRow($index + 1, $line, $value);
            $line++;
        }

        $file = tempnam(sys_get_temp_dir(), 'customers_').'.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        return response()->download($file, 'customers.xlsx')->deleteFileAfterSend(true);
    }

    private function canonicalHeader(string $header): ?string
    {
        $key = Str::lower(Str::ascii(trim($header)));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            if (in_array($key, $aliases, true)) return $canonical;
        }
        return null;
    }
}

