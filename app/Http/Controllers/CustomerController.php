<?php
namespace App\Http\Controllers;

use App\Models\{Customer, FupState, Onu, Package, Router};
use App\Services\FupService;
use App\Services\RouterOsService;
use App\Services\CustomerQueryService;
use App\Support\Audit;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class CustomerController extends Controller
{
    public function index(Request $r, CustomerQueryService $customerQuery, RouterOsService $routerOs)
    {
        $filters = $r->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,isolated,suspended,terminated,trial'],
            'service_type' => ['nullable', 'in:pppoe,hotspot'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
        ]);
        $q = $customerQuery->filtered($filters)->paginate(25)->withQueryString();
        $period = now(config('billing.timezone'))->format('Y-m');
        $customers = $q->getCollection();
        $customers->load(['invoices' => fn ($query) => $query->where('period', $period)]);

        // Billing state and live router session status are distinct.
        $sessionMaps = [];
        foreach ($customers->filter(fn (Customer $customer) => $customer->router_id)->groupBy('router_id') as $routerId => $routerCustomers) {
            $router = $routerCustomers->first()->router;
            if (!$router || !$router->enabled) {
                $sessionMaps[$routerId] = null;
                continue;
            }

            try {
                $pppSessions = array_filter(
                    $routerOs->activePppMap($router),
                    fn (array $session) => ($session['service'] ?? 'pppoe') === 'pppoe',
                );
                $hotspotSessions = array_map(
                    fn (array $session) => (string) ($session['user'] ?? ''),
                    $routerOs->listHotspotActive($router),
                );
                $sessionMaps[$routerId] = [
                    'pppoe' => array_fill_keys(array_keys($pppSessions), true),
                    'hotspot' => array_fill_keys($hotspotSessions, true),
                ];
            } catch (\Throwable $exception) {
                Log::warning('Customer live connection status read failed', [
                    'router_id' => $router->id,
                    'error' => $exception->getMessage(),
                ]);
                $sessionMaps[$routerId] = null;
            }
        }

        foreach ($customers as $customer) {
            $username = $customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username;
            $connectionStatus = 'not_configured';
            if (filled($username) && $customer->router_id) {
                $sessions = $sessionMaps[$customer->router_id] ?? null;
                $connectionStatus = $sessions === null
                    ? 'unknown'
                    : (isset($sessions[$customer->service_type][$username]) ? 'online' : 'offline');
            }
            $customer->setAttribute('live_connection_status', $connectionStatus);
        }

        return view('customers.index', [
            'customers' => $q,
            'packages' => Package::orderBy('name')->get(['id', 'name']), 'billingPeriod' => $period,
        ]);
    }

    private function formData(Customer $customer): array
    {
        return [
            'customer' => $customer,
            'packages' => Package::with('router')->orderBy('name')->get(),
            'routers' => Router::orderBy('name')->get(),
            'onus' => Onu::with('olt')->where(function ($q) use ($customer) {
                $q->whereNull('customer_id');
                if ($customer->exists) {
                    $q->orWhere('customer_id', $customer->id);
                }
            })->orderBy('olt_id')->orderBy('pon_port')->get(),
            'fupState' => $customer->exists
                ? FupState::where('customer_id', $customer->id)
                    ->where('period', app(FupService::class)->currentPeriod())->first()
                : null,
        ];
    }

    private function newCustomerCode(): string
    {
        do {
            $code = 'CUST-'.Str::upper(Str::random(12));
        } while (Customer::where('customer_code', $code)->exists());

        return $code;
    }

    public function create()
    {
        $customer = new Customer(['customer_code' => $this->newCustomerCode()]);
        return view('customers.form', $this->formData($customer) + [
            'portalPassword' => old('portal_password', Str::random(16)),
        ]);
    }

    public function store(Request $r, RouterOsService $routerOs)
    {
        $data = $this->validated($r);
        $portalPassword = $data['portal_password'];
        $onuId = $data['onu_record_id'] ?? null;
        unset($data['onu_record_id']);

        $customer = DB::transaction(function () use ($data, $onuId) {
            $customer = Customer::create($data);
            $customer->pppoe_profile_normal = $customer->package?->normal_profile;
            $customer->save();
            $this->syncOnu($customer, $onuId);
            return $customer;
        });

        Audit::log('customer.created', Customer::class, $customer->id, ['code' => $customer->customer_code]);

        try {
            $this->syncPppSecret($customer, $routerOs);
            $feedbackKey = 'success';
            $message = $customer->status === 'trial'
                ? 'Pelanggan disimpan sebagai uji coba; belum ada secret yang dikirim ke MikroTik.'
                : ($customer->service_type === 'pppoe'
                ? 'Pelanggan ditambahkan dan secret PPP berhasil disimpan di MikroTik.'
                : 'Pelanggan ditambahkan.');
        } catch (\Throwable $e) {
            Log::warning('Customer PPP secret sync failed', [
                'customer_id' => $customer->id,
                'router_id' => $customer->router_id,
                'error' => $e->getMessage(),
            ]);
            $feedbackKey = 'warning';
            $message = 'Data pelanggan tersimpan, tetapi sinkronisasi secret PPP gagal. Periksa koneksi router dan log aplikasi.';
        }

        return redirect()->route('customers.edit', $customer)
            ->with($feedbackKey, $message)
            ->with('portal_password_created', $portalPassword);
    }

    public function edit(Customer $customer)
    {
        return view('customers.form', $this->formData($customer));
    }

    public function update(Request $r, Customer $customer, RouterOsService $routerOs)
    {
        $data = $this->validated($r, $customer);
        $onuId = $data['onu_record_id'] ?? null;
        unset($data['onu_record_id']);

        foreach (['pppoe_password', 'hotspot_password', 'portal_password', 'ktp_number', 'npwp'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $previousRouter = $customer->router;
        $previousServiceType = $customer->service_type;
        $previousUsername = $customer->pppoe_username;

        DB::transaction(function () use ($customer, $data, $onuId) {
            $customer->update($data);
            if ($customer->service_type === 'pppoe') {
                $customer->update(['pppoe_profile_normal' => $customer->package?->normal_profile]);
            }
            $this->syncOnu($customer, $onuId);
        });

        $customer->refresh();
        Audit::log('customer.updated', Customer::class, $customer->id);

        try {
            $previousUsernameOnSelectedRouter = $previousRouter?->id === $customer->router_id
                ? $previousUsername
                : null;
            $this->syncPppSecret($customer, $routerOs, $previousUsernameOnSelectedRouter);
            if ($customer->status !== 'trial' && $previousRouter && $previousServiceType === 'pppoe'
                && ($customer->service_type !== 'pppoe' || $previousRouter->id !== $customer->router_id || $previousUsername !== $customer->pppoe_username)
                && $previousUsername) {
                $routerOs->deletePppSecret($previousRouter, $previousUsername);
            }
            $feedbackKey = 'success';
            $message = $customer->status === 'trial'
                ? 'Data uji coba diperbarui; belum ada perubahan ke MikroTik.'
                : ($customer->service_type === 'pppoe'
                ? 'Pelanggan diperbarui dan secret PPP berhasil disinkronkan ke MikroTik.'
                : 'Data pelanggan diperbarui.');
        } catch (\Throwable $e) {
            Log::warning('Customer PPP secret sync failed', [
                'customer_id' => $customer->id,
                'router_id' => $customer->router_id,
                'error' => $e->getMessage(),
            ]);
            $feedbackKey = 'warning';
            $message = 'Data pelanggan tersimpan, tetapi sinkronisasi secret PPP gagal. Periksa koneksi router dan log aplikasi.';
        }

        return redirect()->route('customers.edit', $customer)->with($feedbackKey, $message);
    }

    private function syncPppSecret(Customer $customer, RouterOsService $routerOs, ?string $previousUsername = null): void
    {
        if ($customer->service_type !== 'pppoe' || $customer->status === 'trial') {
            return;
        }
        if (!$customer->router_id || !$customer->router) {
            throw new RuntimeException('Pilih router MikroTik untuk layanan PPPoE.');
        }
        if (blank($customer->pppoe_username) || blank($customer->pppoe_password)) {
            throw new RuntimeException('Username dan password PPP harus tersedia.');
        }

        $routerOs->createOrUpdatePppSecret($customer->router, [
            'name' => $customer->pppoe_username,
            'password' => $customer->pppoe_password,
            'profile' => $customer->package?->normal_profile ?: 'default',
        ], $previousUsername);
    }

    private function syncOnu(Customer $customer, ?int $onuId): void
    {
        $onuId = $onuId ? (int) $onuId : null;
        $previous = $customer->onu;
        if ($previous && $previous->id !== $onuId) {
            $previous->update(['customer_id' => null]);
        }
        if (!$onuId) {
            $customer->update(['olt_id' => null]);
            return;
        }

        $onu = Onu::with('olt')->lockForUpdate()->findOrFail($onuId);
        if ($onu->customer_id && $onu->customer_id !== $customer->id) {
            throw new RuntimeException('ONU sudah terhubung ke pelanggan lain.');
        }
        $onu->update(['customer_id' => $customer->id]);
        $customer->update([
            'olt_id' => $onu->olt_id,
            'olt_name' => $onu->olt?->name,
            'pon_port' => $onu->pon_port,
            'onu_id' => $onu->onu_id,
            'onu_sn' => $onu->serial_number,
        ]);
    }

    private function validated(Request $r, ?Customer $customer = null): array
    {
        $creating = !$customer || !$customer->exists;
        $service = $r->input('service_type');
        $status = $r->input('status');
        $needsPppSetup = $service === 'pppoe' && $status !== 'trial';
        $needsPppPassword = $needsPppSetup && ($creating || ($customer?->status === 'trial' && blank($customer?->pppoe_password)));
        $rawWhatsapp = $r->input('whatsapp_number');
        $normalizedWhatsapp = WhatsappNumber::normalize(is_string($rawWhatsapp) ? $rawWhatsapp : null);
        $r->merge(['whatsapp_number' => filled($rawWhatsapp) && blank($normalizedWhatsapp) ? 'invalid' : $normalizedWhatsapp]);

        $data = $r->validate([
            'customer_code' => ['nullable', 'max:50', Rule::unique('customers', 'customer_code')->ignore($customer?->id)],
            'name' => 'required|max:120',
            'whatsapp_number' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^628[0-9]{7,12}$/',
                Rule::unique('customers', 'whatsapp_number')->ignore($customer?->id),
            ],
            'area' => [$creating ? 'required' : 'nullable', 'max:120'],
            'address' => 'nullable',
            'rt' => 'nullable|max:10',
            'rw' => 'nullable|max:10',
            'village' => 'nullable|max:120',
            'district' => 'nullable|max:120',
            'city' => 'nullable|max:120',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'ktp_number' => 'nullable|max:50',
            'registered_at' => 'nullable|date',
            'modem_device' => 'nullable|max:120',
            'source_port' => 'nullable|max:120',
            'notes' => 'nullable|max:5000',
            'service_type' => 'required|in:pppoe,hotspot',
            'status' => 'required|in:active,isolated,suspended,terminated,trial',
            'due_day' => 'required|integer|min:1|max:28',
            'grace_days' => 'nullable|integer|min:0|max:31',
            'activated_at' => 'nullable|date',
            'router_id' => [$needsPppSetup ? 'required' : 'nullable', 'exists:routers,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'pppoe_username' => [$needsPppSetup ? 'required' : 'nullable', 'max:120', Rule::unique('customers', 'pppoe_username')->ignore($customer?->id)],
            'pppoe_password' => [$needsPppPassword ? 'required' : 'nullable', 'string', 'max:255'],
            'pppoe_ip' => 'nullable|ip',
            'pppoe_mac' => 'nullable|mac_address',
            'pppoe_profile_normal' => 'nullable|max:120',
            'pppoe_profile_isolir' => 'nullable|max:120',
            'hotspot_username' => [$service === 'hotspot' ? 'required' : 'nullable', 'max:120', Rule::unique('customers', 'hotspot_username')->ignore($customer?->id)],
            'hotspot_password' => [$service === 'hotspot' && $creating ? 'required' : 'nullable', 'string', 'max:255'],
            'olt_name' => 'nullable|max:120',
            'pon_port' => 'nullable|max:50',
            'onu_id' => 'nullable|max:50',
            'onu_sn' => 'nullable|max:100',
            'onu_record_id' => ['nullable', 'exists:onus,id', Rule::exists('onus', 'id')->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', $customer?->id))],
            'fup_mode' => 'required|in:inherit,on,off',
            'fup_limit_gb' => 'nullable|numeric|min:0|max:100000',
            'fup_speed_after' => 'nullable|max:50',
            'is_auto_isolate' => 'nullable|boolean',
            'portal_password' => 'nullable|string|min:8|max:255',
        ]);

        $selectedPackage = Package::findOrFail($data['package_id']);
        if ($service === 'pppoe' && $status !== 'trial') {
            if (!$selectedPackage->router_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'package_id' => 'Tautkan paket ke router dan profil PPP terlebih dahulu di menu PPPoE → Paket & Profil.',
                ]);
            }
            if ((int) $selectedPackage->router_id !== (int) ($data['router_id'] ?? 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'package_id' => 'Router pelanggan harus sama dengan router yang dipetakan pada paket ini.',
                ]);
            }
        }

        $data['customer_code'] = $creating ? $this->newCustomerCode() : $customer->customer_code;
        if ($service === 'pppoe') {
            // Billing package is the single source of truth for the PPP profile.
            $data['pppoe_profile_normal'] = $selectedPackage->normal_profile;
            $data['fup_speed_after'] = $selectedPackage->fup_speed_after;
        }
        $data['portal_password'] = filled($data['portal_password'] ?? null)
            ? $data['portal_password']
            : Str::random(16);

        $fupMode = $data['fup_mode'];
        unset($data['fup_mode']);
        $data['fup_override'] = $fupMode === 'inherit' ? null : $fupMode === 'on';
        $data['fup_enabled'] = $fupMode === 'on';
        $data['fup_limit_bytes'] = blank($data['fup_limit_gb'] ?? null)
            ? null : (int) round((float) $data['fup_limit_gb'] * 1024 * 1024 * 1024);
        unset($data['fup_limit_gb']);
        $data['is_auto_isolate'] = (bool) ($data['is_auto_isolate'] ?? false);

        return $data;
    }
}
