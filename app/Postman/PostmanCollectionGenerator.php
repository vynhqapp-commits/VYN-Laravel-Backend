<?php

namespace App\Postman;

use Illuminate\Support\Facades\Artisan;

final class PostmanCollectionGenerator
{
    public static function generate(): array
    {
        Artisan::call('route:list', ['--path' => 'api', '--json' => true]);
        $rows = json_decode(Artisan::output(), true);
        if (! is_array($rows)) {
            throw new \RuntimeException('route:list --json did not return an array.');
        }

        $merged = self::mergeDuplicateMethods($rows);
        $folderItems = [];

        foreach ($merged as $r) {
            $action = $r['action'] ?? null;
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            $uri = str_replace('\\/', '/', (string) ($r['uri'] ?? ''));
            $method = self::normalizeMethod((string) ($r['method'] ?? 'GET'));
            $middleware = $r['middleware'] ?? [];
            if (! is_array($middleware)) {
                $middleware = [];
            }

            $folder = self::folderFor($uri, $middleware);
            $name = $method . ' ' . self::shortPath($uri);
            $description = self::buildDescription($middleware, $action);

            $request = self::buildRequest($method, $uri, $middleware, $action, $description);
            $folderItems[$folder][] = [
                'name' => $name,
                'request' => $request,
            ];
        }

        ksort($folderItems);
        $postmanFolders = [];
        foreach ($folderItems as $folderName => $items) {
            usort($items, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $entry = ['name' => $folderName, 'item' => $items];
            if (in_array($folderName, ['Public', 'Auth'], true)) {
                $entry['auth'] = ['type' => 'noauth'];
            }
            $postmanFolders[] = $entry;
        }

        return [
            'info' => [
                'name' => 'Salon SaaS API',
                'description' => "Full route list from `php artisan route:list --path=api --json`.\n\n**Variables**\n- `base_url` — include `/api` (e.g. `http://127.0.0.1:8000/api`).\n- `token` — JWT from login; used as `Authorization: Bearer {{token}}`.\n- `tenant` — value for `X-Tenant` (tenant id or slug) on tenant-scoped routes.\n\n**Roles**\nRoutes use Spatie roles via `CheckRole` middleware (not per-route `permission:` middleware). Effective roles are the intersection of all `CheckRole` layers on that route.",
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => 'salon-saas-api-full-v1',
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000/api'],
                ['key' => 'token', 'value' => ''],
                ['key' => 'tenant', 'value' => ''],
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
            ],
            'item' => $postmanFolders,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function mergeDuplicateMethods(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $action = $r['action'] ?? null;
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            $uri = str_replace('\\/', '/', (string) ($r['uri'] ?? ''));
            $key = $uri.'|'.$action;
            if (! isset($by[$key])) {
                $by[$key] = $r;

                continue;
            }
            $prevMethod = (string) ($by[$key]['method'] ?? '');
            $newMethod = (string) ($r['method'] ?? '');
            if ($newMethod === 'PATCH' || ($newMethod === 'PUT' && ! str_contains($prevMethod, 'PATCH'))) {
                $by[$key] = $r;
            }
        }

        return array_values($by);
    }

    private static function normalizeMethod(string $method): string
    {
        if (! str_contains($method, '|')) {
            return $method;
        }
        $parts = explode('|', $method);
        if (in_array('PATCH', $parts, true)) {
            return 'PATCH';
        }
        if (in_array('PUT', $parts, true)) {
            return 'PUT';
        }
        if (in_array('GET', $parts, true)) {
            return 'GET';
        }

        return $parts[0];
    }

    /**
     * @param  list<string>  $middleware
     */
    private static function folderFor(string $uri, array $middleware): string
    {
        $needsAuth = in_array('Illuminate\\Auth\\Middleware\\Authenticate:api', $middleware, true);
        $needsTenant = in_array('App\\Http\\Middleware\\EnsureTenant', $middleware, true);

        if (str_starts_with($uri, 'api/public/')) {
            return 'Public';
        }

        if (! $needsAuth) {
            return 'Auth';
        }

        if (str_starts_with($uri, 'api/customer/')) {
            return 'Customer';
        }

        if (str_starts_with($uri, 'api/admin/')) {
            return 'Super Admin';
        }

        if ($needsTenant) {
            $rest = preg_replace('#^api/#', '', $uri) ?? '';
            $seg = explode('/', $rest)[0] ?? 'misc';

            $map = [
                'settings' => 'Settings',
                'branches' => 'Branches',
                'salons' => 'Salon media',
                'service-categories' => 'Services',
                'services' => 'Services',
                'catalog' => 'Catalog',
                'products' => 'Products',
                'inventory' => 'Inventory',
                'staff-invitations' => 'Staff',
                'staff-time-entries' => 'Staff',
                'staff' => 'Staff',
                'customers' => 'Customers',
                'appointments' => 'Appointments',
                'time-blocks' => 'Time blocks',
                'time-off-requests' => 'Time off',
                'sales' => 'POS',
                'cash-drawers' => 'POS',
                'coupons' => 'Coupons',
                'expenses' => 'Finance',
                'debts' => 'Finance',
                'reports' => 'Reports',
                'monthly-closings' => 'Closing',
                'analytics' => 'Analytics',
                'commissions' => 'Commissions',
                'gift-cards' => 'Gift cards',
                'invoices' => 'Invoices',
                'ledger' => 'Ledger',
                'reviews' => 'Reviews',
            ];

            $label = $map[$seg] ?? ucfirst(str_replace('-', ' ', $seg));

            return 'Tenant / '.$label;
        }

        return 'Account';
    }

    private static function shortPath(string $uri): string
    {
        return preg_replace('#^api/#', '', $uri) ?? $uri;
    }

    /**
     * @param  list<string>  $middleware
     */
    private static function buildDescription(array $middleware, string $action): string
    {
        $lines = [];
        $lines[] = '**Controller** `'.$action.'`';
        $lines[] = '';
        $lines[] = '**Effective access**';
        if (in_array('App\\Http\\Middleware\\SuperAdminMiddleware', $middleware, true)) {
            $lines[] = '- Role: `super_admin`';
        } else {
            $eff = self::effectiveRoles($middleware);
            if ($eff === null) {
                $lines[] = '- Any authenticated user (no `CheckRole` restriction on this route).';
            } elseif ($eff === []) {
                $lines[] = '- No role satisfies all `CheckRole` layers (verify nested middleware).';
            } else {
                $lines[] = '- Roles (must be one of): `'.implode('`, `', $eff).'`';
            }
        }
        if (in_array('App\\Http\\Middleware\\EnsureTenant', $middleware, true)) {
            $lines[] = '- Header **`X-Tenant`**: tenant id or slug (`EnsureTenant`).';
        }
        $lines[] = '';
        $lines[] = '**Laravel middleware**';
        foreach ($middleware as $m) {
            $lines[] = '- '.$m;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $middleware
     * @return list<string>|null
     */
    private static function effectiveRoles(array $middleware): ?array
    {
        $sets = [];
        foreach ($middleware as $m) {
            if (! str_starts_with((string) $m, 'App\\Http\\Middleware\\CheckRole:')) {
                continue;
            }
            $csv = substr((string) $m, strlen('App\\Http\\Middleware\\CheckRole:'));
            $roles = array_values(array_filter(array_map('trim', explode(',', $csv))));
            if ($roles !== []) {
                $sets[] = $roles;
            }
        }
        if ($sets === []) {
            return null;
        }
        $inter = $sets[0];
        for ($i = 1; $i < count($sets); $i++) {
            $inter = array_values(array_intersect($inter, $sets[$i]));
        }

        return $inter;
    }

    /**
     * @param  list<string>  $middleware
     */
    private static function buildRequest(string $method, string $uri, array $middleware, string $action, string $description): array
    {
        $needsAuth = in_array('Illuminate\\Auth\\Middleware\\Authenticate:api', $middleware, true);
        $needsTenant = in_array('App\\Http\\Middleware\\EnsureTenant', $middleware, true);

        $rel = preg_replace('#^api/#', '', $uri) ?? '';
        $rel = preg_replace_callback('#\{([^}]+)\}#', static fn (array $m) => '{{'.$m[1].'}}', $rel);
        $basePath = '{{base_url}}/'.$rel;

        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
        ];
        if ($needsAuth) {
            $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{token}}'];
        }
        if ($needsTenant) {
            $headers[] = ['key' => 'X-Tenant', 'value' => '{{tenant}}'];
        }

        $spec = PostmanBodyCatalog::forAction($action, $method);
        $body = null;
        $url = $basePath;

        if ($method === 'GET' && ! empty($spec['query'])) {
            $pairs = [];
            foreach ($spec['query'] as $q) {
                $pairs[] = $q['key'].'='.rawurlencode((string) ($q['value'] ?? ''));
            }
            $url = [
                'raw' => $basePath.'?'.implode('&', $pairs),
                'query' => array_map(static function ($q) {
                    return [
                        'key' => $q['key'],
                        'value' => (string) ($q['value'] ?? ''),
                        'disabled' => (bool) ($q['disabled'] ?? false),
                    ];
                }, $spec['query']),
            ];
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $mode = $spec['mode'] ?? 'json';
            if ($mode === 'formdata' && ! empty($spec['formdata'])) {
                $body = [
                    'mode' => 'formdata',
                    'formdata' => $spec['formdata'],
                ];
            } elseif ($mode === 'none') {
                $note = $spec['note'] ?? 'No JSON body.';
                $description .= "\n\n**Body**\n".$note;
            } else {
                $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
                $raw = $spec['raw'] ?? '{}';
                if ($raw === '{}' || $raw === '') {
                    $description .= "\n\n**Body**\nExample JSON not defined in catalog — align with `".$action.'` validation.';
                }
                $body = [
                    'mode' => 'raw',
                    'raw' => $raw,
                ];
            }
        }

        $req = [
            'method' => $method,
            'header' => $headers,
            'url' => $url,
            'description' => $description,
        ];
        if ($body !== null) {
            $req['body'] = $body;
        }

        return $req;
    }
}
