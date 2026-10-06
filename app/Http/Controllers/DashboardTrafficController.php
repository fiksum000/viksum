<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterOsService;
use Illuminate\Http\JsonResponse;

class DashboardTrafficController extends Controller
{
    public function __invoke(RouterOsService $routerOs): JsonResponse
    {
        $routerName = trim((string) config('billing.traffic_monitor_router'));
        if ($routerName === '') {
            return $this->emptySample();
        }

        $router = Router::where('name', $routerName)->where('enabled', true)->first();
        if (!$router) {
            return $this->emptySample();
        }

        try {
            $sample = [
                'router_id' => $router->id,
                'router' => $router->name,
                ...$routerOs->interfaceTraffic($router),
                'ok' => true,
            ];
        } catch (\Throwable $exception) {
            report($exception);
            $sample = [
                'router_id' => $router->id,
                'router' => $router->name,
                'interface' => $router->traffic_interface,
                'rx_bps' => null,
                'tx_bps' => null,
                'ok' => false,
            ];
        }

        return response()->json([
            'sampled_at' => now(config('billing.timezone'))->toIso8601String(),
            'routers' => [$sample],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function emptySample(): JsonResponse
    {
        return response()->json([
            'sampled_at' => now(config('billing.timezone'))->toIso8601String(),
            'routers' => [],
        ])->header('Cache-Control', 'no-store, private');
    }
}

