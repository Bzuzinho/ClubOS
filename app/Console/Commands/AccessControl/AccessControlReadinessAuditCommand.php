<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use App\Models\User;
use App\Services\AccessControl\ResolveCurrentUserType;
use App\Support\AccessControl\AccessControlCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class AccessControlReadinessAuditCommand extends Command
{
    protected $signature = 'access:audit-readiness
        {--json : Devolve o relatório em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--fail-on-critical : Termina com código 1 se existirem findings críticos}
        {--fail-on-warning : Termina com código 1 se existirem findings críticos ou warnings}';

    protected $description = 'Auditoria read-only da prontidão de acessos, tipos de utilizador e guardas de rotas administrativas';

    public function __construct(
        private readonly ResolveCurrentUserType $resolveCurrentUserType,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $findings = [];
        $rows = [];

        $schemaReady = Schema::hasTable('users')
            && Schema::hasTable('dados_configuracao')
            && Schema::hasColumn('dados_configuracao', 'platform_access_enabled')
            && Schema::hasTable('user_types');

        if (! $schemaReady) {
            $findings[] = $this->finding(
                'critical',
                'access_control_schema_not_ready',
                'O schema necessário para auditar acesso à plataforma não está completo.'
            );
        } else {
            $platformUserIds = DB::table('dados_configuracao')
                ->where('platform_access_enabled', true)
                ->pluck('user_id')
                ->filter()
                ->values();

            $users = User::query()
                ->whereIn('id', $platformUserIds)
                ->orderBy('id')
                ->get();

            foreach ($users as $user) {
                $resolvedType = ($this->resolveCurrentUserType)($user);
                $resolved = $resolvedType !== null;

                $rows[] = [
                    'user_id' => (string) $user->id,
                    'member_number' => $user->numero_socio,
                    'name' => $user->nome_completo ?: $user->name,
                    'perfil' => $user->perfil,
                    'resolved_user_type_id' => $resolvedType?->id,
                    'resolved_user_type_code' => $resolvedType?->codigo,
                    'resolved_user_type_name' => $resolvedType?->nome,
                    'resolved' => $resolved,
                ];

                if (! $resolved) {
                    $findings[] = $this->finding(
                        'critical',
                        'platform_user_without_resolved_user_type',
                        'Conta com acesso explícito à plataforma sem UserType resolvido.',
                        [
                            'user_id' => (string) $user->id,
                            'member_number' => $user->numero_socio,
                            'perfil' => $user->perfil,
                        ]
                    );
                }
            }
        }

        $menuModuleKeys = collect(AccessControlCatalog::menuModules())
            ->pluck('key')
            ->reject(static fn (string $key): bool => $key === 'inicio')
            ->values();

        $permissionModuleKeys = collect(AccessControlCatalog::permissionTree())
            ->pluck('module_key')
            ->filter()
            ->unique()
            ->values();

        $modulesWithoutGranularTree = $menuModuleKeys
            ->diff($permissionModuleKeys)
            ->values()
            ->all();

        foreach ($modulesWithoutGranularTree as $moduleKey) {
            $findings[] = $this->finding(
                'warning',
                'menu_module_without_granular_permission_tree',
                'Módulo visível/configurável sem árvore granular de permissões.',
                ['module' => $moduleKey]
            );
        }

        $routeAudit = $this->auditAdministrativeRoutes();
        array_push($findings, ...$routeAudit['findings']);

        $criticalCount = collect($findings)->where('severity', 'critical')->count();
        $warningCount = collect($findings)->where('severity', 'warning')->count();
        $resolvedCount = collect($rows)->where('resolved', true)->count();
        $unresolvedCount = collect($rows)->where('resolved', false)->count();

        $payload = [
            'contract' => 'access-control-readiness-v1',
            'read_only' => true,
            'summary' => [
                'schema_ready' => $schemaReady,
                'platform_access_user_count' => count($rows),
                'resolved_user_type_count' => $resolvedCount,
                'unresolved_user_type_count' => $unresolvedCount,
                'menu_module_count' => $menuModuleKeys->count(),
                'granular_permission_module_count' => $permissionModuleKeys->count(),
                'modules_without_granular_permission_tree_count' => count($modulesWithoutGranularTree),
                'mutating_routes_without_module_guard_count' => $routeAudit['missing_module_guard_count'],
                'mutating_routes_with_module_only_guard_count' => $routeAudit['module_only_guard_count'],
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
            ],
            'modules_without_granular_permission_tree' => $modulesWithoutGranularTree,
            'platform_access_users' => $rows,
            'findings' => $findings,
        ];

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-warning') && ($criticalCount > 0 || $warningCount > 0)) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-critical') && $criticalCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{findings: array<int,array<string,mixed>>, missing_module_guard_count: int, module_only_guard_count: int}
     */
    private function auditAdministrativeRoutes(): array
    {
        $prefixes = [
            'configuracoes' => 'configuracoes',
            'logistica' => 'logistica',
            'admin/loja' => 'loja',
            'patrocinios' => 'patrocinios',
            'comunicacao' => 'comunicacao',
            'campanhas-marketing' => 'marketing',
        ];

        $findings = [];
        $missingModuleGuardCount = 0;
        $moduleOnlyGuardCount = 0;

        foreach (Route::getRoutes() as $route) {
            $mutatingMethods = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
            if ($mutatingMethods === []) {
                continue;
            }

            $uri = ltrim($route->uri(), '/');
            $moduleKey = null;

            foreach ($prefixes as $prefix => $candidateModuleKey) {
                if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                    $moduleKey = $candidateModuleKey;
                    break;
                }
            }

            if ($moduleKey === null) {
                continue;
            }

            $middleware = collect($route->gatherMiddleware())
                ->filter('is_string')
                ->values();

            $hasModuleGuard = $middleware->contains('module.access:' . $moduleKey);
            $hasPermissionGuard = $middleware->contains(
                static fn (string $item): bool => str_starts_with($item, 'permission.access:')
            );

            $metadata = [
                'module' => $moduleKey,
                'route_name' => $route->getName(),
                'uri' => $route->uri(),
                'methods' => $mutatingMethods,
            ];

            if (! $hasModuleGuard) {
                $missingModuleGuardCount++;
                $findings[] = $this->finding(
                    'critical',
                    'mutating_admin_route_without_module_guard',
                    'Rota administrativa mutável sem module.access correspondente.',
                    $metadata
                );
                continue;
            }

            if (! $hasPermissionGuard) {
                $moduleOnlyGuardCount++;
                $findings[] = $this->finding(
                    'warning',
                    'mutating_admin_route_with_module_only_guard',
                    'Rota administrativa mutável protegida apenas ao nível de módulo, sem capability granular.',
                    $metadata
                );
            }
        }

        return [
            'findings' => $findings,
            'missing_module_guard_count' => $missingModuleGuardCount,
            'module_only_guard_count' => $moduleOnlyGuardCount,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, string $reason, array $metadata = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Access control readiness audit');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['User', 'Member', 'Perfil', 'Resolved Type', 'Resolved'],
            collect($payload['platform_access_users'] ?? [])->map(static fn (array $row): array => [
                (string) ($row['user_id'] ?? ''),
                (string) ($row['member_number'] ?? ''),
                (string) ($row['perfil'] ?? ''),
                (string) ($row['resolved_user_type_code'] ?? ''),
                (bool) ($row['resolved'] ?? false) ? 'yes' : 'no',
            ])->all(),
        );

        foreach ($payload['findings'] ?? [] as $finding) {
            $this->line(sprintf(
                '[%s] %s — %s %s',
                strtoupper((string) ($finding['severity'] ?? 'info')),
                (string) ($finding['code'] ?? ''),
                (string) ($finding['reason'] ?? ''),
                ($finding['metadata'] ?? []) === [] ? '' : $this->toJson($finding['metadata'])
            ));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPathOption = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPathOption === '') {
            return;
        }

        $reportPath = str_starts_with($reportPathOption, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $reportPathOption) === 1
            ? $reportPathOption
            : base_path($reportPathOption);

        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, $this->toJson($payload));
        $this->line(sprintf('Report written to: %s', $reportPath));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
