<?php

declare(strict_types=1);

namespace App\Services\Routing;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use SplFileInfo;

final class RouteTopologyAuditService
{
    private const VERSION = 'web-route-topology-v1';

    private const BASELINE_PATH = 'qa/baselines/web-route-topology.json';

    /** @return array<string,mixed> */
    public function report(): array
    {
        $routes = $this->webRouteSignatures();
        $namedRoutes = $this->namedWebRouteSignatures();
        $contractPayload = ['ordered_routes' => $routes, 'named_routes' => $namedRoutes];
        $contractHash = hash('sha256', json_encode($contractPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
        $baseline = $this->baseline();
        $duplicates = $this->duplicateSignatureGroups($routes);
        $duplicateNames = $this->duplicateNameGroups($routes);
        $sourceDuplicateCandidates = $this->sourceLiteralDuplicateCandidates();
        $redirects = $this->legacyRedirects();
        $legacyConsumers = $this->legacyRedirectConsumers($redirects);
        $routeFiles = $this->routeFiles();
        $fallbackRoutes = collect($routes)->where('is_fallback', true)->values();
        $fallback = $fallbackRoutes->first();
        $last = $routes === [] ? null : $routes[array_key_last($routes)];
        $fallbackRegisteredLast = $fallback !== null && $last !== null && $fallback['position'] === $last['position'];
        $contractMatches = ($baseline['version'] ?? null) === self::VERSION
            && ($baseline['contract_hash'] ?? null) === $contractHash
            && (int) ($baseline['route_count'] ?? -1) === count($routes)
            && (int) ($baseline['named_route_count'] ?? -1) === count($namedRoutes)
            && ($baseline['fallback_name'] ?? null) === ($fallback['name'] ?? null)
            && (bool) ($baseline['fallback_registered_last'] ?? false) === $fallbackRegisteredLast;

        return [
            'version' => self::VERSION,
            'read_only' => true,
            'summary' => [
                'web_route_count' => count($routes),
                'named_web_route_count' => collect($routes)->whereNotNull('name')->count(),
                'named_route_lookup_count' => count($namedRoutes),
                'unnamed_web_route_count' => collect($routes)->whereNull('name')->count(),
                'duplicate_signature_group_count' => count($duplicates),
                'duplicate_name_group_count' => count($duplicateNames),
                'source_literal_duplicate_candidate_count' => count($sourceDuplicateCandidates),
                'legacy_redirect_count' => count($redirects),
                'legacy_redirect_consumer_count' => count($legacyConsumers),
                'legacy_named_boundary_count' => count($this->legacyNamedBoundaries($routes)),
                'modular_route_file_count' => count($routeFiles),
                'loaded_modular_route_file_count' => collect($routeFiles)->where('loaded', true)->count(),
                'web_source_line_count' => $this->lineCount(base_path('routes/web.php')),
                'web_source_route_call_count' => $this->routeCallCount(base_path('routes/web.php')),
                'web_source_controller_import_count' => $this->controllerImportCount(base_path('routes/web.php')),
                'fallback_route_count' => $fallbackRoutes->count(),
                'fallback_registered_last' => $fallbackRegisteredLast,
                'contract_matches_baseline' => $contractMatches,
            ],
            'contract' => [
                'hash_algorithm' => 'sha256',
                'hash' => $contractHash,
                'baseline_hash' => $baseline['contract_hash'] ?? null,
                'baseline_route_count' => $baseline['route_count'] ?? null,
                'fallback_name' => $fallback['name'] ?? null,
                'fallback_position' => $fallback['position'] ?? null,
                'last_route_position' => $last['position'] ?? null,
                'signatures' => $routes,
                'named_routes' => $namedRoutes,
            ],
            'duplicates' => [
                'method_uri_groups' => $duplicates,
                'name_groups' => $duplicateNames,
                'source_literal_candidates' => $sourceDuplicateCandidates,
            ],
            'legacy' => [
                'redirects' => $redirects,
                'redirect_consumers' => $legacyConsumers,
                'named_boundaries' => $this->legacyNamedBoundaries($routes),
            ],
            'modularization' => [
                'route_files' => $routeFiles,
                'registration_surfaces' => [
                    'routes/web.php',
                    'app/Providers/AppServiceProvider.php',
                    'bootstrap/app.php',
                ],
                'preserve' => ['methods', 'uri', 'domain', 'name_lookup', 'action', 'middleware', 'where', 'order', 'fallback'],
            ],
            'interpretation' => [
                'diagnostic_only' => true,
                'no_routes_changed' => true,
                'duplicate_signatures_require_classification_before_extraction' => true,
                'source_literal_duplicates_are_candidates_not_runtime_findings' => true,
                'legacy_redirects_require_zero_runtime_consumers_before_retirement' => true,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function webRouteSignatures(): array
    {
        $signatures = [];

        foreach (Route::getRoutes() as $route) {
            if (! $route instanceof IlluminateRoute) {
                continue;
            }

            $middleware = array_values(array_map(
                static fn (mixed $value): string => is_string($value) ? $value : get_debug_type($value),
                $route->gatherMiddleware(),
            ));

            if (! in_array('web', $middleware, true)) {
                continue;
            }

            $where = $route->wheres;
            ksort($where);

            $signatures[] = [
                'position' => count($signatures) + 1,
                'methods' => array_values($route->methods()),
                'uri' => $route->uri(),
                'domain' => $route->getDomain(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => $middleware,
                'where' => $where,
                'is_fallback' => $this->isFallback($route),
            ];
        }

        return $signatures;
    }

    /** @return array<string,array<string,mixed>> */
    private function namedWebRouteSignatures(): array
    {
        $signatures = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! $route instanceof IlluminateRoute) {
                continue;
            }

            $middleware = array_values(array_map(
                static fn (mixed $value): string => is_string($value) ? $value : get_debug_type($value),
                $route->gatherMiddleware(),
            ));

            if (! in_array('web', $middleware, true)) {
                continue;
            }

            $where = $route->wheres;
            ksort($where);

            $signatures[(string) $name] = [
                'methods' => array_values($route->methods()),
                'uri' => $route->uri(),
                'domain' => $route->getDomain(),
                'action' => $route->getActionName(),
                'middleware' => $middleware,
                'where' => $where,
                'is_fallback' => $this->isFallback($route),
            ];
        }

        ksort($signatures);

        return $signatures;
    }

    /**
     * @param list<array<string,mixed>> $routes
     * @return list<array<string,mixed>>
     */
    private function duplicateSignatureGroups(array $routes): array
    {
        return collect($routes)
            ->groupBy(static fn (array $route): string => json_encode([
                $route['methods'],
                $route['domain'],
                $route['uri'],
            ], JSON_UNESCAPED_SLASHES) ?: '')
            ->filter(static fn ($group): bool => $group->count() > 1)
            ->map(static fn ($group): array => [
                'methods' => $group->first()['methods'],
                'domain' => $group->first()['domain'],
                'uri' => $group->first()['uri'],
                'routes' => $group->map(static fn (array $route): array => [
                    'position' => $route['position'],
                    'name' => $route['name'],
                    'action' => $route['action'],
                    'middleware' => $route['middleware'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param list<array<string,mixed>> $routes
     * @return list<array<string,mixed>>
     */
    private function duplicateNameGroups(array $routes): array
    {
        return collect($routes)
            ->filter(static fn (array $route): bool => filled($route['name']))
            ->groupBy('name')
            ->filter(static fn ($group): bool => $group->count() > 1)
            ->map(static fn ($group, string $name): array => [
                'name' => $name,
                'routes' => $group->map(static fn (array $route): array => [
                    'position' => $route['position'],
                    'methods' => $route['methods'],
                    'uri' => $route['uri'],
                    'action' => $route['action'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{method:string,uri:string,occurrences:list<array{line:int,name:?string}>}> */
    private function sourceLiteralDuplicateCandidates(): array
    {
        $lines = preg_split('/\R/', File::get(base_path('routes/web.php'))) ?: [];
        $declarations = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/Route::(get|post|put|patch|delete|options)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $line, $match) !== 1) {
                continue;
            }

            $declarations[] = [
                'method' => strtoupper($match[1]),
                'uri' => $match[2],
                'line' => $index + 1,
                'name' => preg_match('/->name\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $line, $nameMatch) === 1
                    ? $nameMatch[1]
                    : null,
            ];
        }

        return collect($declarations)
            ->groupBy(static fn (array $route): string => $route['method'].' '.$route['uri'])
            ->filter(static fn ($group): bool => $group->count() > 1)
            ->map(static fn ($group): array => [
                'method' => $group->first()['method'],
                'uri' => $group->first()['uri'],
                'occurrences' => $group->map(static fn (array $route): array => [
                    'line' => $route['line'],
                    'name' => $route['name'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{source:string,target:string,status:int}> */
    private function legacyRedirects(): array
    {
        $contents = File::get(base_path('routes/web.php'));
        preg_match_all(
            '/Route::redirect\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"](?:\s*,\s*(\d+))?\s*\)/',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)->map(static fn (array $match): array => [
            'source' => $match[1],
            'target' => $match[2],
            'status' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 302,
        ])->values()->all();
    }

    /**
     * @param list<array{source:string,target:string,status:int}> $redirects
     * @return list<array{source:string,path:string,line:int}>
     */
    private function legacyRedirectConsumers(array $redirects): array
    {
        $roots = [app_path(), resource_path('js')];
        $findings = [];
        $sources = collect($redirects)
            ->map(static fn (array $redirect): string => preg_replace('/\/\{[^}]+\}.*$/', '', $redirect['source']) ?: $redirect['source'])
            ->unique()
            ->values()
            ->all();

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! $file instanceof SplFileInfo || ! in_array(strtolower($file->getExtension()), ['php', 'js', 'jsx', 'ts', 'tsx'], true)) {
                    continue;
                }

                $lines = preg_split('/\R/', File::get($file->getPathname())) ?: [];
                foreach ($sources as $source) {
                    $pattern = '~[\'\"`]'.preg_quote($source, '~').'(?:[/?\'\"`]|$)~';

                    foreach ($lines as $index => $line) {
                        if (preg_match($pattern, $line) !== 1) {
                            continue;
                        }

                        $findings[] = [
                            'source' => $source,
                            'path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                            'line' => $index + 1,
                        ];
                    }
                }
            }
        }

        return collect($findings)->unique(static fn (array $finding): string => implode(':', $finding))->values()->all();
    }

    /**
     * @param list<array<string,mixed>> $routes
     * @return list<array<string,mixed>>
     */
    private function legacyNamedBoundaries(array $routes): array
    {
        return collect($routes)
            ->filter(static function (array $route): bool {
                $name = (string) ($route['name'] ?? '');
                $action = (string) ($route['action'] ?? '');

                return str_contains($name, 'legacy')
                    || str_contains($action, 'TransacoesController')
                    || str_contains($action, 'CategoriasFinanceirasController');
            })
            ->map(static fn (array $route): array => [
                'position' => $route['position'],
                'methods' => $route['methods'],
                'uri' => $route['uri'],
                'name' => $route['name'],
                'action' => $route['action'],
            ])
            ->values()
            ->all();
    }

    /** @return list<array{path:string,route_call_count:int,loaded:bool,loaded_from:list<string>}> */
    private function routeFiles(): array
    {
        $registrationSources = [
            'routes/web.php' => File::get(base_path('routes/web.php')),
            'app/Providers/AppServiceProvider.php' => File::get(app_path('Providers/AppServiceProvider.php')),
            'bootstrap/app.php' => File::get(base_path('bootstrap/app.php')),
        ];

        return collect(File::files(base_path('routes')))
            ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->reject(static fn (SplFileInfo $file): bool => in_array($file->getFilename(), ['api.php', 'auth.php', 'console.php', 'web.php'], true))
            ->sortBy(static fn (SplFileInfo $file): string => $file->getFilename())
            ->map(function (SplFileInfo $file) use ($registrationSources): array {
                $path = 'routes/'.$file->getFilename();
                $loadedFrom = collect($registrationSources)
                    ->filter(static fn (string $contents): bool => str_contains($contents, $path) || str_contains($contents, $file->getFilename()))
                    ->keys()
                    ->values()
                    ->all();

                return [
                    'path' => $path,
                    'route_call_count' => $this->routeCallCount($file->getPathname()),
                    'loaded' => $loadedFrom !== [],
                    'loaded_from' => $loadedFrom,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function baseline(): array
    {
        $path = base_path(self::BASELINE_PATH);
        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?: [];
    }

    private function isFallback(IlluminateRoute $route): bool
    {
        return (bool) ($route->isFallback ?? false)
            || ($route->uri() === '{fallbackPlaceholder}' && ($route->wheres['fallbackPlaceholder'] ?? null) === '.*');
    }

    private function lineCount(string $path): int
    {
        $contents = File::get($path);

        return substr_count($contents, "\n") + ($contents === '' || str_ends_with($contents, "\n") ? 0 : 1);
    }

    private function routeCallCount(string $path): int
    {
        return (int) preg_match_all('/Route::(?:get|post|put|patch|delete|options|match|any|resource|redirect|fallback)\s*\(/', File::get($path));
    }

    private function controllerImportCount(string $path): int
    {
        return (int) preg_match_all('/^use App\\\\Http\\\\Controllers\\\\/m', File::get($path));
    }
}
