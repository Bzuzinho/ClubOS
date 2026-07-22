<?php

declare(strict_types=1);

namespace App\Services\Pessoas;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MemberModelAuditService
{
    private const VERSION = 'c1-member-model-audit-v1';

    /** @var array<int,string> */
    private const ADMIN_PROFILES = ['admin', 'administrator', 'administrador', 'super_admin'];

    /** @var array<int,string> */
    private const ACTIVE_STATUSES = ['ativo', 'active', 'activa', 'ativa'];

    /** @var array<int,string> */
    private const KNOWN_STATUSES = ['ativo', 'active', 'activa', 'ativa', 'inativo', 'inactive', 'suspenso', 'suspended', 'eliminado', 'deleted', 'cancelado', 'cancelled'];

    /** @var array<int,string> */
    private const ATHLETE_MARKERS = ['atleta', 'athlete'];

    /** @var array<int,string> */
    private const GUARDIAN_MARKERS = ['encarregado', 'encarregado_educacao', 'educacao', 'guardian', 'responsavel', 'responsavel_educacao'];

    /** @var array<int,string> */
    private const OPERATIONAL_PROFILES = ['staff', 'colaborador', 'treinador', 'coach', 'gestor', 'manager', 'logistica', 'financeiro', 'secretaria', 'direcao', 'direcao_tecnica'];

    /** @var array<int,string> */
    private const ACCESS_GRANTED_COLUMNS = ['access_enabled', 'portal_user_enabled', 'can_login', 'login_enabled', 'app_access_enabled', 'portal_access_granted', 'user_access_enabled'];

    /** @var array<int,string> */
    private const INVITE_COLUMNS = ['ultimo_envio_acessos_at', 'invite_sent_at', 'invited_at', 'access_invited_at', 'accepted_invite_at', 'password_changed_at'];

