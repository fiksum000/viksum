<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterOsService;
use Illuminate\Http\JsonResponse;

class DashboardTrafficController extends Controller
{
    public function __invoke(RouterOsService $routerOs): JsonResponse
    {
        $samples = Router::where('enabled', true)->orderBy('name')->get()->map(function (Router $router) use ($routerOs): array {
            try {
                return ['router_id' => $router->id, 'router' => $router->name, ...$routerOs->interfaceTraffic($router), 'ok' => true];
            } catch (\Throwable $exception) {
                report($exception);
                return ['router_id' => $router->id, 'router' => $router->name, 'interface' => $router->traffic_interface, 'rx_bps' => null, 'tx_bps' => null, 'ok' => false];
            }
        });

        return response()->json(['sampled_at' => now(config('billing.timezone'))->toIso8601String(), 'routers' => $samples]);
    }
}
