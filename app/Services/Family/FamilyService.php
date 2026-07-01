<?php

namespace App\Services\Family;

use App\Models\Familia;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FamilyService
{
    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
    ) {
    }

    /**
     * @var array<string>
     */
    private const EDITOR_ROLES = [
        'responsavel',
        'encarregado_educacao',
    ];

    public function userHasFamily(User $user): bool
    {
        return ($this->familyTablesAvailable() && $this->familyMembershipsQuery($user)->exists()) || $this->hasLegacyFamilyLinks($user);
    }

    public function userHasEducandos(User $user): bool
    {
        return $this->educandosForUser($user)->isNotEmpty();
    }

    public function actualFamiliesForUser(User $user): Collection
    {
        if (! $this->familyTablesAvailable()) {
            return collect();
        }

        return Familia::query()
            ->where('ativo', true)
            ->whereHas('members', fn (Builder $query) => $query->whereKey($user->id))
            ->with([
                'responsavel:id,name,nome_completo,email,numero_socio,foto_perfil,estado,tipo_membro',
                'members:id,name,nome_completo,email,numero_socio,foto_perfil,estado,tipo_membro,escalao,menor',
            ])
            ->get();
    }

    public function familiesForPortal(User $user): Collection
    {
        $families = $this->actualFamiliesForUser($user);

        if ($families->isNotEmpty()) {
            return $families->map(fn (Familia $familia) => $this->mapActualFamily($user, $familia))->values();
        }

        $legacyFamily = $this->buildLegacyFamily($user);

        return $legacyFamily ? collect([$legacyFamily]) : collect();
    }

    public function familySummary(User $user): array
    {
        $families = $this->familiesForPortal($user);

        if ($families->isEmpty()) {
            return [
                'total_familias' => 0,
                'total_elementos' => 0,
                'educandos' => 0,
                'alertas' => 0,
                'pagamentos_pendentes' => 0,
                'convocatorias_pendentes' => 0,
            ];
        }

        return [
            'total_familias' => $families->count(),
            'total_elementos' => (int) $families->sum('total_elementos'),
            'educandos' => (int) $families->sum('educandos_count'),
            'alertas' => (int) $families->sum('alertas_count'),
            'pagamentos_pendentes' => (int) $families->sum('pagamentos_pendentes'),
            'convocatorias_pendentes' => (int) $families->sum('convocatorias_pendentes'),
        ];
    }

    public function portalMembers(User $user): EloquentCollection
    {
        $actualMembers = $this->actualFamiliesForUser($user)
            ->flatMap(fn (Familia $familia) => $familia->members);

        $legacyMembers = collect([$user])
            ->merge($user->educandos()->get())
            ->merge($user->encarregados()->get());

        return User::query()
            ->whereKey(
                $actualMembers
                    ->merge($legacyMembers)
                    ->pluck('id')
                    ->push($user->id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
            )
            ->get();
    }

    public function userCanManageFamily(User $user, ?Familia $familia = null): bool
    {
        if ($this->userCanAccessAdmin($user)) {
            return true;
        }

        if ($familia === null) {
            return $this->actualFamiliesForUser($user)
                ->contains(fn (Familia $candidate) => $this->userCanManageFamily($user, $candidate));
        }

        $membership = $this->membershipForFamily($user, $familia);

        return $membership !== null
            && in_array($this->normalizeRole($membership->pivot?->papel_na_familia), self::EDITOR_ROLES, true)
            && (bool) ($membership->pivot?->pode_editar ?? false);
    }

    public function userCanViewFamilyMember(User $viewer, User $target): bool
    {
        if ($viewer->is($target) || $this->userCanAccessAdmin($viewer)) {
            return true;
        }

        if ($this->sharesActualFamily($viewer, $target)) {
            return true;
        }

        return $viewer->educandos()->whereKey($target->id)->exists()
            || $viewer->encarregados()->whereKey($target->id)->exists()
            || $target->educandos()->whereKey($viewer->id)->exists()
            || $target->encarregados()->whereKey($viewer->id)->exists();
    }

    public function userCanEditFamilyMember(User $viewer, User $target): bool
    {
        if ($viewer->is($target)) {
            return true;
        }

        if ($this->userCanAccessAdmin($viewer)) {
            return true;
        }

        $sharedFamily = $this->firstSharedActualFamily($viewer, $target);

        if ($sharedFamily !== null) {
            $targetMembership = $this->membershipForFamily($target, $sharedFamily);

            return $targetMembership !== null
                && $this->normalizeRole($targetMembership->pivot?->papel_na_familia) === 'educando'
                && $this->userCanManageFamily($viewer, $sharedFamily);
        }

        return $viewer->educandos()->whereKey($target->id)->exists();
    }

    public function userCanAccessAdmin(User $user): bool
    {
        return collect(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->prepend($user->perfil)
            ->map(fn ($value) => $this->normalizeRole((string) $value))
            ->contains('admin')
            || $user->userTypes()->where('ativo', true)->get()->contains(function ($type) {
                return $this->normalizeRole((string) $type->codigo) === 'admin'
                    || $this->normalizeRole((string) $type->nome) === 'admin';
            });
    }

    public function userHasAdministratorProfile(User $user): bool
    {
        return $this->normalizeRole((string) $user->perfil) === 'admin';
    }

    public function userIsOnlyAdmin(User $user): bool
    {
        if (! $this->userCanAccessAdmin($user)) {
            return false;
        }

        $otherRelevantRoles = collect(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->prepend($user->perfil)
            ->merge($user->userTypes()->where('ativo', true)->get()->pluck('codigo'))
            ->merge($user->userTypes()->where('ativo', true)->get()->pluck('nome'))
            ->map(fn ($value) => $this->normalizeRole((string) $value))
            ->filter(fn ($role) => $role !== '' && $role !== 'admin')
            ->reject(fn ($role) => in_array($role, ['direcao', 'gestor', 'staff', 'tecnico'], true))
            ->unique();

        return $otherRelevantRoles->isEmpty() && ! $this->userHasFamily($user);
    }

    public function ensureFamilyForManager(User $user, ?string $name = null): Familia
    {
        if (! $this->familyTablesAvailable()) {
            throw new \RuntimeException('As tabelas de família ainda não existem neste ambiente. Execute as migrações antes de associar membros.');
        }

        $existingFamily = $this->actualFamiliesForUser($user)->first(fn (Familia $familia) => $this->userCanManageFamily($user, $familia));

        if ($existingFamily) {
            return $existingFamily;
        }

        $legacyFamily = $this->buildLegacyFamily($user);

        $family = Familia::create([
            'nome' => $name ?: ($legacyFamily['nome'] ?? sprintf('Família %s', $this->memberIdentityDisplayResolver->displayName($user))),
            'responsavel_user_id' => $user->id,
            'ativo' => true,
        ]);

        if ($legacyFamily !== null) {
            collect($legacyFamily['members'] ?? [])
                ->unique('id')
                ->each(function (array $member) use ($family, $user) {
                    if ($family->members()->whereKey($member['id'])->exists()) {
                        return;
                    }

                    $permissions = $member['permissions'] ?? [];

                    $family->members()->attach($member['id'], [
                        'id' => (string) Str::uuid(),
                        'papel_na_familia' => $member['papel_na_familia'] ?? ($member['id'] === $user->id ? 'responsavel' : 'familiar'),
                        'pode_editar' => (bool) ($permissions['pode_editar'] ?? ($member['id'] === $user->id)),
                        'pode_ver_financeiro' => (bool) ($permissions['pode_ver_financeiro'] ?? true),
                        'pode_ver_desportivo' => (bool) ($permissions['pode_ver_desportivo'] ?? true),
                        'pode_ver_documentos' => (bool) ($permissions['pode_ver_documentos'] ?? true),
                        'pode_ver_comunicacoes' => (bool) ($permissions['pode_ver_comunicacoes'] ?? true),
                    ]);
                });
        } elseif (! $family->members()->whereKey($user->id)->exists()) {
            $family->members()->attach($user->id, [
                'id' => (string) Str::uuid(),
                'papel_na_familia' => $this->userHasEducandos($user) ? 'encarregado_educacao' : 'responsavel',
                'pode_editar' => true,
                'pode_ver_financeiro' => true,
                'pode_ver_desportivo' => true,
                'pode_ver_documentos' => true,
                'pode_ver_comunicacoes' => true,
            ]);
        }

        return $family->fresh(['responsavel', 'members']);
    }

    private function familyMembershipsQuery(User $user): Builder
    {
        return Familia::query()
            ->where('ativo', true)
            ->whereHas('members', fn (Builder $query) => $query->whereKey($user->id));
    }

    private function familyTablesAvailable(): bool
    {
        return Schema::hasTable('familias') && Schema::hasTable('familia_user');
    }

    private function hasLegacyFamilyLinks(User $user): bool
    {
        return $user->educandos()->exists() || $user->encarregados()->exists();
    }

    private function educandosForUser(User $user): Collection
    {
        $actualEducandos = $this->actualFamiliesForUser($user)
            ->flatMap(function (Familia $familia) {
                return $familia->members->filter(function (User $member) {
                    return $this->normalizeRole($member->pivot?->papel_na_familia) === 'educando';
                });
            });

        if ($actualEducandos->isNotEmpty()) {
            return $actualEducandos->unique('id')->values();
        }

        return $user->educandos()
            ->select('users.id', 'users.name', 'users.nome_completo', 'users.email', 'users.numero_socio', 'users.estado', 'users.tipo_membro', 'users.escalao', 'users.foto_perfil', 'users.menor')
            ->get();
    }

    private function mapActualFamily(User $viewer, Familia $familia): array
    {
        $members = $familia->members
            ->map(function (User $member) use ($viewer) {
                return [
                    'id' => $member->id,
                    'name' => $this->memberIdentityDisplayResolver->displayName($member),
                    'email' => $member->email,
                    'numero_socio' => $member->numero_socio,
                    'estado' => $member->estado,
                    'foto_perfil' => $member->foto_perfil,
                    'tipo_membro' => is_array($member->tipo_membro) ? $member->tipo_membro : (array) $member->tipo_membro,
                    'escalao' => is_array($member->escalao) ? $member->escalao : (array) $member->escalao,
                    'papel_na_familia' => $member->pivot?->papel_na_familia,
                    'permissions' => [
                        'pode_editar' => (bool) ($member->pivot?->pode_editar ?? false),
                        'pode_ver_financeiro' => (bool) ($member->pivot?->pode_ver_financeiro ?? false),
                        'pode_ver_desportivo' => (bool) ($member->pivot?->pode_ver_desportivo ?? false),
                        'pode_ver_documentos' => (bool) ($member->pivot?->pode_ver_documentos ?? false),
                        'pode_ver_comunicacoes' => (bool) ($member->pivot?->pode_ver_comunicacoes ?? false),
                    ],
                    'can_view' => $this->userCanViewFamilyMember($viewer, $member),
                    'can_edit' => $this->userCanEditFamilyMember($viewer, $member),
                ];
            })
            ->values();

        return [
            'id' => $familia->id,
            'nome' => $familia->nome,
            'observacoes' => $familia->observacoes,
            'ativo' => $familia->ativo,
            'responsavel_user_id' => $familia->responsavel_user_id,
            'members' => $members->all(),
            'total_elementos' => $members->count(),
            'educandos_count' => $members->filter(fn ($member) => $this->normalizeRole((string) $member['papel_na_familia']) === 'educando')->count(),
            'alertas_count' => 0,
            'pagamentos_pendentes' => 0,
            'convocatorias_pendentes' => 0,
            'legacy' => false,
        ];
    }

    private function buildLegacyFamily(User $user): ?array
    {
        $guardians = $user->encarregados()
            ->select('users.id', 'users.name', 'users.nome_completo', 'users.email', 'users.numero_socio', 'users.estado', 'users.tipo_membro', 'users.escalao', 'users.foto_perfil', 'users.menor')
            ->get();
        $educandos = $user->educandos()
            ->select('users.id', 'users.name', 'users.nome_completo', 'users.email', 'users.numero_socio', 'users.estado', 'users.tipo_membro', 'users.escalao', 'users.foto_perfil', 'users.menor')
            ->get();

        $members = collect([$user])
            ->merge($guardians)
            ->merge($educandos)
            ->unique('id')
            ->values()
            ->map(function (User $member) use ($user, $educandos) {
                $role = $member->is($user)
                    ? ($educandos->isNotEmpty() ? 'encarregado_educacao' : 'familiar')
                    : ($educandos->contains('id', $member->id) ? 'educando' : 'encarregado_educacao');

                return [
                    'id' => $member->id,
                    'name' => $this->memberIdentityDisplayResolver->displayName($member),
                    'email' => $member->email,
                    'numero_socio' => $member->numero_socio,
                    'estado' => $member->estado,
                    'foto_perfil' => $member->foto_perfil,
                    'tipo_membro' => is_array($member->tipo_membro) ? $member->tipo_membro : (array) $member->tipo_membro,
                    'escalao' => is_array($member->escalao) ? $member->escalao : (array) $member->escalao,
                    'papel_na_familia' => $role,
                    'permissions' => [
                        'pode_editar' => $role !== 'encarregado_educacao' || $member->is($user),
                        'pode_ver_financeiro' => true,
                        'pode_ver_desportivo' => true,
                        'pode_ver_documentos' => true,
                        'pode_ver_comunicacoes' => true,
                    ],
                    'can_view' => $this->userCanViewFamilyMember($user, $member),
                    'can_edit' => $this->userCanEditFamilyMember($user, $member),
                ];
            });

        if ($members->count() <= 1) {
            return null;
        }

        return [
            'id' => 'legacy:' . $user->id,
            'nome' => sprintf('Família %s', $this->memberIdentityDisplayResolver->displayName($user)),
            'observacoes' => null,
            'ativo' => true,
            'responsavel_user_id' => $user->id,
            'members' => $members->all(),
            'total_elementos' => $members->count(),
            'educandos_count' => $members->filter(fn ($member) => $this->normalizeRole((string) $member['papel_na_familia']) === 'educando')->count(),
            'alertas_count' => 0,
            'pagamentos_pendentes' => 0,
            'convocatorias_pendentes' => 0,
            'legacy' => true,
        ];
    }

    private function membershipForFamily(User $user, Familia $familia): ?User
    {
        return $familia->members->firstWhere('id', $user->id)
            ?? $familia->members()->whereKey($user->id)->first();
    }

    private function sharesActualFamily(User $viewer, User $target): bool
    {
        return $this->firstSharedActualFamily($viewer, $target) !== null;
    }

    private function firstSharedActualFamily(User $viewer, User $target): ?Familia
    {
        return $this->actualFamiliesForUser($viewer)
            ->first(fn (Familia $familia) => $familia->members->contains('id', $target->id));
    }

    private function normalizeRole(?string $value): string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return match ($normalized) {
            'encarregado', 'encarregado_de_educacao' => 'encarregado_educacao',
            'administrador' => 'admin',
            default => $normalized,
        };
    }
}