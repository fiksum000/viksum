<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterOsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouterReadController extends Controller
{
    private const PAGES = [
        'pppoe.secrets' => ['title' => 'PPP Secrets', 'kind' => 'ppp-secrets'],
        'pppoe.active' => ['title' => 'PPP Active', 'kind' => 'ppp-active'],
        'pppoe.offline' => ['title' => 'PPP Offline', 'kind' => 'ppp-offline'],
        'pppoe.profiles' => ['title' => 'PPP Profiles', 'kind' => 'ppp-profiles'],
        'hotspot.users' => ['title' => 'Hotspot Users', 'kind' => 'hotspot-users'],
        'hotspot.active-users' => ['title' => 'Hotspot Active', 'kind' => 'hotspot-active'],
        'hotspot.profiles' => ['title' => 'Hotspot Profiles', 'kind' => 'hotspot-profiles'],
    ];

    public function show(Request $request, string $page, RouterOsService $routerOs)
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        $definition = self::PAGES[$page];
        $routers = Router::where('enabled', true)->orderBy('name')->get();
        $router = $request->filled('router_id')
            ? $routers->firstWhere('id', (int) $request->query('router_id'))
            : null;
        abort_if($request->filled('router_id') && !$router, 404);

        $rows = [];
        $error = null;
        if ($router) {
            try {
                $rows = $this->readRows($definition['kind'], $router, $routerOs);
            } catch (\Throwable $exception) {
                Log::warning('Router read-only page failed', [
                    'router_id' => $router->id,
                    'page' => $page,
                    'error' => $exception->getMessage(),
                ]);
                $error = 'Data belum dapat dibaca. Periksa koneksi router dan log aplikasi.';
            }
        }

        return view('network.readonly-table', [
            'title' => $definition['title'],
            'page' => $page,
            'kind' => $definition['kind'],
            'routers' => $routers,
            'selectedRouter' => $router,
            'rows' => $rows,
            'columns' => $this->columnsFor($definition['kind']),
            'error' => $error,
        ]);
    }

    private function readRows(string $kind, Router $router, RouterOsService $routerOs): array
    {
        $raw = match ($kind) {
            'ppp-secrets', 'ppp-offline' => $routerOs->listPppSecrets($router),
            'ppp-active' => array_values($routerOs->activePppMap($router)),
            'ppp-profiles' => $routerOs->listPppProfiles($router),
            'hotspot-users' => $routerOs->listHotspotUsers($router),
            'hotspot-active' => $routerOs->listHotspotActive($router),
            'hotspot-profiles' => $routerOs->listHotspotProfiles($router),
            default => [],
        };

        if ($kind === 'ppp-offline') {
            $activeNames = array_fill_keys(array_keys($routerOs->activePppMap($router)), true);
            $raw = array_values(array_filter($raw, fn (array $row) => !isset($activeNames[$row['name'] ?? ''])));
        }
        if (in_array($kind, ['ppp-secrets', 'ppp-offline'], true)) {
            $raw = array_values(array_filter($raw, fn (array $row) => in_array($row['service'] ?? 'any', ['pppoe', 'any'], true)));
        }
        if ($kind === 'ppp-active') {
            $raw = array_values(array_filter($raw, fn (array $row) => ($row['service'] ?? 'pppoe') === 'pppoe'));
        }

        // Return explicit safe fields only. RouterOS secret/user responses may include passwords.
        $fields = match ($kind) {
            'ppp-secrets', 'ppp-offline' => ['name', 'service', 'profile', 'disabled', 'remote-address'],
            'ppp-active' => ['name', 'service', 'caller-id', 'address', 'uptime', 'encoding'],
            'ppp-profiles' => ['name', 'local-address', 'remote-address', 'rate-limit', 'only-one', 'session-timeout'],
            'hotspot-users' => ['name', 'profile', 'server', 'disabled', 'uptime', 'bytes-in', 'bytes-out'],
            'hotspot-active' => ['user', 'server', 'address', 'mac-address', 'uptime', 'bytes-in', 'bytes-out'],
            'hotspot-profiles' => ['name', 'rate-limit', 'shared-users', 'session-timeout', 'idle-timeout', 'keepalive-timeout'],
            default => [],
        };

        return array_map(static function (array $row) use ($fields): array {
            $safe = [];
            foreach ($fields as $field) {
                $safe[$field] = $row[$field] ?? '—';
            }
            return $safe;
        }, $raw);
    }

    private function columnsFor(string $kind): array
    {
        return match ($kind) {
            'ppp-secrets', 'ppp-offline' => ['name', 'service', 'profile', 'disabled', 'remote-address'],
            'ppp-active' => ['name', 'service', 'caller-id', 'address', 'uptime', 'encoding'],
            'ppp-profiles' => ['name', 'local-address', 'remote-address', 'rate-limit', 'only-one', 'session-timeout'],
            'hotspot-users' => ['name', 'profile', 'server', 'disabled', 'uptime', 'bytes-in', 'bytes-out'],
            'hotspot-active' => ['user', 'server', 'address', 'mac-address', 'uptime', 'bytes-in', 'bytes-out'],
            'hotspot-profiles' => ['name', 'rate-limit', 'shared-users', 'session-timeout', 'idle-timeout', 'keepalive-timeout'],
            default => [],
        };
    }
}

