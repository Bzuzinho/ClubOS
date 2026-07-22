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
        $rolesByUser = $this->rolesByUser($schema);

        $findings = [];

        $findings = array_merge($findings, $this->identityFindings($users, $profiles, $schema));
        $findings = array_merge($findings, $this->memberProfileFindings($users, $profiles, $schema));
        $findings = array_merge($findings, $this->familyFindings($users, $schema));
        $findings = array_merge($findings, $this->typeAndStatusFindings($users, $rolesByUser, $schema));
        $findings = array_merge($findings, $this->sportsFindings($users, $profiles, $sportsProfiles, $schema));
        $findings = array_merge($findings, $this->financialFindings($users, $financialProfiles, $schema));
        $findings = array_merge($findings, $this->permissionFindings($users, $rolesByUser, $schema));

        $findings = $this->appendCleanFindings($users, $findings);
        $findings = $this->deduplicateFindings($findings);

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toISOString(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($users, $profiles, $sportsProfiles, $schema, $findings),
            'findings' => $findings,
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
            'users_columns' => $this->columns('users', ['id', 'name', 'email', 'perfil', 'estado', 'tipo_membro', 'menor', 'ativo_desportivo', 'data_nascimento', 'numero_socio', 'email_utilizador']),
            'dados_pessoais_columns' => $this->columns('dados_pessoais', ['id', 'user_id', 'nome_completo', 'data_nascimento', 'nif', 'documento_identificacao', 'contacto', 'contacto_alternativo', 'tipo_utilizador']),
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
    private function identityFindings(Collection $users, Collection $profiles, array $schema): array
    {
        $findings = [];

        foreach ($users as $user) {
            $name = $this->displayName($user, $profiles);
            if ($this->isBlank($user->name ?? null) && $this->isBlank($name)) {
                $findings[] = $this->finding('critical', 'user_missing_required_identity', $user, null, 'Nome obrigatório em falta.', 'create_missing_member_profile', true);
            }

            if ($this->isBlank($user->email ?? null)) {
                $findings[] = $this->finding('critical', 'user_missing_required_identity', $user, null, 'Email de login em falta.', 'review_user_permissions', true);
            }

            $birthdate = $this->birthdate($user, $profiles);
            if ($birthdate !== null && $birthdate->isFuture()) {
                $findings[] = $this->finding('warning', 'user_missing_required_identity', $user, null, 'Data de nascimento futura ou inválida.', 'create_missing_member_profile', true);
            }

            $phone = $this->firstFilled($this->profileForUser($profiles, (string) $user->id)?->contacto ?? null, $user->contacto ?? null, $user->contacto_telefonico ?? null);
            if ($phone !== null && ! preg_match('/^[0-9 +().-]{6,30}$/', $phone)) {
                $findings[] = $this->finding('info', 'user_missing_required_identity', $user, null, 'Contacto telefónico com formato pouco provável.', 'create_missing_member_profile', false);
            }
        }

        $findings = array_merge($findings, $this->duplicateFindings($users, 'email', 'duplicate_user_email', 'Email de login duplicado.', 'merge_duplicate_member_after_review'));
        $findings = array_merge($findings, $this->duplicateProfileFindings($profiles, 'nif', 'duplicate_member_identifier', 'NIF duplicado.'));
        $findings = array_merge($findings, $this->duplicateProfileFindings($profiles, 'documento_identificacao', 'duplicate_member_identifier', 'Documento de identificação duplicado.'));
        $findings = array_merge($findings, $this->duplicateCompositeIdentityFindings($users, $profiles));

        return $findings;
    }

    /**
     * @param Collection<int,object> $users
     * @param Collection<int,object> $profiles
     * @param array<string,mixed> $schema
     * @return array<int,array<string,mixed>>
     */
    private function memberProfileFindings(Collection $users, Collection $profiles, array $schema): array
    {
        $findings = [];
        $userIds = $users->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        $allUserIds = data_get($schema, 'tables.users') ? DB::table('users')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all() : [];
        $profilesByUser = $profiles->groupBy('user_id');

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user)) {
                $findings[] = $this->finding('info', 'administrative_user_excluded_from_member_audit', $user, null, 'Utilizador administrativo excluído das regras obrigatórias de ficha de membro.', 'no_action_needed_member_model_clean', false);
                continue;
            }

            if (data_get($schema, 'tables.dados_pessoais') && ! $profilesByUser->has((string) $user->id)) {
                $findings[] = $this->finding('warning', 'user_without_member_profile', $user, null, 'Utilizador operacional sem ficha canónica em dados_pessoais.', 'create_missing_member_profile', true);
            }

            if ($profilesByUser->get((string) $user->id, collect())->count() > 1) {
                $findings[] = $this->finding('critical', 'member_user_link_invalid', $user, null, 'Múltiplas fichas canónicas associadas ao mesmo utilizador.', 'merge_duplicate_member_after_review', true);
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
    private function familyFindings(Collection $users, array $schema): array
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
            if ($this->isMinor($user) && ! $guardiansByChild->has($userId) && ! $this->hasFamilyGuardian($userId, $schema)) {
                $findings[] = $this->finding('warning', 'minor_without_guardian', $user, null, 'Menor sem encarregado de educação em user_guardian/familia_user.', 'assign_guardian_after_review', true);
            }

            if ($this->looksLikeGuardian($user, $schema) && ! $childrenByGuardian->has($userId) && ! $this->hasFamilyDependent($userId, $schema)) {
                $findings[] = $this->finding('info', 'guardian_without_dependents', $user, null, 'Encarregado/responsável sem educandos associados.', 'assign_guardian_after_review', false);
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
    private function typeAndStatusFindings(Collection $users, array $rolesByUser, array $schema): array
    {
        $findings = [];
        $knownTypes = $this->knownUserTypes($schema);

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user)) {
                continue;
            }

            $types = $this->memberTypes($user, $rolesByUser[(string) $user->id] ?? collect());
            if ($types === []) {
                $findings[] = $this->finding('warning', 'member_missing_type', $user, null, 'Membro sem tipo funcional/user_type associado.', 'fix_member_type', true);
            } elseif ($knownTypes !== []) {
                foreach ($types as $type) {
                    if (! in_array($this->normalize($type), $knownTypes, true)) {
                        $findings[] = $this->finding('warning', 'member_unknown_type', $user, null, sprintf('Tipo de membro desconhecido: %s.', $type), 'fix_member_type', true);
                    }
                }
            }

            $status = $this->normalize((string) ($user->estado ?? ''));
            if ($status === '') {
                $findings[] = $this->finding('warning', 'member_missing_status', $user, null, 'Membro sem estado operacional.', 'fix_member_status', true);
            } elseif (! in_array($status, self::KNOWN_STATUSES, true)) {
                $findings[] = $this->finding('warning', 'member_unknown_status', $user, null, sprintf('Estado desconhecido: %s.', (string) $user->estado), 'fix_member_status', true);
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
    private function sportsFindings(Collection $users, Collection $profiles, Collection $sportsProfiles, array $schema): array
    {
        $findings = [];
        $sportsByUser = $sportsProfiles->keyBy('user_id');

        foreach ($users as $user) {
            if (! $this->isActiveAthlete($user)) {
                continue;
            }

            if (data_get($schema, 'tables.athlete_sports_data') && ! $sportsByUser->has((string) $user->id)) {
                $findings[] = $this->finding('warning', 'active_athlete_without_sports_profile', $user, null, 'Atleta ativo sem perfil desportivo canónico.', 'create_sports_profile_after_review', true);
            }

            if ($this->birthdate($user, $profiles) === null) {
                $findings[] = $this->finding('warning', 'athlete_missing_birthdate', $user, null, 'Atleta ativo sem data de nascimento para escalão/validações desportivas.', 'create_sports_profile_after_review', true);
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
    private function financialFindings(Collection $users, Collection $financialProfiles, array $schema): array
    {
        $findings = [];
        $financialByUser = $financialProfiles->keyBy('user_id');

        foreach ($users as $user) {
            if ($this->isActiveAthlete($user)) {
                $financial = $financialByUser->get((string) $user->id);
                if (! $financial || $this->isBlank($financial->mensalidade_id ?? null)) {
                    $findings[] = $this->finding('warning', 'active_athlete_without_financial_setup', $user, null, 'Atleta ativo sem configuração financeira mínima/mensalidade.', 'review_financial_setup', true);
                }
            }

            if (! $this->isActiveStatus((string) ($user->estado ?? '')) && data_get($schema, 'tables.invoices') && $this->hasActiveMonthlyObligation((string) $user->id)) {
                $findings[] = $this->finding('warning', 'inactive_member_with_active_financial_obligation', $user, null, 'Membro inativo/eliminado com obrigação financeira mensal ativa.', 'review_financial_setup', true);
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
    private function permissionFindings(Collection $users, array $rolesByUser, array $schema): array
    {
        $findings = [];
        $userIds = $users->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user) && empty($rolesByUser[(string) $user->id])) {
                continue;
            }

            if (data_get($schema, 'tables.user_user_type') && empty($rolesByUser[(string) $user->id])) {
                $findings[] = $this->finding('warning', 'user_missing_role', $user, null, 'Utilizador sem user_type associado.', 'review_user_permissions', true);
            }

            foreach (($rolesByUser[(string) $user->id] ?? collect()) as $role) {
                if ($this->isBlank($role->user_type_id ?? null) || $this->isBlank($role->nome ?? null)) {
                    $findings[] = $this->finding('critical', 'user_unknown_role', $user, null, 'Associação a user_type inexistente.', 'review_user_permissions', true, ['user_type_id' => (string) ($role->user_type_id ?? '')]);
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
    private function appendCleanFindings(Collection $users, array $findings): array
    {
        $findingsByUser = collect($findings)->filter(static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))->groupBy('user_id');

        foreach ($users as $user) {
            if ($this->isAdministrativeUser($user) || $findingsByUser->has((string) $user->id)) {
                continue;
            }

            $findings[] = $this->finding('info', 'member_model_clean', $user, null, 'Modelo de membro coerente nas regras C1 aplicáveis.', 'no_action_needed_member_model_clean', false);
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
    private function summary(Collection $users, Collection $profiles, Collection $sportsProfiles, array $schema, array $findings): array
    {
        $byCode = collect($findings)->countBy('code');

        return [
            'total_users_scanned' => $users->count(),
            'total_members_scanned' => data_get($schema, 'tables.dados_pessoais') ? $profiles->count() : $users->count(),
            'total_athletes_scanned' => $users->filter(fn (object $user): bool => $this->isActiveAthlete($user))->count(),
            'total_guardians_scanned' => data_get($schema, 'tables.user_guardian') ? DB::table('user_guardian')->distinct()->count('guardian_id') : 0,
            'duplicate_identity_count' => (int) (($byCode['duplicate_user_email'] ?? 0) + ($byCode['duplicate_member_identifier'] ?? 0)),
            'invalid_relation_count' => (int) (($byCode['member_user_link_invalid'] ?? 0) + ($byCode['invalid_guardian_relation'] ?? 0)),
            'missing_required_identity_count' => (int) ($byCode['user_missing_required_identity'] ?? 0),
            'minor_without_guardian_count' => (int) ($byCode['minor_without_guardian'] ?? 0),
            'member_type_issue_count' => (int) (($byCode['member_missing_type'] ?? 0) + ($byCode['member_unknown_type'] ?? 0)),
            'member_status_issue_count' => (int) (($byCode['member_missing_status'] ?? 0) + ($byCode['member_unknown_status'] ?? 0)),
            'sports_profile_issue_count' => (int) (($byCode['active_athlete_without_sports_profile'] ?? 0) + ($byCode['athlete_missing_birthdate'] ?? 0)),
            'financial_profile_issue_count' => (int) (($byCode['active_athlete_without_financial_setup'] ?? 0) + ($byCode['inactive_member_with_active_financial_obligation'] ?? 0)),
            'permission_issue_count' => (int) (($byCode['user_missing_role'] ?? 0) + ($byCode['user_unknown_role'] ?? 0) + ($byCode['permission_orphan_user'] ?? 0)),
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
    private function duplicateFindings(Collection $users, string $column, string $code, string $detail, string $recommendation): array
    {
        $findings = [];
        $users->filter(fn (object $user): bool => ! $this->isBlank($user->{$column} ?? null))
            ->groupBy(fn (object $user): string => $this->normalize((string) $user->{$column}))
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $code, $detail, $recommendation): void {
                foreach ($group as $user) {
                    $findings[] = $this->finding('critical', $code, $user, null, $detail, $recommendation, true);
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
    private function duplicateCompositeIdentityFindings(Collection $users, Collection $profiles): array
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
                    $findings[] = $this->finding('critical', 'duplicate_member_identifier', $item['user'], null, 'Possível duplicado por nome+data nascimento+NIF.', 'merge_duplicate_member_after_review', true);
                }
            });

        return $findings;
    }

    /**
     * @param Collection<int,object> $roles
     * @return array<int,string>
     */
    private function memberTypes(object $user, Collection $roles): array
    {
        $types = [];
        $raw = $user->tipo_membro ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (is_array($raw)) {
            $types = array_merge($types, array_filter(array_map('strval', $raw)));
        }

        foreach ($roles as $role) {
            $types[] = (string) (($role->codigo ?? null) ?: ($role->nome ?? ''));
        }

        return array_values(array_unique(array_filter($types, fn (string $type): bool => ! $this->isBlank($type))));
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
        $profile = $this->profileForUser($profiles, (string) $user->id);
        $value = $this->firstFilled($profile->data_nascimento ?? null, $user->data_nascimento ?? null);
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::tomorrow();
        }
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

        if (str_contains($text, 'encarregado') || str_contains($text, 'guardian') || str_contains($text, 'educacao') || str_contains($text, 'educação')) {
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

    private function hasActiveMonthlyObligation(string $userId): bool
    {
        return DB::table('invoices')
            ->where('user_id', $userId)
            ->where(function ($query): void {
                $query->where('tipo', 'mensalidade')
                    ->orWhere('origem_tipo', 'monthly_fee')
                    ->orWhere('origem_tipo', 'monthly_fee_legacy');
            })
            ->whereNotIn('estado', ['cancelada', 'cancelado', 'anulada', 'anulado'])
            ->where(function ($query): void {
                $query->whereIn('estado_pagamento', ['pendente', 'parcial'])
                    ->orWhere('valor_em_aberto', '>', 0);
            })
            ->exists();
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
