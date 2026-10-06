<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Support\Audit;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicRegistrationController extends Controller
{
    public function create()
    {
        return view('public.registration', [
            'packages' => Package::orderBy('price')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (filled($request->input('website_url'))) {
            abort(404);
        }

        $rawWhatsapp = $request->input('whatsapp_number');
        $whatsapp = WhatsappNumber::normalize(is_string($rawWhatsapp) ? $rawWhatsapp : null);
        $request->merge([
            'whatsapp_number' => filled($rawWhatsapp) && blank($whatsapp) ? 'invalid' : $whatsapp,
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'whatsapp_number' => [
                'required', 'string', 'max:30', 'regex:/^628[0-9]{7,12}$/',
                Rule::unique('customers', 'whatsapp_number'),
            ],
            'area' => 'required|string|max:120',
            'address' => 'required|string|max:2000',
            'village' => 'nullable|string|max:120',
            'district' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'package_id' => 'required|exists:packages,id',
            'terms' => 'accepted',
        ], [
            'whatsapp_number.unique' => 'Nomor WhatsApp ini sudah terdaftar di Billing. Hubungi admin bila ingin mengajukan layanan tambahan.',
            'whatsapp_number.regex' => 'Masukkan nomor WhatsApp Indonesia yang benar, misalnya 0812… atau 62812… .',
            'terms.accepted' => 'Setujui penggunaan data untuk memproses pemasangan internet.',
        ]);

        $package = Package::findOrFail($data['package_id']);
        $customerCode = $this->newCustomerCode();
        $portalPassword = Str::random(16);

        $customer = Customer::create([
            'customer_code' => $customerCode,
            'name' => $data['name'],
            'whatsapp_number' => $data['whatsapp_number'],
            'area' => $data['area'],
            'address' => $data['address'],
            'village' => $data['village'] ?? null,
            'district' => $data['district'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'package_id' => $package->id,
            'pppoe_profile_normal' => $package->normal_profile,
            'service_type' => 'pppoe',
            'status' => 'trial',
            'due_day' => 20,
            'grace_days' => 0,
            'registered_at' => now(config('billing.timezone'))->toDateString(),
            'activated_at' => null,
            'is_auto_isolate' => true,
            'portal_password' => $portalPassword,
            'notes' => 'Pendaftaran online; menunggu verifikasi area dan pemasangan.',
        ]);

        Audit::log('customer.registered_online', Customer::class, $customer->id, ['customer_code' => $customerCode]);

        return redirect()->route('register.complete')->with([
            'online_registration_customer_id' => $customer->id,
            'online_registration_portal_password' => $portalPassword,
        ]);
    }

    public function complete(Request $request)
    {
        $customerId = $request->session()->pull('online_registration_customer_id');
        $portalPassword = $request->session()->pull('online_registration_portal_password');
        abort_unless($customerId && $portalPassword, 404);

        $customer = Customer::with('package')->findOrFail($customerId);

        return view('public.registration-complete', compact('customer', 'portalPassword'));
    }

    private function newCustomerCode(): string
    {
        do {
            $code = 'CUST-'.Str::upper(Str::random(12));
        } while (Customer::where('customer_code', $code)->exists());

        return $code;
    }
}

