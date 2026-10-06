<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Router;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $now = now(config('billing.timezone'));
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $paid = Payment::where('status', 'paid');
        $revenueByMonth = (clone $paid)->whereBetween('paid_at', [$now->copy()->subMonths(5)->startOfMonth(), $monthEnd])->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as period, SUM(amount) as total")->groupBy('period')->pluck('total', 'period');
        $months = collect(range(5, 0))->map(function (int $offset) use ($now, $revenueByMonth) {
            $period = $now->copy()->subMonths($offset)->format('Y-m');
            return ['label' => Carbon::createFromFormat('Y-m', $period)->translatedFormat('M'), 'period' => $period, 'total' => (int) ($revenueByMonth[$period] ?? 0)];
        });

        return view('dashboard.index', ['stats' => [
            'customers' => Customer::count(),
            'active' => Customer::where('status', 'active')->count(),
            'isolated' => Customer::where('status', 'isolated')->count(),
            'new_month' => Customer::whereBetween('created_at', [$monthStart, $monthEnd])->count(),
            'unpaid' => Invoice::where('status', 'unpaid')->count(),
            'unpaid_amount' => Invoice::where('status', 'unpaid')->sum('total'),
            'invoice_month' => Invoice::where('period', $now->format('Y-m'))->count(),
            'paid_month' => (clone $paid)->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount'),
            'tripay_today' => (clone $paid)->where('provider', 'tripay')->whereDate('paid_at', $now->toDateString())->sum('amount'),
            'routers' => Router::count(),
            'routers_online' => Router::whereNotNull('last_seen_at')->where('last_seen_at', '>', $now->copy()->subMinutes(10))->count(),
            'routers_offline' => Router::whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $now->copy()->subMinutes(10))->count(),
        ], 'months' => $months]);
    }
}
