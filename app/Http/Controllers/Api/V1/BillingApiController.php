<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Customer, Invoice, Router};
use Illuminate\Http\Request;

class BillingApiController extends Controller
{
    public function customers(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,isolated,suspended,terminated,trial', 'per_page' => 'nullable|integer|min:1|max:100']);
        $customers = Customer::with(['package:id,name,price', 'router:id,name,host,last_seen_at'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('customer_code', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $customers->getCollection()->map(fn (Customer $customer) => $this->customerData($customer)), 'meta' => ['current_page' => $customers->currentPage(), 'last_page' => $customers->lastPage(), 'per_page' => $customers->perPage(), 'total' => $customers->total()]]);
    }

    public function customer(Customer $customer)
    {
        $customer->load(['package:id,name,price,normal_speed', 'router:id,name,host,last_seen_at', 'onu:id,customer_id,status,rx_power,tx_power,last_seen_at']);
        return response()->json(['data' => $this->customerData($customer)]);
    }

    public function invoices(Request $request)
    {
        $filters = $request->validate(['period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'status' => 'nullable|in:draft,unpaid,paid,cancelled,expired', 'customer_code' => 'nullable|string|max:50', 'per_page' => 'nullable|integer|min:1|max:100']);
        $invoices = Invoice::with('customer:id,customer_code,name')->when($filters['period'] ?? null, fn ($query, $period) => $query->where('period', $period))->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->when($filters['customer_code'] ?? null, fn ($query, $code) => $query->whereHas('customer', fn ($customer) => $customer->where('customer_code', $code)))->latest('id')->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $invoices->getCollection()->map(fn (Invoice $invoice) => $this->invoiceData($invoice)), 'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()]]);
    }

    public function invoice(Invoice $invoice)
    {
        $invoice->load(['customer:id,customer_code,name,phone', 'items', 'payments:id,invoice_id,provider,reference,channel,amount,status,paid_at']);
        return response()->json(['data' => $this->invoiceData($invoice) + ['items' => $invoice->items->map(fn ($item) => ['description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'line_total' => $item->line_total]), 'payments' => $invoice->payments->map(fn ($payment) => ['provider' => $payment->provider, 'reference' => $payment->reference, 'channel' => $payment->channel, 'amount' => $payment->amount, 'status' => $payment->status, 'paid_at' => $payment->paid_at])]]);
    }

    public function routers()
    {
        return response()->json(['data' => Router::where('enabled', true)->orderBy('name')->get(['id', 'name', 'host', 'port', 'last_seen_at'])->map(fn (Router $router) => ['id' => $router->id, 'name' => $router->name, 'host' => $router->host, 'port' => $router->port, 'status' => $router->last_seen_at?->gt(now()->subMinutes(10)) ? 'online' : 'unknown', 'last_seen_at' => $router->last_seen_at])]);
    }

    private function customerData(Customer $customer): array
    {
        return ['id' => $customer->id, 'customer_code' => $customer->customer_code, 'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email, 'address' => $customer->address, 'rt' => $customer->rt, 'rw' => $customer->rw, 'village' => $customer->village, 'district' => $customer->district, 'city' => $customer->city, 'service_type' => $customer->service_type, 'status' => $customer->status, 'due_day' => $customer->due_day, 'activated_at' => $customer->activated_at?->toDateString(), 'package' => $customer->package ? ['id' => $customer->package->id, 'name' => $customer->package->name, 'price' => $customer->package->price, 'speed' => $customer->package->normal_speed] : null, 'router' => $customer->router ? ['id' => $customer->router->id, 'name' => $customer->router->name, 'last_seen_at' => $customer->router->last_seen_at] : null, 'onu' => $customer->onu ? ['status' => $customer->onu->status, 'rx_power' => $customer->onu->rx_power, 'tx_power' => $customer->onu->tx_power, 'last_seen_at' => $customer->onu->last_seen_at] : null];
    }

    private function invoiceData(Invoice $invoice): array
    {
        return ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'period' => $invoice->period, 'issued_at' => $invoice->issued_at?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(), 'subtotal' => $invoice->subtotal, 'discount' => $invoice->discount, 'penalty' => $invoice->penalty, 'tax_rate' => $invoice->tax_rate, 'tax_amount' => $invoice->tax_amount, 'total' => $invoice->total, 'status' => $invoice->status, 'paid_at' => $invoice->paid_at, 'customer' => $invoice->customer ? ['customer_code' => $invoice->customer->customer_code, 'name' => $invoice->customer->name] : null];
    }
}
