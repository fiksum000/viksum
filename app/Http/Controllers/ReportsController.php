<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $filters['from'] ?? now(config('billing.timezone'))->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now(config('billing.timezone'))->toDateString();
        $payments = Payment::with(['invoice.customer'])->where('status', 'paid')->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])->latest('paid_at');
        $daily = (clone $payments)->selectRaw('DATE(paid_at) as period, SUM(amount) as total, COUNT(*) as count')->groupByRaw('DATE(paid_at)')->orderBy('period')->get();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'daily' => $daily,
            'payments' => (clone $payments)->paginate(50)->withQueryString(),
            'revenue' => (clone $payments)->sum('amount'),
            'paymentCount' => (clone $payments)->count(),
            'activeCustomers' => Customer::where('status', 'active')->count(),
            'isolatedCustomers' => Customer::where('status', 'isolated')->count(),
            'terminatedCustomers' => Customer::where('status', 'terminated')->count(),
            'unpaidAmount' => Invoice::where('status', 'unpaid')->sum('total'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $filters['from'] ?? now(config('billing.timezone'))->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now(config('billing.timezone'))->toDateString();
        $payments = Payment::with(['invoice.customer'])->where('status', 'paid')->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('paid_at')->cursor();

        return response()->streamDownload(function () use ($payments): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['tanggal', 'invoice', 'pelanggan', 'provider', 'metode', 'nominal', 'status']);
            foreach ($payments as $payment) {
                fputcsv($output, [$payment->paid_at?->format('Y-m-d H:i:s'), $payment->invoice?->invoice_number, $payment->invoice?->customer?->name, $payment->provider, $payment->channel, $payment->amount, $payment->status]);
            }
            fclose($output);
        }, "laporan-pembayaran-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
