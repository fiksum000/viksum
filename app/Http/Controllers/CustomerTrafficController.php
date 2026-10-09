<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerQueryService;
use App\Services\RouterOsService;
use App\Support\PppTrafficRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CustomerTrafficController extends Controller
{
    public function __invoke(Request $request, CustomerQueryService $customerQuery, RouterOsService $routerOs): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,isolated,suspended,terminated,trial'],
            'service_type' => ['nullable', 'in:pppoe,hotspot'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        unset($filters['page']);
        $customers = $customerQuery->filtered($filters)->paginate(25, ['*'], 'page', $page)->getCollection();
        $traffic = [];

        foreach ($customers->filter(fn (Customer $customer) => in_array($customer->service_type, ['pppoe', 'hotspot'], true)
            && filled($customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username)
            && $customer->router_id)->groupBy('router_id') as $routerId => $routerCustomers) {
            $router = $routerCustomers->first()->router;
            if (!$router || !$router->enabled) {
                foreach ($routerCustomers as $customer) $traffic[$customer->id] = ['state' => 'unknown'];
                continue;
            }

            try {
                $serviceTypes = $routerCustomers->pluck('service_type')->unique();
                $sessionsByService = [];
                if ($serviceTypes->contains('pppoe')) {
                    $pppoeUsernames = $routerCustomers
                        ->where('service_type', 'pppoe')
                        ->pluck('pppoe_username')
                        ->filter(fn ($username) => filled($username))
                        ->map(fn ($username) => (string) $username)
                        ->all();
                    $sessionsByService['pppoe'] = $routerOs->activePppTrafficMap($router, $pppoeUsernames);
                }
                if ($serviceTypes->contains('hotspot')) $sessionsByService['hotspot'] = $routerOs->activeHotspotTrafficMap($router);
                foreach ($routerCustomers as $customer) {
                    $username = (string) ($customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username);
                    $sessions = $sessionsByService[$customer->service_type] ?? [];
                    if (!isset($sessions[$username])) {
                        $traffic[$customer->id] = ['state' => 'offline'];
                        continue;
                    }

                    $session = $sessions[$username];
                    if (array_key_exists('download_bps', $session) || array_key_exists('upload_bps', $session)) {
                        $download = $session['download_bps'] ?? null;
                        $upload = $session['upload_bps'] ?? null;
                        $traffic[$customer->id] = is_numeric($download) && is_numeric($upload)
                            ? ['state' => 'online', 'download_bps' => max(0, (int) $download), 'upload_bps' => max(0, (int) $upload)]
                            : ['state' => 'unavailable'];
                        continue;
                    }
                    if (!is_numeric($session['download_bytes'] ?? null) || !is_numeric($session['upload_bytes'] ?? null)) {
                        $traffic[$customer->id] = ['state' => 'unavailable'];
                        continue;
                    }

                    $sessionIdentity = $session['session_id'] ?: implode('|', [$username, $session['caller_id'], $session['address']]);
                    $cacheKey = 'customer-traffic:'.$router->id.':'.hash('sha256', $customer->service_type.'|'.$sessionIdentity);
                    $now = now()->getTimestamp();
                    $current = [
                        'sampled_at' => $now,
                        'download_bytes' => (int) $session['download_bytes'],
                        'upload_bytes' => (int) $session['upload_bytes'],
                    ];
                    $previous = Cache::get($cacheKey);
                    Cache::put($cacheKey, $current, now()->addMinutes(3));
                    $rate = is_array($previous) ? PppTrafficRate::fromSamples($previous, $current) : null;
                    $traffic[$customer->id] = $rate
                        ? ['state' => 'online', ...$rate]
                        : ['state' => 'sampling'];
                }
            } catch (\Throwable) {
                Log::warning('Customer PPP traffic read failed', [
                    'router_id' => $router->id,
                ]);
                foreach ($routerCustomers as $customer) $traffic[$customer->id] = ['state' => 'unknown'];
            }
        }

        foreach ($customers as $customer) {
            if (!array_key_exists($customer->id, $traffic)) $traffic[$customer->id] = ['state' => 'not_configured'];
        }

        return response()->json([
            'sampled_at' => now(config('billing.timezone'))->toIso8601String(),
            'refresh_seconds' => 30,
            'customers' => $traffic,
        ])->header('Cache-Control', 'no-store, private');
    }
}

