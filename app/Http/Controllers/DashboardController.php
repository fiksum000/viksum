<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HotspotVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Router;
use App\Services\FupService;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(FupService $fupService)
    {
        $now = now(config('billing.timezone'));
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $paid = Payment::where('status', 'paid');
        $revenueByMonth = (clone $paid)
            ->whereBetween('paid_at', [$now->copy()->subMonths(5)->startOfMonth(), $monthEnd])
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as period, SUM(amount) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $months = collect(range(5, 0))->map(function (int $offset) use ($now, $revenueByMonth): array {
            $period = $now->copy()->subMonths($offset)->format('Y-m');

            return [
                'label' => Carbon::createFromFormat('Y-m', $period)->translatedFormat('M'),
                'period' => $period,
                'total' => (int) ($revenueByMonth[$period] ?? 0),
            ];
        });

        $pppoe = Customer::where('service_type', 'pppoe');
        $hotspot = Customer::where('service_type', 'hotspot');
        $routers = Router::orderBy('name')->get(['id', 'name', 'enabled', 'last_seen_at', 'meta']);

        return view('dashboard.index', [
            'now' => $now,
            'fupCycle' => $fupService->currentPeriod(),
            'routers' => $routers,
            'stats' => [
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
                'pppoe_total' => (clone $pppoe)->count(),
                'pppoe_active' => (clone $pppoe)->where('status', 'active')->count(),
                'pppoe_isolated' => (clone $pppoe)->where('status', 'isolated')->count(),
                'hotspot_total' => (clone $hotspot)->count(),
                'hotspot_active' => (clone $hotspot)->where('status', 'active')->count(),
                'hotspot_vouchers' => HotspotVoucher::where('status', 'active')->count(),
            ],
            'months' => $months,
        ]);
    }
}

