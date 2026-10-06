<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\FupService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerPortalController extends Controller
{
    public function loginForm()
    {
        if (session('customer_id')) {
            return redirect()->route('portal.home');
        }

        return view('portal.login');
    }

    public function login(Request $request, RateLimiter $limiter)
    {
        $data = $request->validate(['customer_code' => 'required|string|max:50', 'password' => 'required|string|max:1024']);
        $key = 'customer-portal:'.Str::lower($data['customer_code']).'|'.$request->ip();
        if ($limiter->tooManyAttempts($key, 5)) {
            return back()->withInput($request->only('customer_code'))->with('error', 'Terlalu banyak percobaan. Coba lagi dalam satu menit.');
        }

        $customer = Customer::where('customer_code', $data['customer_code'])->first();
        if (!$customer || !$customer->portal_password || !Hash::check($data['password'], $customer->portal_password)) {
            $limiter->hit($key, 60);
            Audit::log('portal.login_failed', Customer::class, $customer?->id, ['customer_code' => $data['customer_code']]);
            return back()->withInput($request->only('customer_code'))->with('error', 'ID pelanggan atau password salah.');
        }

        $limiter->clear($key);
        $request->session()->regenerate();
        $request->session()->put('customer_id', $customer->id);
        Audit::log('portal.login_succeeded', Customer::class, $customer->id);
        return redirect()->intended(route('portal.home'));
    }

    public function home(Request $request)
    {
        $customer = $request->attributes->get('billing_customer')->load(['package', 'router', 'onu.olt']);
        $invoices = $customer->invoices()->latest('period')->limit(12)->get();
        $fup = $customer->fupState()->where('period', app(FupService::class)->currentPeriod())->first();

        return view('portal.home', compact('customer', 'invoices', 'fup'));
    }

    public function isolated(Request $request)
    {
        $customer = $request->attributes->get('billing_customer');
        abort_unless($customer->status === 'isolated', 404);
        $invoice = $customer->invoices()->where('status', 'unpaid')->latest('due_date')->first();

        return view('portal.isolated', compact('customer', 'invoice'));
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $customer = $request->attributes->get('billing_customer');
        abort_unless($invoice->customer_id === $customer->id, 404);
        $invoice->load('customer.package', 'payments');

        return view('portal.invoice', compact('invoice'));
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->customer_id === $request->attributes->get('billing_customer')->id, 404);
        $invoice->load('customer.package');

        return Pdf::loadView('invoices.pdf', compact('invoice'))->setPaper('a4')->stream($invoice->invoice_number.'.pdf');
    }

    public function logout(Request $request)
    {
        Audit::log('portal.logout', Customer::class, $request->session()->get('customer_id'));
        $request->session()->forget('customer_id');
        $request->session()->regenerateToken();
        return redirect()->route('portal.login');
    }
}