    /** @var array<int,string> */
    private const LOGIN_ACTIVITY_COLUMNS = ['last_login_at', 'last_seen_at', 'login_at', 'email_verified_at'];

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function audit(array $filters = []): array
    {
        $filters = [
            'user' => $this->blankToNull($filters['user'] ?? null),
            'member' => $this->blankToNull($filters['member'] ?? null),
            'only_actionable' => (bool) ($filters['only_actionable'] ?? false),
        ];

        $schema = $this->detectSchema();
        $users = $this->users($filters, $schema);
        $profiles = $this->personalProfiles($filters, $schema);
        $sportsProfiles = $this->sportsProfiles($schema);
        $financialProfiles = $this->financialProfiles($schema);
        $configurationProfiles = $this->configurationProfiles($filters, $schema);
        $rolesByUser = $this->rolesByUser($schema);
        $contexts = $this->functionalContexts($users, $profiles, $sportsProfiles, $financialProfiles, $configurationProfiles, $rolesByUser, $schema);
        $schema['platform_access_schema'] = $this->platformAccessSchema($schema, $contexts);

        $findings = [];

        $findings = array_merge($findings, $this->identityFindings($users, $profiles, $schema, $contexts));
        $findings = array_merge($findings, $this->memberProfileFindings($users, $profiles, $schema, $contexts));
        $findings = array_merge($findings, $this->familyFindings($users, $schema, $contexts));
        $findings = array_merge($findings, $this->typeAndStatusFindings($users, $rolesByUser, $schema, $contexts));
        $findings = array_merge($findings, $this->sportsFindings($users, $profiles, $sportsProfiles, $schema, $contexts));
        $findings = array_merge($findings, $this->financialFindings($users, $financialProfiles, $schema, $contexts));
        $findings = array_merge($findings, $this->permissionFindings($users, $rolesByUser, $schema, $contexts));

        $findings = $this->appendCleanFindings($users, $findings, $contexts);
        $findings = $this->deduplicateFindings($findings);

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toISOString(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($users, $profiles, $sportsProfiles, $schema, $findings, $contexts),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function inspectPlatformAccess(array $filters = []): array
    {
        $filters = [
            'user' => $this->blankToNull($filters['user'] ?? null),
            'only_actionable' => (bool) ($filters['only_actionable'] ?? false),
        ];

        $schema = $this->detectSchema();
        $users = $this->users($filters + ['member' => null], $schema);
        $profiles = $this->personalProfiles($filters + ['member' => null], $schema);
        $sportsProfiles = $this->sportsProfiles($schema);
        $financialProfiles = $this->financialProfiles($schema);
        $configurationProfiles = $this->configurationProfiles($filters + ['member' => null], $schema);
        $rolesByUser = $this->rolesByUser($schema);
        $contexts = $this->functionalContexts($users, $profiles, $sportsProfiles, $financialProfiles, $configurationProfiles, $rolesByUser, $schema);
        $schema['platform_access_schema'] = $this->platformAccessSchema($schema, $contexts);

        $rows = $users->map(function (object $user) use ($contexts): array {
            $context = $this->contextFor($contexts, $user);
            $actionable = (bool) ($context['access_role_missing_is_problem'] ?? false);

            return [
                'user_id' => (string) $user->id,
                'name' => (string) ($user->nome_completo ?? $user->name ?? ''),
                'perfil' => (string) ($context['perfil'] ?? ''),
                'member_functional_types' => $context['member_functional_types'] ?? [],
                'estado' => (string) ($context['estado'] ?? ''),
                'portal_eligible' => (bool) ($context['portal_eligible'] ?? false),
                'portal_profile_enabled' => (bool) ($context['portal_profile_enabled'] ?? false),
                'platform_access_granted' => (bool) ($context['platform_access_granted'] ?? false),
                'platform_access_granted_reason' => (string) ($context['platform_access_granted_reason'] ?? ''),
                'access_expected' => (bool) ($context['access_expected'] ?? false),
                'access_expected_reason' => (string) ($context['access_expected_reason'] ?? ''),
                'has_access_role' => (bool) ($context['has_access_role'] ?? false),
                'access_roles' => $context['access_roles'] ?? [],
                'issue' => (string) ($context['access_role_missing_reason'] ?? ''),
                'platform_access_status' => (string) ($context['platform_access_status'] ?? ''),
                'recommendation' => $this->platformAccessInspectionRecommendation($context),
                'actionable' => $actionable,
                'context' => $context,
            ];
        })->values()->all();

        if ($filters['only_actionable']) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toISOString(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => [
                'total_users_scanned' => $users->count(),
                'total_rows' => count($rows),
                'total_platform_access_expected' => collect($contexts)->where('access_expected', true)->count(),
                'total_platform_access_configured' => collect($contexts)->where('has_access_role', true)->count(),
                'total_no_platform_access_expected' => collect($contexts)->filter(fn (array $context): bool => ! (bool) ($context['access_expected'] ?? false))->count(),
                'portal_eligible_count' => collect($contexts)->where('portal_eligible', true)->count(),
                'portal_eligible_without_access_count' => collect($contexts)->filter(fn (array $context): bool => (bool) ($context['portal_eligible'] ?? false) && ! (bool) ($context['platform_access_granted'] ?? false))->count(),
                'platform_access_granted_count' => collect($contexts)->where('platform_access_granted', true)->count(),
                'platform_access_granted_missing_role_count' => collect($contexts)->filter(fn (array $context): bool => (bool) ($context['platform_access_granted'] ?? false) && ! (bool) ($context['has_access_role'] ?? false))->count(),
                'platform_access_expected_count' => collect($contexts)->where('access_expected', true)->count(),
                'platform_access_expected_without_role_count' => collect($contexts)->filter(fn (array $context): bool => (bool) ($context['access_expected'] ?? false) && ! (bool) ($context['has_access_role'] ?? false))->count(),
                'platform_access_issue_count' => collect($contexts)->where('access_role_missing_is_problem', true)->count(),
                'actionable_count' => collect($rows)->where('actionable', true)->count(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function detectSchema(): array
    {
        $tables = [];
        foreach ([
            'users',
            'members',
            'dados_pessoais',
            'dados_configuracao',
            'dados_financeiros',
            'athletes',
            'athlete_sports_data',
            'guardians',
            'user_guardian',
            'familias',
            'familia_user',
            'user_relationships',
            'user_types',
            'user_user_type',
            'user_type_permissions',
            'permission_nodes',
            'centro_custo_user',
            'cost_centers',
            'user_documents',
            'invoices',
        ] as $table) {
            $tables[$table] = Schema::hasTable($table);
        }

        return [
            'tables' => $tables,
            'member_profile_table' => $tables['members'] ? 'members' : ($tables['dados_pessoais'] ? 'dados_pessoais' : 'users'),
            'users_columns' => $this->columns('users', array_values(array_unique(array_merge(['id', 'name', 'email', 'email_utilizador', 'password', 'email_verified_at', 'last_login_at', 'perfil', 'estado', 'tipo_membro', 'menor', 'ativo_desportivo', 'data_nascimento', 'numero_socio'], self::ACCESS_GRANTED_COLUMNS, self::INVITE_COLUMNS, self::LOGIN_ACTIVITY_COLUMNS)))),
            'dados_pessoais_columns' => $this->columns('dados_pessoais', ['id', 'user_id', 'nome_completo', 'data_nascimento', 'nif', 'documento_identificacao', 'contacto', 'contacto_alternativo', 'tipo_utilizador']),
            'dados_configuracao_columns' => $this->columns('dados_configuracao', array_values(array_unique(array_merge(['id', 'user_id', 'acesso_portal_ativo', 'ultimo_envio_acessos_at'], self::ACCESS_GRANTED_COLUMNS, self::INVITE_COLUMNS, self::LOGIN_ACTIVITY_COLUMNS)))),
            'platform_access_schema' => [
                'dados_configuracao_exists' => $tables['dados_configuracao'],
                'portal_access_active_column' => Schema::hasColumn('dados_configuracao', 'acesso_portal_ativo'),
                'access_granted_columns_detected' => $this->existingColumns('dados_configuracao', self::ACCESS_GRANTED_COLUMNS),
                'invite_columns_detected' => array_values(array_unique(array_merge(
                    $this->existingColumns('dados_configuracao', self::INVITE_COLUMNS),
                    $this->existingColumns('users', self::INVITE_COLUMNS),
                ))),
                'login_activity_columns_detected' => $this->existingColumns('users', self::LOGIN_ACTIVITY_COLUMNS),
                'role_tables_detected' => array_values(array_filter([
                    $tables['user_user_type'] ? 'user_user_type' : null,
                    $tables['user_types'] ? 'user_types' : null,
                    $tables['user_type_permissions'] ? 'user_type_permissions' : null,
                    $tables['permission_nodes'] ? 'permission_nodes' : null,
                ])),
                'portal_access_active_true_count' => 0,
                'platform_access_granted_count' => 0,
                'portal_eligible_without_access_count' => 0,
            ],
            'family_sources' => [
                'user_guardian' => $tables['user_guardian'],
                'familia_user' => $tables['familia_user'],
                'user_relationships' => $tables['user_relationships'],
                'users_legacy_arrays' => Schema::hasColumn('users', 'encarregado_educacao') || Schema::hasColumn('users', 'educandos'),
            ],
            'permission_sources' => [
                'perfil' => Schema::hasColumn('users', 'perfil'),
                'user_user_type' => $tables['user_user_type'],
                'user_type_permissions' => $tables['user_type_permissions'],
                'permission_nodes' => $tables['permission_nodes'],
            ],
            'sports_sources' => [
                'users_ativo_desportivo' => Schema::hasColumn('users', 'ativo_desportivo'),
                'athlete_sports_data' => $tables['athlete_sports_data'],
                'athletes' => $tables['athletes'],
            ],
            'financial_sources' => [
                'dados_financeiros' => $tables['dados_financeiros'],
                'centro_custo_user' => $tables['centro_custo_user'],
                'users_centro_custo_legacy' => Schema::hasColumn('users', 'centro_custo'),
            ],
            'financial_tables' => [
                'invoices' => $tables['invoices'],
            ],
            'invoice_columns' => $this->columns('invoices', ['user_id', 'tipo', 'origem_tipo', 'estado', 'status', 'estado_pagamento', 'valor_em_aberto']),
        ];
    }

    /**
     * @param array<int,string> $wanted
     * @return array<string,bool>
     */
    private function columns(string $table, array $wanted): array
    {
        $columns = [];
        foreach ($wanted as $column) {
            $columns[$column] = Schema::hasColumn($table, $column);
        }

        return $columns;
    }

    /**
     * @param array<int,string> $wanted
     * @return array<int,string>
     */
    private function existingColumns(string $table, array $wanted): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter($wanted, static fn (string $column): bool => Schema::hasColumn($table, $column)));
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array<string,mixed>> $contexts
     * @return array<string,mixed>
     */
    private function platformAccessSchema(array $schema, array $contexts): array
    {
        $base = data_get($schema, 'platform_access_schema', []);
        $contextCollection = collect($contexts);

        return array_merge($base, [
            'portal_access_active_true_count' => $contextCollection->where('portal_eligible', true)->count(),
            'platform_access_granted_count' => $contextCollection->where('platform_access_granted', true)->count(),
            'portal_eligible_without_access_count' => $contextCollection
                ->filter(fn (array $context): bool => (bool) ($context['portal_eligible'] ?? false) && ! (bool) ($context['platform_access_granted'] ?? false))
                ->count(),
        ]);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $schema
     * @return Collection<int,object>
     */
    private function users(array $filters, array $schema): Collection
    {
        if (! data_get($schema, 'tables.users')) {
            return collect();
        }

        $query = DB::table('users')->select('users.*')->orderBy('users.created_at')->orderBy('users.id');

        if ($filters['user']) {
            $query->where('users.id', $filters['user']);
        }

        if ($filters['member'] && data_get($schema, 'tables.dados_pessoais')) {
            $query->join('dados_pessoais', 'dados_pessoais.user_id', '=', 'users.id')
                ->where('dados_pessoais.id', $filters['member']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $schema
     * @return Collection<int,object>
     */
    private function personalProfiles(array $filters, array $schema): Collection
    {
        if (! data_get($schema, 'tables.dados_pessoais')) {
            return collect();
        }

        $query = DB::table('dados_pessoais')->orderBy('created_at')->orderBy('id');

        if ($filters['member']) {
            $query->where('id', $filters['member']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $schema
     * @return Collection<int,object>
     */
    private function sportsProfiles(array $schema): Collection
    {
        if (! data_get($schema, 'tables.athlete_sports_data')) {
            return collect();
        }

        return DB::table('athlete_sports_data')->get();
    }

    /**
     * @param array<string,mixed> $schema
     * @return Collection<int,object>
     */
    private function financialProfiles(array $schema): Collection
    {
        if (! data_get($schema, 'tables.dados_financeiros')) {
            return collect();
        }

        return DB::table('dados_financeiros')->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $schema
     * @return Collection<int,object>
     */
    private function configurationProfiles(array $filters, array $schema): Collection
    {
        if (! data_get($schema, 'tables.dados_configuracao')) {
            return collect();
        }

        $query = DB::table('dados_configuracao')->orderBy('created_at')->orderBy('id');

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,Collection<int,object>>
     */
    private function rolesByUser(array $schema): array
    {
        if (! data_get($schema, 'tables.user_user_type')) {
            return [];
        }

        $query = DB::table('user_user_type')
            ->leftJoin('user_types', 'user_types.id', '=', 'user_user_type.user_type_id')
            ->select([
                'user_user_type.user_id',
                'user_user_type.user_type_id',
                'user_types.codigo',
                'user_types.nome',
                'user_types.ativo',
            ]);

        return $query->get()->groupBy('user_id')->all();
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function identityFindings(Collection $users, Collection $profiles, array $schema, array $contexts): array
    {
        $findings = [];

        foreach ($users as $user) {
            $name = $this->displayName($user, $profiles);
            if ($this->isBlank($user->name ?? null) && $this->isBlank($name)) {
                $findings[] = $this->finding('critical', 'user_missing_required_identity', $user, null, 'Nome obrigatório em falta.', 'create_missing_member_profile', true, $this->contextFor($contexts, $user));
            }

            $context = $this->contextFor($contexts, $user);
            if ($this->isBlank($user->email ?? null) && $this->expectsAccessRole($context)) {
                $findings[] = $this->finding('critical', 'user_missing_required_identity', $user, null, 'Email de login em falta para utilizador com sinais de acesso.', 'review_user_permissions', true, $context);
            }

            $birthdateInfo = $context['birthdate'] ?? ['status' => 'missing'];
            if (($birthdateInfo['status'] ?? 'missing') === 'invalid_format') {
                $findings[] = $this->finding('warning', 'birthdate_invalid_format', $user, null, 'Data de nascimento com formato inválido.', 'review_member_birthdate', true, $context);
            } elseif (($birthdateInfo['status'] ?? 'missing') === 'year_out_of_range') {
                $findings[] = $this->finding('warning', 'birthdate_year_out_of_range', $user, null, 'Data de nascimento com ano fora do intervalo válido.', 'review_member_birthdate', true, $context);
            } elseif (($birthdateInfo['status'] ?? 'missing') === 'future') {
                $findings[] = $this->finding('warning', 'birthdate_future', $user, null, 'Data de nascimento futura.', 'review_member_birthdate', true, $context);
            } elseif (($birthdateInfo['status'] ?? 'missing') === 'age_unrealistic') {
                $findings[] = $this->finding('warning', 'birthdate_age_unrealistic', $user, null, 'Data de nascimento implica idade pouco plausível.', 'review_member_birthdate', true, $context);
            } elseif (($birthdateInfo['status'] ?? 'missing') === 'administrative_placeholder') {
                $findings[] = $this->finding('info', 'administrative_placeholder_birthdate', $user, null, 'Data de nascimento placeholder em utilizador administrativo/sistema.', 'no_action_needed_administrative_placeholder_birthdate', false, $context);
            }

            $phone = $this->firstFilled($this->profileForUser($profiles, (string) $user->id)?->contacto ?? null, $user->contacto ?? null, $user->contacto_telefonico ?? null);
            if ($phone !== null && ! preg_match('/^[0-9 +().-]{6,30}$/', $phone)) {
                $findings[] = $this->finding('info', 'user_missing_required_identity', $user, null, 'Contacto telefónico com formato pouco provável.', 'create_missing_member_profile', false, $this->contextFor($contexts, $user));
            }
        }

        $findings = array_merge($findings, $this->duplicateFindings($users, 'email', 'duplicate_user_email', 'Email de login duplicado.', 'merge_duplicate_member_after_review', $contexts));
        $findings = array_merge($findings, $this->duplicateProfileFindings($profiles, 'nif', 'duplicate_member_identifier', 'NIF duplicado.'));
        $findings = array_merge($findings, $this->duplicateProfileFindings($profiles, 'documento_identificacao', 'duplicate_member_identifier', 'Documento de identificação duplicado.'));
        $findings = array_merge($findings, $this->duplicateCompositeIdentityFindings($users, $profiles, $contexts));

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function memberProfileFindings(Collection $users, Collection $profiles, array $schema, array $contexts): array
    {
        $findings = [];
        $userIds = $users->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $allUserIds = data_get($schema, 'tables.users') ? DB::table('users')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all() : [];
        $profilesByUser = $profiles->groupBy('user_id');

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user)) {
                $findings[] = $this->finding('info', 'administrative_user_excluded_from_member_audit', $user, null, 'Utilizador administrativo excluído das regras obrigatórias de ficha de membro.', 'no_action_needed_member_model_clean', false, $this->contextFor($contexts, $user));
                continue;
            }

            $context = $this->contextFor($contexts, $user);
            if (data_get($schema, 'tables.dados_pessoais') && ! $profilesByUser->has((string) $user->id) && ($context['has_login'] || in_array('operational_user', $context['functional_classification'], true))) {
                $findings[] = $this->finding('warning', 'user_without_member_profile', $user, null, 'Utilizador operacional sem ficha canónica em dados_pessoais.', 'create_missing_member_profile', true, $context);
            }

            if ($profilesByUser->get((string) $user->id, collect())->count() > 1) {
                $findings[] = $this->finding('critical', 'member_user_link_invalid', $user, null, 'Múltiplas fichas canónicas associadas ao mesmo utilizador.', 'merge_duplicate_member_after_review', true, $context);
            }
        }

        foreach ($profiles as $profile) {
            if (! in_array((string) ($profile->user_id ?? ''), $allUserIds, true)) {
                $findings[] = $this->profileFinding('critical', 'member_user_link_invalid', $profile, 'Ficha canónica aponta para user inexistente.', 'link_member_to_user_after_review', true);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function familyFindings(Collection $users, array $schema, array $contexts): array
    {
        $findings = [];
        $scannedUserIds = $users->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $allUserIds = data_get($schema, 'tables.users') ? DB::table('users')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all() : collect();
        $allUserIds = $allUserIds instanceof Collection ? $allUserIds->map(static fn (mixed $id): string => (string) $id)->all() : $allUserIds;

        $guardianRows = data_get($schema, 'tables.user_guardian') ? DB::table('user_guardian')->get() : collect();
        $guardiansByChild = $guardianRows->groupBy('user_id');
        $childrenByGuardian = $guardianRows->groupBy('guardian_id');

        foreach ($guardianRows as $row) {
            if (! in_array((string) $row->user_id, $allUserIds, true) || ! in_array((string) $row->guardian_id, $allUserIds, true) || (string) $row->user_id === (string) $row->guardian_id) {
                $findings[] = [
                    'severity' => 'critical',
                    'code' => 'invalid_guardian_relation',
                    'user_id' => (string) ($row->user_id ?? ''),
                    'member_id' => null,
                    'name' => '',
                    'detail' => 'Relação user_guardian inválida ou órfã.',
                    'actionable' => true,
                    'recommendation' => 'assign_guardian_after_review',
                    'context' => ['guardian_id' => (string) ($row->guardian_id ?? ''), 'relation_id' => (string) ($row->id ?? '')],
                ];
            }
        }

        if (data_get($schema, 'tables.familia_user')) {
            $familyRows = DB::table('familia_user')->get();
            foreach ($familyRows as $row) {
                if (! in_array((string) $row->user_id, $allUserIds, true)) {
                    $findings[] = [
                        'severity' => 'critical',
                        'code' => 'invalid_guardian_relation',
                        'user_id' => (string) ($row->user_id ?? ''),
                        'member_id' => null,
                        'name' => '',
                        'detail' => 'Membro de familia_user aponta para user inexistente.',
                        'actionable' => true,
                        'recommendation' => 'assign_guardian_after_review',
                        'context' => ['family_id' => (string) ($row->familia_id ?? ''), 'relation_id' => (string) ($row->id ?? '')],
                    ];
                }
            }
        }

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user)) {
                continue;
            }

            $userId = (string) $user->id;
            $context = $this->contextFor($contexts, $user);
            if ($this->isMinor($user) && ! $guardiansByChild->has($userId) && ! $this->hasFamilyGuardian($userId, $schema)) {
                $findings[] = $this->finding('warning', 'minor_without_guardian', $user, null, 'Menor sem encarregado de educação em user_guardian/familia_user.', 'assign_guardian_after_review', true, $context + [
                    'candidate_context' => $this->guardianCandidateContext($user, $schema),
                ]);
            }

            if ($this->looksLikeGuardian($user, $schema) && ! $childrenByGuardian->has($userId) && ! $this->hasFamilyDependent($userId, $schema)) {
                $findings[] = $this->finding('info', 'guardian_without_dependents', $user, null, 'Encarregado/responsável sem educandos associados.', 'assign_guardian_after_review', false, $context);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param array<string,Collection<int,object>> $rolesByUser
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function typeAndStatusFindings(Collection $users, array $rolesByUser, array $schema, array $contexts): array
    {
        $findings = [];
        $knownTypes = $this->knownUserTypes($schema);

        foreach ($users as $user) {
            $context = $this->contextFor($contexts, $user);
            $types = $context['access_roles'] ?? [];
            if ($types === []) {
                // A ausência de user_type é classificada em permissionFindings, onde há contexto de login/acesso.
            } elseif ($knownTypes !== []) {
                foreach ($types as $type) {
                    if (! in_array($this->normalize($type), $knownTypes, true)) {
                        $findings[] = $this->finding('warning', 'member_unknown_type', $user, null, sprintf('Tipo de membro desconhecido: %s.', $type), 'fix_member_type', true, $context);
                    }
                }
            }

            $status = $this->normalize((string) ($user->estado ?? ''));
            if ($status === '') {
                $findings[] = $this->finding('warning', 'member_missing_status', $user, null, 'Membro sem estado operacional.', 'fix_member_status', true, $context);
            } elseif (! in_array($status, self::KNOWN_STATUSES, true)) {
                $findings[] = $this->finding('warning', 'member_unknown_status', $user, null, sprintf('Estado desconhecido: %s.', (string) $user->estado), 'fix_member_status', true, $context);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param Collection<int,object> $sportsProfiles
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function sportsFindings(Collection $users, Collection $profiles, Collection $sportsProfiles, array $schema, array $contexts): array
    {
        $findings = [];
        $sportsByUser = $sportsProfiles->keyBy('user_id');

        foreach ($users as $user) {
            if (! $this->isActiveAthlete($user)) {
                continue;
            }

            $context = $this->contextFor($contexts, $user);
            if (data_get($schema, 'tables.athlete_sports_data') && ! $sportsByUser->has((string) $user->id)) {
                if ($this->requiresSportsProfile($context)) {
                    $findings[] = $this->finding('warning', 'active_athlete_without_sports_profile', $user, null, 'Atleta ativo com evidência operacional sem perfil desportivo canónico.', 'create_sports_profile_after_review', true, $context);
                } else {
                    $findings[] = $this->finding('info', 'athlete_sports_profile_pending_setup', $user, null, 'Atleta ativo sem perfil desportivo canónico; classificado como setup pendente/legacy sem impacto operacional confirmado.', 'no_action_needed_schema_limited_sports_check', false, $context);
                }
            }

            if ($this->birthdate($user, $profiles) === null) {
                $findings[] = $this->finding('warning', 'athlete_missing_birthdate', $user, null, 'Atleta ativo sem data de nascimento para escalão/validações desportivas.', 'create_sports_profile_after_review', true, $context);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $financialProfiles
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function financialFindings(Collection $users, Collection $financialProfiles, array $schema, array $contexts): array
    {
        $findings = [];
        $financialByUser = $financialProfiles->keyBy('user_id');

        foreach ($users as $user) {
            $context = $this->contextFor($contexts, $user);
            if ($this->isActiveAthlete($user)) {
                $financial = $financialByUser->get((string) $user->id);
                if (! $financial || $this->isBlank($financial->mensalidade_id ?? null)) {
                    if ($this->requiresFinancialSetup($context)) {
                        $findings[] = $this->finding('warning', 'active_athlete_without_financial_setup', $user, null, 'Atleta ativo com obrigação financeira clara sem configuração financeira mínima/mensalidade.', 'review_financial_setup', true, $context);
                    } else {
                        $findings[] = $this->finding('info', 'athlete_financial_setup_not_required_or_unknown', $user, null, 'Atleta ativo sem configuração financeira completa, mas sem obrigação financeira clara no schema/contexto atual.', 'no_action_needed_financial_setup_not_required_or_unknown', false, $context);
                    }
                }
            }

            if (! $this->isActiveStatus((string) ($user->estado ?? '')) && data_get($schema, 'tables.invoices')) {
                $activeMonthlyObligation = $this->hasActiveMonthlyObligation((string) $user->id, $schema);
                if ($activeMonthlyObligation === true) {
                    $findings[] = $this->finding('warning', 'inactive_member_with_active_financial_obligation', $user, null, 'Membro inativo/eliminado com obrigação financeira mensal ativa.', 'review_financial_setup', true, $context);
                } elseif ($activeMonthlyObligation === null) {
                    $findings[] = $this->finding('info', 'financial_profile_check_limited', $user, null, 'Verificação financeira limitada pelo schema real de invoices.', 'no_action_needed_schema_limited_financial_check', false, $context + [
                        'invoice_columns' => data_get($schema, 'invoice_columns', []),
                    ]);
                }
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param array<string,Collection<int,object>> $rolesByUser
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function permissionFindings(Collection $users, array $rolesByUser, array $schema, array $contexts): array
    {
        $findings = [];
        $userIds = $users->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        foreach ($users as $user) {
            $context = $this->contextFor($contexts, $user);
            if (data_get($schema, 'tables.user_user_type') && empty($rolesByUser[(string) $user->id])) {
                if ($this->expectsAccessRole($context)) {
                    if (in_array('admin_user', $context['functional_classification'] ?? [], true) || in_array('operational_user', $context['functional_classification'] ?? [], true)) {
                        $findings[] = $this->finding('warning', 'admin_or_operational_access_missing_role', $user, null, 'Utilizador administrativo/operacional com acesso esperado sem role técnica associada.', 'assign_access_role_after_review', true, $context);
                    } elseif ((bool) ($context['platform_access_granted'] ?? false)) {
                        $findings[] = $this->finding('warning', 'platform_access_granted_missing_role', $user, null, 'Pessoa com acesso à plataforma efetivamente concedido sem role técnica associada.', 'assign_access_role_after_review', true, $context);
                    } else {
                        $findings[] = $this->finding('warning', 'user_missing_role', $user, null, 'Pessoa com acesso à plataforma explicitamente configurado sem role técnica associada.', 'review_user_permissions', true, $context);
                    }
                } else {
                    if ((bool) ($context['portal_eligible'] ?? false)) {
                        $findings[] = $this->finding('info', 'portal_eligible_without_platform_access', $user, null, 'Pessoa/membro elegível para portal sem acesso à plataforma concedido; ausência de role é normal.', 'no_action_needed_portal_eligible_without_access', false, $context);
                    } else {
                        $findings[] = $this->finding('info', $this->noPlatformAccessFindingCode($context), $user, null, 'Pessoa/membro sem acesso à plataforma esperado; ausência de role é normal.', $this->noPlatformAccessRecommendation($context), false, $context);
                    }
                }
            } elseif (data_get($schema, 'tables.user_user_type') && ! empty($rolesByUser[(string) $user->id])) {
                $findings[] = $this->finding('info', 'platform_access_configured_clean', $user, null, 'Acesso à plataforma configurado por role técnica.', 'no_action_needed_access_clean', false, $context);
            }

            foreach (($rolesByUser[(string) $user->id] ?? collect()) as $role) {
                if ($this->isBlank($role->user_type_id ?? null) || $this->isBlank($role->nome ?? null)) {
                    $findings[] = $this->finding('critical', 'user_unknown_role', $user, null, 'Associação a user_type inexistente.', 'review_user_permissions', true, $context + ['user_type_id' => (string) ($role->user_type_id ?? '')]);
                }
            }
        }

        if (data_get($schema, 'tables.user_user_type')) {
            DB::table('user_user_type')->orderBy('user_id')->get()->each(function (object $row) use (&$findings, $userIds): void {
                if (! in_array((string) $row->user_id, $userIds, true) && ! DB::table('users')->where('id', $row->user_id)->exists()) {
                    $findings[] = [
                        'severity' => 'critical',
                        'code' => 'permission_orphan_user',
                        'user_id' => (string) ($row->user_id ?? ''),
                        'member_id' => null,
                        'name' => '',
                        'detail' => 'Permissão/user_type associado a user inexistente.',
                        'actionable' => true,
                        'recommendation' => 'review_user_permissions',
                        'context' => ['user_type_id' => (string) ($row->user_type_id ?? '')],
                    ];
                }
            });
        }

        if (data_get($schema, 'tables.user_type_permissions')) {
            DB::table('user_type_permissions')
                ->leftJoin('user_types', 'user_types.id', '=', 'user_type_permissions.user_type_id')
                ->select('user_type_permissions.*', 'user_types.id as existing_user_type_id')
                ->whereNull('user_types.id')
                ->get()
                ->each(function (object $row) use (&$findings): void {
                    $findings[] = [
                        'severity' => 'critical',
                        'code' => 'permission_orphan_user',
                        'user_id' => null,
                        'member_id' => null,
                        'name' => '',
                        'detail' => 'Permissão associada a user_type inexistente.',
                        'actionable' => true,
                        'recommendation' => 'review_user_permissions',
                        'context' => ['user_type_id' => (string) ($row->user_type_id ?? ''), 'permission_id' => (string) ($row->id ?? '')],
                    ];
                });
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private function appendCleanFindings(Collection $users, array $findings, array $contexts): array
    {
        $findingsByUser = collect($findings)->filter(static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))->groupBy('user_id');

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user) || $findingsByUser->has((string) $user->id)) {
                continue;
            }

            $findings[] = $this->finding('info', 'member_model_clean', $user, null, 'Modelo de membro coerente nas regras C1 aplicáveis.', 'no_action_needed_member_model_clean', false, $this->contextFor($contexts, $user));
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param Collection<int,object> $sportsProfiles
     * @param array<string,mixed> $schema
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(Collection $users, Collection $profiles, Collection $sportsProfiles, array $schema, array $findings, array $contexts): array
    {
        $byCode = collect($findings)->countBy('code');
        $contextCollection = collect($contexts);

        return [
            'total_users_scanned' => $users->count(),
            'total_members_scanned' => data_get($schema, 'tables.dados_pessoais') ? $profiles->count() : $users->count(),
            'total_athletes_scanned' => $users->filter(fn (object $user): bool => $this->isActiveAthlete($user))->count(),
            'total_guardians_scanned' => data_get($schema, 'tables.user_guardian') ? DB::table('user_guardian')->distinct()->count('guardian_id') : 0,
            'total_login_users_detected' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['access_expected'] ?? false))->count(),
            'total_member_only_users_detected' => $contextCollection->filter(fn (array $context): bool => in_array('member_profile', $context['functional_classification'] ?? [], true) && ! ($context['access_expected'] ?? false))->count(),
            'total_platform_access_expected' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['access_expected'] ?? false))->count(),
            'total_platform_access_configured' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['has_access_role'] ?? false))->count(),
            'total_no_platform_access_expected' => $contextCollection->filter(fn (array $context): bool => ! (bool) ($context['access_expected'] ?? false))->count(),
            'portal_eligible_count' => $contextCollection->where('portal_eligible', true)->count(),
            'portal_eligible_without_access_count' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['portal_eligible'] ?? false) && ! (bool) ($context['platform_access_granted'] ?? false))->count(),
            'platform_access_granted_count' => $contextCollection->where('platform_access_granted', true)->count(),
            'platform_access_granted_missing_role_count' => (int) ($byCode['platform_access_granted_missing_role'] ?? 0),
            'platform_access_expected_count' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['access_expected'] ?? false))->count(),
            'platform_access_expected_without_role_count' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['access_expected'] ?? false) && ! (bool) ($context['has_access_role'] ?? false))->count(),
            'total_admin_users_detected' => $contextCollection->filter(fn (array $context): bool => in_array('admin_user', $context['functional_classification'] ?? [], true))->count(),
            'total_guardian_profiles_detected' => $contextCollection->filter(fn (array $context): bool => in_array('guardian_profile', $context['functional_classification'] ?? [], true))->count(),
            'total_athlete_profiles_detected' => $contextCollection->filter(fn (array $context): bool => in_array('athlete_profile', $context['functional_classification'] ?? [], true))->count(),
            'duplicate_identity_count' => (int) (($byCode['duplicate_user_email'] ?? 0) + ($byCode['duplicate_member_identifier'] ?? 0)),
            'invalid_relation_count' => (int) (($byCode['member_user_link_invalid'] ?? 0) + ($byCode['invalid_guardian_relation'] ?? 0)),
            'missing_required_identity_count' => (int) ($byCode['user_missing_required_identity'] ?? 0),
            'invalid_birthdate_count' => (int) (($byCode['birthdate_invalid_format'] ?? 0) + ($byCode['birthdate_year_out_of_range'] ?? 0) + ($byCode['birthdate_future'] ?? 0) + ($byCode['birthdate_age_unrealistic'] ?? 0)),
            'placeholder_birthdate_count' => (int) ($byCode['administrative_placeholder_birthdate'] ?? 0),
            'minor_without_guardian_count' => (int) ($byCode['minor_without_guardian'] ?? 0),
            'member_type_issue_count' => (int) (($byCode['member_missing_type'] ?? 0) + ($byCode['member_unknown_type'] ?? 0)),
            'member_status_issue_count' => (int) (($byCode['member_missing_status'] ?? 0) + ($byCode['member_unknown_status'] ?? 0)),
            'sports_profile_issue_count' => (int) (($byCode['active_athlete_without_sports_profile'] ?? 0) + ($byCode['athlete_missing_birthdate'] ?? 0)),
            'financial_profile_issue_count' => (int) (($byCode['active_athlete_without_financial_setup'] ?? 0) + ($byCode['inactive_member_with_active_financial_obligation'] ?? 0) + ($byCode['financial_profile_check_limited'] ?? 0)),
            'permission_issue_count' => (int) (($byCode['platform_access_granted_missing_role'] ?? 0) + ($byCode['admin_or_operational_access_missing_role'] ?? 0) + ($byCode['user_missing_role'] ?? 0) + ($byCode['user_unknown_role'] ?? 0) + ($byCode['permission_orphan_user'] ?? 0)),
            'login_credentials_detected_count' => $contextCollection->where('has_login_credentials', true)->count(),
            'login_identifier_only_count' => $contextCollection->filter(fn (array $context): bool => (bool) ($context['has_login_identifier'] ?? false) && ! (bool) ($context['has_login_credentials'] ?? false) && ! (bool) ($context['has_access_role'] ?? false))->count(),
            'access_role_missing_count' => (int) (($byCode['platform_access_granted_missing_role'] ?? 0) + ($byCode['user_missing_role'] ?? 0) + ($byCode['admin_or_operational_access_missing_role'] ?? 0)),
            'admin_access_role_issue_count' => (int) ($byCode['admin_or_operational_access_missing_role'] ?? 0),
            'admin_or_operational_access_missing_role_count' => (int) ($byCode['admin_or_operational_access_missing_role'] ?? 0),
            'platform_access_issue_count' => (int) (($byCode['platform_access_granted_missing_role'] ?? 0) + ($byCode['user_missing_role'] ?? 0) + ($byCode['admin_or_operational_access_missing_role'] ?? 0) + ($byCode['user_unknown_role'] ?? 0) + ($byCode['permission_orphan_user'] ?? 0)),
            'member_no_access_expected_count' => $contextCollection->filter(fn (array $context): bool => in_array('member_profile', $context['functional_classification'] ?? [], true) && ! (bool) ($context['access_expected'] ?? false))->count(),
            'athlete_no_access_expected_count' => $contextCollection->filter(fn (array $context): bool => in_array('athlete_profile', $context['functional_classification'] ?? [], true) && ! (bool) ($context['access_expected'] ?? false))->count(),
            'guardian_no_access_expected_count' => $contextCollection->filter(fn (array $context): bool => in_array('guardian_profile', $context['functional_classification'] ?? [], true) && ! (bool) ($context['access_expected'] ?? false))->count(),
            'portal_eligible_without_platform_access_count' => (int) ($byCode['portal_eligible_without_platform_access'] ?? 0),
            'member_without_platform_access_expected_count' => (int) ($byCode['member_without_platform_access_expected'] ?? 0),
            'athlete_without_platform_access_expected_count' => (int) ($byCode['athlete_without_platform_access_expected'] ?? 0),
            'guardian_without_platform_access_expected_count' => (int) ($byCode['guardian_without_platform_access_expected'] ?? 0),
            'member_without_access_role_expected_count' => (int) (($byCode['member_without_access_role_expected'] ?? 0) + ($byCode['member_without_platform_access_expected'] ?? 0) + ($byCode['athlete_without_platform_access_expected'] ?? 0) + ($byCode['guardian_without_platform_access_expected'] ?? 0)),
            'member_without_login_role_expected_count' => (int) (($byCode['member_without_login_role_expected'] ?? 0) + ($byCode['member_without_access_role_expected'] ?? 0) + ($byCode['member_without_platform_access_expected'] ?? 0) + ($byCode['athlete_without_platform_access_expected'] ?? 0) + ($byCode['guardian_without_platform_access_expected'] ?? 0)),
            'sports_profile_pending_setup_count' => (int) ($byCode['athlete_sports_profile_pending_setup'] ?? 0),
            'financial_setup_not_required_or_unknown_count' => (int) ($byCode['athlete_financial_setup_not_required_or_unknown'] ?? 0),
            'suspected_false_positive_reclassified_count' => (int) (($byCode['member_without_login_role_expected'] ?? 0) + ($byCode['member_without_access_role_expected'] ?? 0) + ($byCode['member_without_platform_access_expected'] ?? 0) + ($byCode['athlete_without_platform_access_expected'] ?? 0) + ($byCode['guardian_without_platform_access_expected'] ?? 0) + ($byCode['athlete_sports_profile_pending_setup'] ?? 0) + ($byCode['athlete_financial_setup_not_required_or_unknown'] ?? 0)),
            'total_findings' => count($findings),
            'critical_count' => collect($findings)->where('severity', 'critical')->count(),
            'warning_count' => collect($findings)->where('severity', 'warning')->count(),
            'info_count' => collect($findings)->where('severity', 'info')->count(),
            'actionable_count' => collect($findings)->where('actionable', true)->count(),
        ];
    }

    /**
     * @param Collection<int,object> $users
     * @return array<int,array<string,mixed>>
     */
    private function duplicateFindings(Collection $users, string $column, string $code, string $detail, string $recommendation, array $contexts): array
    {
        $findings = [];
        $users->filter(fn (object $user): bool => ! $this->isBlank($user->{$column} ?? null))
            ->groupBy(fn (object $user): string => $this->normalize((string) $user->{$column}))
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $code, $detail, $recommendation, $contexts): void {
                foreach ($group as $user) {
                    $findings[] = $this->finding('critical', $code, $user, null, $detail, $recommendation, true, $this->contextFor($contexts, $user));
                }
            });

        return $findings;
    }

    /**
     * @param Collection<int,object> $profiles
     * @return array<int,array<string,mixed>>
     */
    private function duplicateProfileFindings(Collection $profiles, string $column, string $code, string $detail): array
    {
        $findings = [];
        $profiles->filter(fn (object $profile): bool => ! $this->isBlank($profile->{$column} ?? null))
            ->groupBy(fn (object $profile): string => $this->normalize((string) $profile->{$column}))
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $code, $detail): void {
                foreach ($group as $profile) {
                    $findings[] = $this->profileFinding('critical', $code, $profile, $detail, 'merge_duplicate_member_after_review', true);
                }
            });

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @return array<int,array<string,mixed>>
     */
    private function duplicateCompositeIdentityFindings(Collection $users, Collection $profiles, array $contexts): array
    {
        $profilesByUser = $profiles->keyBy('user_id');
        $findings = [];

        $users->map(function (object $user) use ($profilesByUser, $profiles): array {
            $profile = $profilesByUser->get((string) $user->id);

            return [
                'user' => $user,
                'key' => implode('|', [
                    $this->normalize($this->displayName($user, $profiles)),
                    $this->formatDate($this->birthdate($user, $profiles)),
                    $this->normalize((string) ($profile->nif ?? '')),
                ]),
            ];
        })
            ->filter(static fn (array $item): bool => trim((string) $item['key'], '|') !== '')
            ->groupBy('key')
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings): void {
                foreach ($group as $item) {
                    $findings[] = $this->finding('critical', 'duplicate_member_identifier', $item['user'], null, 'Possível duplicado por nome+data nascimento+NIF.', 'merge_duplicate_member_after_review', true, $this->contextFor($contexts, $item['user']));
                }
            });

        return $findings;
    }

    /**
     * @return array<int,string>
     */
    private function memberFunctionalTypes(object $user, ?object $profile): array
    {
        $types = array_merge(
            $this->rawMemberTypes($user),
            array_filter([
                (string) ($profile->tipo_utilizador ?? ''),
                (string) ($user->perfil ?? ''),
            ], fn (string $type): bool => ! $this->isBlank($type)),
        );

        return array_values(array_unique(array_filter($types, fn (string $type): bool => ! $this->isBlank($type))));
    }

    /**
     * @param Collection<int,object> $roles
     * @return array<int,string>
     */
    private function accessRoles(Collection $roles): array
    {
        $types = [];
        foreach ($roles as $role) {
            $types[] = (string) (($role->codigo ?? null) ?: ($role->nome ?? ''));
        }

        return array_values(array_unique(array_filter($types, fn (string $type): bool => ! $this->isBlank($type))));
    }

    private function noPlatformAccessFindingCode(array $context): string
    {
        $classifications = $context['functional_classification'] ?? [];

        if (in_array('athlete_profile', $classifications, true)) {
            return 'athlete_without_platform_access_expected';
        }

        if (in_array('guardian_profile', $classifications, true)) {
            return 'guardian_without_platform_access_expected';
        }

        return 'member_without_platform_access_expected';
    }

    private function noPlatformAccessRecommendation(array $context): string
    {
        return match ($this->noPlatformAccessFindingCode($context)) {
            'athlete_without_platform_access_expected' => 'no_action_needed_athlete_without_platform_access',
            'guardian_without_platform_access_expected' => 'no_action_needed_guardian_without_platform_access',
            default => 'no_action_needed_member_without_platform_access',
        };
    }

    private function platformAccessInspectionRecommendation(array $context): string
    {
        if ((bool) ($context['has_access_role'] ?? false)) {
            return 'no_action_needed_access_clean';
        }

        if ((bool) ($context['access_role_missing_is_problem'] ?? false)) {
            return 'assign_access_role_after_review';
        }

        if ((bool) ($context['portal_eligible'] ?? false)) {
            return 'no_action_needed_portal_eligible_without_access';
        }

        return $this->noPlatformAccessRecommendation($context);
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<int,string>
     */
    private function knownUserTypes(array $schema): array
    {
        if (! data_get($schema, 'tables.user_types')) {
            return [];
        }

        return DB::table('user_types')
            ->get(['codigo', 'nome'])
            ->flatMap(fn (object $row): array => [$this->normalize((string) ($row->codigo ?? '')), $this->normalize((string) ($row->nome ?? ''))])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param Collection<int,object> $sportsProfiles
     * @param Collection<int,object> $financialProfiles
     * @param Collection<int,object> $configurationProfiles
     * @param array<string,Collection<int,object>> $rolesByUser
     * @param array<string,mixed> $schema
     * @return array<string,array<string,mixed>>
     */
    private function functionalContexts(Collection $users, Collection $profiles, Collection $sportsProfiles, Collection $financialProfiles, Collection $configurationProfiles, array $rolesByUser, array $schema): array
    {
        $profilesByUser = $profiles->keyBy('user_id');
        $sportsByUser = $sportsProfiles->keyBy('user_id');
        $financialByUser = $financialProfiles->keyBy('user_id');
        $configurationByUser = $configurationProfiles->keyBy('user_id');
        $contexts = [];

        foreach ($users as $user) {
            $userId = (string) $user->id;
            $roles = $rolesByUser[$userId] ?? collect();
            $profile = $profilesByUser->get($userId);
            $financial = $financialByUser->get($userId);
            $configuration = $configurationByUser->get($userId);
            $memberFunctionalTypes = $this->memberFunctionalTypes($user, $profile);
            $accessRoles = $this->accessRoles($roles);
            $normalizedFunctionalTypes = array_values(array_unique(array_filter(array_map(fn (string $type): string => $this->normalize($type), $memberFunctionalTypes))));
            $normalizedAccessRoles = array_values(array_unique(array_filter(array_map(fn (string $type): string => $this->normalize($type), $accessRoles))));
            $perfil = $this->normalize((string) ($user->perfil ?? ''));
            $birthdate = $this->birthdateInfo($user, $profiles);
            $hasAccessRole = $roles->isNotEmpty();
            $isAdmin = $this->isAdministrativeUser($user);
            $isOperational = in_array($perfil, self::OPERATIONAL_PROFILES, true) || collect($normalizedFunctionalTypes)->contains(fn (string $type): bool => in_array($type, self::OPERATIONAL_PROFILES, true)) || collect($normalizedAccessRoles)->contains(fn (string $type): bool => in_array($type, self::OPERATIONAL_PROFILES, true));
            $hasLoginIdentifier = $this->hasLoginIdentifier($user);
            $hasLoginCredentials = $this->hasLoginCredentials($user, $schema, $hasAccessRole);
            $accessExpectation = $this->platformAccessExpectation($user, $configuration, $schema, $hasAccessRole, $isAdmin, $isOperational);
            $hasLogin = (bool) $accessExpectation['platform_access_granted'] || (bool) $accessExpectation['access_expected'];
            $hasGuardianRelation = $this->hasGuardianRelation($userId, $schema);
            $hasDependents = $this->hasAnyDependent($userId, $schema);
            $hasAthlete = $this->isActiveAthlete($user) || collect($normalizedFunctionalTypes)->contains(fn (string $type): bool => in_array($type, self::ATHLETE_MARKERS, true));
            $isGuardian = $this->looksLikeGuardian($user, $schema) || $hasDependents;
            $isLegacy = ! $hasLogin && ! $hasAccessRole && ($profile !== null || ! $this->isBlank($user->numero_socio ?? null));
            $hasMonthlyInvoice = $this->hasActiveMonthlyObligation($userId, $schema) === true;
            $sportsRequirement = $this->sportsProfileRequirement($user, $isAdmin, $isOperational, $isLegacy);

            $classification = ['person_record'];
            if ($profile !== null) {
                $classification[] = 'member_profile';
            }
            if ($hasAthlete) {
                $classification[] = 'athlete_profile';
            }
            if ($isGuardian || $hasGuardianRelation) {
                $classification[] = 'guardian_profile';
            }
            if ($hasLogin) {
                $classification[] = 'login_user';
            }
            if ($isAdmin) {
                $classification[] = 'admin_user';
            }
            if ($isOperational) {
                $classification[] = 'operational_user';
            }
            if ($isLegacy) {
                $classification[] = 'legacy_imported_member';
            }
            if ($classification === ['person_record']) {
                $classification[] = 'unknown_profile';
            }

            $contexts[$userId] = [
                'functional_classification' => array_values(array_unique($classification)),
                'has_login' => $hasLogin,
                'has_user_type' => $hasAccessRole,
                'user_types' => $accessRoles,
                'member_functional_types' => $memberFunctionalTypes,
                'access_roles' => $accessRoles,
                'has_member_functional_type' => $memberFunctionalTypes !== [],
                'has_access_role' => $hasAccessRole,
                'has_login_credentials' => $hasLoginCredentials,
                'has_login_identifier' => $hasLoginIdentifier,
                'login_expected' => (bool) $accessExpectation['access_expected'],
                'login_reason' => $accessExpectation['access_expected_reason'],
                'permission_required' => (bool) $accessExpectation['access_role_required'],
                'permission_reason' => $accessExpectation['access_role_required_reason'],
                'portal_eligible' => (bool) $accessExpectation['portal_eligible'],
                'portal_profile_enabled' => (bool) $accessExpectation['portal_profile_enabled'],
                'platform_access_granted' => (bool) $accessExpectation['platform_access_granted'],
                'platform_access_granted_reason' => $accessExpectation['platform_access_granted_reason'],
                'platform_access_expected' => (bool) $accessExpectation['access_expected'],
                'platform_access_expected_reason' => $accessExpectation['access_expected_reason'],
                'access_expected' => (bool) $accessExpectation['access_expected'],
                'access_expected_reason' => $accessExpectation['access_expected_reason'],
                'access_role_required' => (bool) $accessExpectation['access_role_required'],
                'access_role_required_reason' => $accessExpectation['access_role_required_reason'],
                'access_role_missing_is_problem' => (bool) $accessExpectation['access_role_missing_is_problem'],
                'access_role_missing_reason' => $accessExpectation['access_role_missing_reason'],
                'platform_access_status' => $accessExpectation['platform_access_status'],
                'portal_access_active' => $accessExpectation['portal_access_active'],
                'last_access_email_sent_at' => $accessExpectation['last_access_email_sent_at'],
                'perfil' => (string) ($user->perfil ?? ''),
                'tipo_membro' => $this->rawMemberTypes($user),
                'estado' => (string) ($user->estado ?? ''),
                'menor' => $this->isMinor($user),
                'idade' => $birthdate['age'],
                'data_nascimento' => $birthdate['date'],
                'birthdate' => $birthdate,
                'ativo_desportivo' => (bool) ($user->ativo_desportivo ?? false),
                'sports_profile_required' => (bool) $sportsRequirement['required'],
                'sports_profile_reason' => $sportsRequirement['reason'],
                'has_dados_pessoais' => $profile !== null,
                'has_dados_financeiros' => $financial !== null,
                'has_athlete_sports_data' => $sportsByUser->has($userId),
                'has_guardian_relation' => $hasGuardianRelation,
                'has_dependents' => $hasDependents,
                'has_open_monthly_invoice' => $hasMonthlyInvoice,
                'has_cost_center' => $this->hasCostCenter($userId, $schema),
            ];
        }

        return $contexts;
    }

    /**
     * @param array<string,array<string,mixed>> $contexts
     * @return array<string,mixed>
     */
    private function contextFor(array $contexts, object $user): array
    {
        return $contexts[(string) ($user->id ?? '')] ?? [
            'functional_classification' => ['unknown_profile'],
            'has_login' => false,
            'has_user_type' => false,
            'user_types' => [],
            'member_functional_types' => [],
            'access_roles' => [],
            'has_member_functional_type' => false,
            'has_access_role' => false,
            'has_login_credentials' => false,
            'has_login_identifier' => false,
            'permission_required' => false,
            'portal_eligible' => false,
            'portal_profile_enabled' => false,
            'platform_access_granted' => false,
            'platform_access_granted_reason' => 'unknown_context',
            'platform_access_expected' => false,
            'platform_access_expected_reason' => 'unknown_context',
            'access_expected' => false,
            'access_expected_reason' => 'unknown_context',
            'access_role_required' => false,
            'access_role_required_reason' => 'unknown_context',
            'access_role_missing_is_problem' => false,
            'access_role_missing_reason' => 'unknown_context',
            'platform_access_status' => 'unknown',
        ];
    }

    private function hasLoginIdentifier(object $user): bool
    {
        return ! $this->isBlank($user->email_utilizador ?? null) || ! $this->isBlank($user->email ?? null);
    }

    private function hasLoginCredentials(object $user, array $schema, bool $hasAccessRole): bool
    {
        if (data_get($schema, 'users_columns.password') && ! $this->isBlank($user->password ?? null)) {
            return true;
        }

        return data_get($schema, 'users_columns.last_login_at') && ! $this->isBlank($user->last_login_at ?? null);
    }

    /**
     * @return array{portal_eligible:bool,portal_profile_enabled:bool,platform_access_granted:bool,platform_access_granted_reason:string,access_expected:bool,access_expected_reason:string,access_role_required:bool,access_role_required_reason:string,access_role_missing_is_problem:bool,access_role_missing_reason:string,platform_access_status:string,portal_access_active:?bool,last_access_email_sent_at:?string}
     */
    private function platformAccessExpectation(object $user, ?object $configuration, array $schema, bool $hasAccessRole, bool $isAdmin, bool $isOperational): array
    {
        $isActive = $this->isActiveStatus((string) ($user->estado ?? ''));
        $portalAccessActive = data_get($schema, 'dados_configuracao_columns.acesso_portal_ativo')
            ? $this->nullableBoolean($configuration->acesso_portal_ativo ?? null)
            : null;
        $portalEligible = $portalAccessActive === true;
        $lastAccessEmailSentAt = $this->firstFilledColumnValue($configuration, self::INVITE_COLUMNS, data_get($schema, 'dados_configuracao_columns', []))
            ?? $this->firstFilledColumnValue($user, self::INVITE_COLUMNS, data_get($schema, 'users_columns', []));
        $explicitGrantedColumn = $this->firstTrueColumn($configuration, self::ACCESS_GRANTED_COLUMNS, data_get($schema, 'dados_configuracao_columns', []))
            ?? $this->firstTrueColumn($user, self::ACCESS_GRANTED_COLUMNS, data_get($schema, 'users_columns', []));
        $loginActivityColumn = $this->firstFilledColumnName($user, ['last_login_at', 'last_seen_at', 'login_at'], data_get($schema, 'users_columns', []));
        $platformAccessGranted = $hasAccessRole || $lastAccessEmailSentAt !== null || $explicitGrantedColumn !== null || $loginActivityColumn !== null;
        $platformAccessGrantedReason = 'no_granted_access_evidence';
        if ($hasAccessRole) {
            $platformAccessGrantedReason = 'technical_access_role_configured';
        } elseif ($explicitGrantedColumn !== null) {
            $platformAccessGrantedReason = 'explicit_access_granted_column_'.$explicitGrantedColumn;
        } elseif ($lastAccessEmailSentAt !== null) {
            $platformAccessGrantedReason = 'access_invitation_or_acceptance_recorded';
        } elseif ($loginActivityColumn !== null) {
            $platformAccessGrantedReason = 'login_activity_recorded_'.$loginActivityColumn;
        }

        if ($hasAccessRole) {
            return [
                'portal_eligible' => $portalEligible,
                'portal_profile_enabled' => $portalEligible,
                'platform_access_granted' => true,
                'platform_access_granted_reason' => $platformAccessGrantedReason,
                'access_expected' => true,
                'access_expected_reason' => 'technical_access_role_configured',
                'access_role_required' => false,
                'access_role_required_reason' => 'technical_access_role_present',
                'access_role_missing_is_problem' => false,
                'access_role_missing_reason' => 'technical_access_role_present',
                'platform_access_status' => 'access_configured',
                'portal_access_active' => $portalAccessActive,
                'last_access_email_sent_at' => $lastAccessEmailSentAt,
            ];
        }

        if ($isActive && ($isAdmin || $isOperational)) {
            return [
                'portal_eligible' => $portalEligible,
                'portal_profile_enabled' => $portalEligible,
                'platform_access_granted' => $platformAccessGranted,
                'platform_access_granted_reason' => $platformAccessGrantedReason,
                'access_expected' => true,
                'access_expected_reason' => $isAdmin ? 'active_admin_profile_requires_platform_access' : 'active_operational_profile_requires_platform_access',
                'access_role_required' => true,
                'access_role_required_reason' => $isAdmin ? 'active_admin_profile_missing_access_role' : 'active_operational_profile_missing_access_role',
                'access_role_missing_is_problem' => true,
                'access_role_missing_reason' => $isAdmin ? 'active_admin_profile_missing_access_role' : 'active_operational_profile_missing_access_role',
                'platform_access_status' => 'admin_access_expected',
                'portal_access_active' => $portalAccessActive,
                'last_access_email_sent_at' => $lastAccessEmailSentAt,
            ];
        }

        if ($platformAccessGranted) {
            return [
                'portal_eligible' => $portalEligible,
                'portal_profile_enabled' => $portalEligible,
                'platform_access_granted' => true,
                'platform_access_granted_reason' => $platformAccessGrantedReason,
                'access_expected' => true,
                'access_expected_reason' => $platformAccessGrantedReason,
                'access_role_required' => true,
                'access_role_required_reason' => 'platform_access_granted_without_access_role',
                'access_role_missing_is_problem' => true,
                'access_role_missing_reason' => 'platform_access_granted_without_access_role',
                'platform_access_status' => 'access_granted_missing_role',
                'portal_access_active' => $portalAccessActive,
                'last_access_email_sent_at' => $lastAccessEmailSentAt,
            ];
        }

        return [
            'portal_eligible' => $portalEligible,
            'portal_profile_enabled' => $portalEligible,
            'platform_access_granted' => false,
            'platform_access_granted_reason' => $platformAccessGrantedReason,
            'access_expected' => false,
            'access_expected_reason' => $portalEligible ? 'portal_eligible_without_granted_access' : ($isActive ? 'no_explicit_platform_access_configured' : 'inactive_record_without_explicit_platform_access'),
            'access_role_required' => false,
            'access_role_required_reason' => 'no_access_role_required_without_explicit_platform_access',
            'access_role_missing_is_problem' => false,
            'access_role_missing_reason' => $portalEligible ? 'portal_eligible_is_not_granted_access' : 'no_access_role_required_without_explicit_platform_access',
            'platform_access_status' => $portalEligible ? 'portal_eligible_without_access' : 'no_access_expected',
            'portal_access_active' => $portalAccessActive,
            'last_access_email_sent_at' => $lastAccessEmailSentAt,
        ];
    }

    private function expectsAccessRole(array $context): bool
    {
        return (bool) ($context['permission_required'] ?? false);
    }

    private function requiresSportsProfile(array $context): bool
    {
        return (bool) ($context['sports_profile_required'] ?? false);
    }

    /**
     * @return array{required:bool,reason:string}
     */
    private function sportsProfileRequirement(object $user, bool $isAdmin, bool $isOperational, bool $isLegacy): array
    {
        if (! (bool) ($user->ativo_desportivo ?? false)) {
            return ['required' => false, 'reason' => 'not_sport_active'];
        }

        if ($isAdmin || $isOperational) {
            return ['required' => false, 'reason' => 'administrative_or_operational_profile'];
        }

        if ($isLegacy) {
            return ['required' => false, 'reason' => 'legacy_imported_member_without_confirmed_operational_access'];
        }

        return ['required' => true, 'reason' => 'sport_active_requires_sports_profile'];
    }

    private function requiresFinancialSetup(array $context): bool
    {
        if (! (bool) ($context['ativo_desportivo'] ?? false)) {
            return false;
        }

        $types = collect($context['member_functional_types'] ?? $context['tipo_membro'] ?? [])
            ->map(fn (mixed $type): string => $this->normalize((string) $type));
        if ($types->contains(fn (string $type): bool => str_contains($type, 'isento') || str_contains($type, 'experimental') || str_contains($type, 'master') || str_contains($type, 'admin'))) {
            return false;
        }

        return (bool) ($context['has_open_monthly_invoice'] ?? false)
            || (bool) ($context['has_cost_center'] ?? false);
    }

    private function rawMemberTypes(object $user): array
    {
        $raw = $user->tipo_membro ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }

        return array_values(array_filter(array_map('strval', is_array($raw) ? $raw : [])));
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasGuardianRelation(string $userId, array $schema): bool
    {
        if (data_get($schema, 'tables.user_guardian') && DB::table('user_guardian')->where('user_id', $userId)->exists()) {
            return true;
        }

        return $this->hasFamilyGuardian($userId, $schema);
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasAnyDependent(string $userId, array $schema): bool
    {
        if (data_get($schema, 'tables.user_guardian') && DB::table('user_guardian')->where('guardian_id', $userId)->exists()) {
            return true;
        }

        return $this->hasFamilyDependent($userId, $schema);
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasCostCenter(string $userId, array $schema): bool
    {
        if (data_get($schema, 'tables.centro_custo_user') && DB::table('centro_custo_user')->where('user_id', $userId)->exists()) {
            return true;
        }

        return data_get($schema, 'financial_sources.users_centro_custo_legacy') && ! $this->isBlank(DB::table('users')->where('id', $userId)->value('centro_custo'));
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function guardianCandidateContext(object $user, array $schema): array
    {
        if (! data_get($schema, 'tables.users')) {
            return ['possible_family_name_matches' => 0];
        }

        $name = trim((string) ($user->nome_completo ?? $user->name ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $surname = $parts !== [] ? end($parts) : '';
        if (! is_string($surname) || mb_strlen($surname) < 3) {
            return ['possible_family_name_matches' => 0];
        }

        return [
            'possible_family_name_matches' => DB::table('users')
                ->where('id', '!=', $user->id)
                ->where(function ($query) use ($surname): void {
                    $query->where('name', 'like', '%'.$surname.'%')
                        ->orWhere('nome_completo', 'like', '%'.$surname.'%');
                })
                ->count(),
        ];
    }

    /**
     * @param Collection<int,object> $profiles
     */
    private function displayName(object $user, Collection $profiles): string
    {
        $profile = $this->profileForUser($profiles, (string) $user->id);

        return (string) $this->firstFilled($profile->nome_completo ?? null, $user->nome_completo ?? null, $user->name ?? '');
    }

    /**
     * @param Collection<int,object> $profiles
     */
    private function birthdate(object $user, Collection $profiles): ?CarbonImmutable
    {
        $info = $this->birthdateInfo($user, $profiles);

        if ($info['status'] !== 'valid' && $info['status'] !== 'administrative_placeholder') {
            return null;
        }

        return $info['parsed'];
    }

    /**
     * @param Collection<int,object> $profiles
     * @return array{raw:?string,date:?string,parsed:?CarbonImmutable,status:string,age:?int,reason:string}
     */
    private function birthdateInfo(object $user, Collection $profiles): array
    {
        $profile = $this->profileForUser($profiles, (string) $user->id);
        $value = $this->firstFilled($profile->data_nascimento ?? null, $user->data_nascimento ?? null);
        if ($value === null) {
            return ['raw' => null, 'date' => null, 'parsed' => null, 'status' => 'missing', 'age' => null, 'reason' => 'birthdate_missing'];
        }

        $raw = trim((string) $value);
        try {
            $birthdate = CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return ['raw' => $raw, 'date' => null, 'parsed' => null, 'status' => 'invalid_format', 'age' => null, 'reason' => 'unparseable_birthdate'];
        }

        if ($birthdate->toDateString() === '1900-01-01' && $this->isAdministrativeUser($user)) {
            return ['raw' => $raw, 'date' => $birthdate->toDateString(), 'parsed' => $birthdate, 'status' => 'administrative_placeholder', 'age' => $birthdate->age, 'reason' => 'administrative_placeholder_birthdate'];
        }

        if ($birthdate->year < 1900) {
            return ['raw' => $raw, 'date' => $birthdate->toDateString(), 'parsed' => $birthdate, 'status' => 'year_out_of_range', 'age' => $birthdate->age, 'reason' => 'birthdate_year_before_1900'];
        }

        if ($birthdate->isFuture()) {
            return ['raw' => $raw, 'date' => $birthdate->toDateString(), 'parsed' => $birthdate, 'status' => 'future', 'age' => null, 'reason' => 'birthdate_in_future'];
        }

        if ($birthdate->age > 110) {
            return ['raw' => $raw, 'date' => $birthdate->toDateString(), 'parsed' => $birthdate, 'status' => 'age_unrealistic', 'age' => $birthdate->age, 'reason' => 'birthdate_age_over_110'];
        }

        return ['raw' => $raw, 'date' => $birthdate->toDateString(), 'parsed' => $birthdate, 'status' => 'valid', 'age' => $birthdate->age, 'reason' => 'valid_birthdate'];
    }

    /**
     * @param Collection<int,object> $profiles
     */
    private function profileForUser(Collection $profiles, string $userId): ?object
    {
        return $profiles->firstWhere('user_id', $userId);
    }

    private function isMinor(object $user): bool
    {
        if ((bool) ($user->menor ?? false)) {
            return true;
        }

        if ($this->isBlank($user->data_nascimento ?? null)) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $user->data_nascimento)->age < 18;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isActiveAthlete(object $user): bool
    {
        if (! $this->isActiveStatus((string) ($user->estado ?? ''))) {
            return false;
        }

        if ((bool) ($user->ativo_desportivo ?? false)) {
            return true;
        }

        $perfil = $this->normalize((string) ($user->perfil ?? ''));
        if (in_array($perfil, self::ATHLETE_MARKERS, true)) {
            return true;
        }

        $rawTypes = $user->tipo_membro ?? null;
        if (is_string($rawTypes)) {
            $decoded = json_decode($rawTypes, true);
            $rawTypes = is_array($decoded) ? $decoded : [$rawTypes];
        }

        return collect(is_array($rawTypes) ? $rawTypes : [])
            ->map(fn (mixed $type): string => $this->normalize((string) $type))
            ->contains(fn (string $type): bool => in_array($type, self::ATHLETE_MARKERS, true));
    }

    private function isAdministrativeUser(object $user): bool
    {
        $perfil = $this->normalize((string) ($user->perfil ?? ''));
        if (in_array($perfil, self::ADMIN_PROFILES, true)) {
            return true;
        }

        $rawTypes = $user->tipo_membro ?? null;
        if (is_string($rawTypes)) {
            $decoded = json_decode($rawTypes, true);
            $rawTypes = is_array($decoded) ? $decoded : [$rawTypes];
        }

        return collect(is_array($rawTypes) ? $rawTypes : [])
            ->map(fn (mixed $type): string => $this->normalize((string) $type))
            ->contains(fn (string $type): bool => in_array($type, self::ADMIN_PROFILES, true));
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function looksLikeGuardian(object $user, array $schema): bool
    {
        $text = $this->normalize(implode(' ', [
            (string) ($user->perfil ?? ''),
            is_array($user->tipo_membro ?? null) ? implode(' ', $user->tipo_membro) : (string) ($user->tipo_membro ?? ''),
        ]));

        if (collect(self::GUARDIAN_MARKERS)->contains(fn (string $marker): bool => str_contains($text, $marker))) {
            return true;
        }

        if (data_get($schema, 'tables.familia_user')) {
            return DB::table('familia_user')
                ->where('user_id', $user->id)
                ->whereIn('papel_na_familia', ['responsavel', 'responsável', 'encarregado', 'guardian'])
                ->exists();
        }

        return false;
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasFamilyGuardian(string $userId, array $schema): bool
    {
        if (! data_get($schema, 'tables.familia_user')) {
            return false;
        }

        $familyIds = DB::table('familia_user')->where('user_id', $userId)->pluck('familia_id');
        if ($familyIds->isEmpty()) {
            return false;
        }

        return DB::table('familia_user')
            ->whereIn('familia_id', $familyIds)
            ->where('user_id', '!=', $userId)
            ->whereIn('papel_na_familia', ['responsavel', 'responsável', 'encarregado', 'guardian'])
            ->exists();
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasFamilyDependent(string $userId, array $schema): bool
    {
        if (! data_get($schema, 'tables.familia_user')) {
            return false;
        }

        $familyIds = DB::table('familia_user')->where('user_id', $userId)->pluck('familia_id');
        if ($familyIds->isEmpty()) {
            return false;
        }

        return DB::table('familia_user')
            ->whereIn('familia_id', $familyIds)
            ->where('user_id', '!=', $userId)
            ->whereIn('papel_na_familia', ['educando', 'dependent', 'dependente'])
            ->exists();
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function hasActiveMonthlyObligation(string $userId, array $schema): ?bool
    {
        $columns = data_get($schema, 'invoice_columns', []);
        if (! data_get($schema, 'tables.invoices') || ! ($columns['user_id'] ?? false)) {
            return null;
        }

        $hasMonthlyTypeFilter = (bool) (($columns['tipo'] ?? false) || ($columns['origem_tipo'] ?? false));
        $hasPaymentStateFilter = (bool) (($columns['estado_pagamento'] ?? false) || ($columns['valor_em_aberto'] ?? false));

        if (! $hasMonthlyTypeFilter || ! $hasPaymentStateFilter) {
            return null;
        }

        $query = DB::table('invoices')->where('user_id', $userId);

        $query->where(function ($subQuery) use ($columns): void {
            if ($columns['tipo'] ?? false) {
                $subQuery->orWhere('tipo', 'mensalidade');
            }
            if ($columns['origem_tipo'] ?? false) {
                $subQuery->orWhereIn('origem_tipo', ['monthly_fee', 'monthly_fee_legacy']);
            }
        });

        if ($columns['estado'] ?? false) {
            $query->whereNotIn('estado', ['cancelada', 'cancelado', 'anulada', 'anulado']);
        } elseif ($columns['status'] ?? false) {
            $query->whereNotIn('status', ['cancelada', 'cancelado', 'anulada', 'anulado']);
        }

        $query->where(function ($subQuery) use ($columns): void {
            if ($columns['estado_pagamento'] ?? false) {
                $subQuery->orWhereIn('estado_pagamento', ['pendente', 'parcial']);
            }
            if ($columns['valor_em_aberto'] ?? false) {
                $subQuery->orWhere('valor_em_aberto', '>', 0);
            }
        });

        return $query->exists();
    }

    private function isActiveStatus(string $status): bool
    {
        return in_array($this->normalize($status), self::ACTIVE_STATUSES, true);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, object $user, ?object $profile, string $detail, string $recommendation, bool $actionable, array $context = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'user_id' => (string) ($user->id ?? ''),
            'member_id' => $profile ? (string) ($profile->id ?? '') : null,
            'name' => (string) $this->firstFilled($profile->nome_completo ?? null, $user->nome_completo ?? null, $user->name ?? ''),
            'detail' => $detail,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'context' => $context,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profileFinding(string $severity, string $code, object $profile, string $detail, string $recommendation, bool $actionable): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'user_id' => (string) ($profile->user_id ?? ''),
            'member_id' => (string) ($profile->id ?? ''),
            'name' => (string) ($profile->nome_completo ?? ''),
            'detail' => $detail,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'context' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateFindings(array $findings): array
    {
        return collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                (string) ($finding['code'] ?? ''),
                (string) ($finding['user_id'] ?? ''),
                (string) ($finding['member_id'] ?? ''),
                (string) ($finding['detail'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! $this->isBlank($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = $this->normalize((string) $value);
        if (in_array($normalized, ['1', 'true', 'sim', 'yes', 'ativo', 'active'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'nao', 'no', 'inativo', 'inactive'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,bool> $availableColumns
     */
    private function firstFilledColumnValue(?object $record, array $columns, array $availableColumns): ?string
    {
        if ($record === null) {
            return null;
        }

        foreach ($columns as $column) {
            if (($availableColumns[$column] ?? false) && ! $this->isBlank($record->{$column} ?? null)) {
                return (string) $record->{$column};
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,bool> $availableColumns
     */
    private function firstFilledColumnName(?object $record, array $columns, array $availableColumns): ?string
    {
        if ($record === null) {
            return null;
        }

        foreach ($columns as $column) {
            if (($availableColumns[$column] ?? false) && ! $this->isBlank($record->{$column} ?? null)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $columns
     * @param array<string,bool> $availableColumns
     */
    private function firstTrueColumn(?object $record, array $columns, array $availableColumns): ?string
    {
        if ($record === null) {
            return null;
        }

        foreach ($columns as $column) {
            if (($availableColumns[$column] ?? false) && $this->nullableBoolean($record->{$column} ?? null) === true) {
                return $column;
            }
        }

        return null;
    }

    private function blankToNull(mixed $value): ?string
    {
        return $this->isBlank($value) ? null : (string) $value;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->ascii()->trim()->toString();
    }

    private function formatDate(?CarbonImmutable $date): string
    {
        return $date?->toDateString() ?? '';
    }
}
