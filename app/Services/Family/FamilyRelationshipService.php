<?php

namespace App\Services\Family;

use App\Models\Familia;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyRelationshipService
{
    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
    ) {
    }

    public function associateGuardian(User $member, User $guardian): void
    {
        if ($member->is($guardian)) {
            throw ValidationException::withMessages([
                'guardian_id' => 'Um membro não pode ser encarregado de educação de si próprio.',
            ]);
        }

        DB::transaction(function () use ($member, $guardian): void {
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);
            $lockedGuardian = User::query()->lockForUpdate()->findOrFail($guardian->id);

            if (! DB::table('user_guardian')
                ->where('user_id', $lockedMember->id)
                ->where('guardian_id', $lockedGuardian->id)
                ->exists()) {
                DB::table('user_guardian')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $lockedMember->id,
                    'guardian_id' => $lockedGuardian->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->syncCompatibilityRelationIds($lockedMember, $lockedGuardian, true);

            $family = $this->uniqueActualFamilyForMember($lockedMember);
            if ($family !== null) {
                $this->syncGuardianIntoExistingFamily($family, $lockedMember, $lockedGuardian);
            }
        });
    }

    public function removeGuardian(User $member, User $guardian): void
    {
        DB::transaction(function () use ($member, $guardian): void {
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);
            $lockedGuardian = User::query()->lockForUpdate()->findOrFail($guardian->id);

            DB::table('user_guardian')
                ->where('user_id', $lockedMember->id)
                ->where('guardian_id', $lockedGuardian->id)
                ->delete();

            $this->syncCompatibilityRelationIds($lockedMember, $lockedGuardian, false);

            $family = $this->uniqueActualFamilyForMember($lockedMember);
            if ($family !== null) {
                $membership = $family->members()->whereKey($lockedGuardian->id)->first();

                if ($membership !== null && $membership->pivot?->papel_na_familia === 'encarregado_educacao') {
                    $family->members()->updateExistingPivot(
                        $lockedGuardian->id,
                        $this->membershipPermissions('familiar'),
                    );
                }
            }
        });
    }

    public function addFamilyMember(
        User $member,
        User $relatedMember,
        string $role,
        ?string $familyId = null,
    ): Familia {
        if ($member->is($relatedMember)) {
            throw ValidationException::withMessages([
                'member_id' => 'Este membro já é o titular desta ficha.',
            ]);
        }

        return DB::transaction(function () use ($member, $relatedMember, $role, $familyId): Familia {
            $lockedMember = User::query()
                ->with(['encarregados', 'educandos'])
                ->lockForUpdate()
                ->findOrFail($member->id);
            $target = User::query()->lockForUpdate()->findOrFail($relatedMember->id);
            $family = $this->resolveFamily($lockedMember, $familyId);

            if ($family->members()->whereKey($target->id)->exists()) {
                throw ValidationException::withMessages([
                    'member_id' => 'Este membro já pertence à família.',
                ]);
            }

            $family->members()->attach($target->id, $this->membershipPayload($role));

            if ($role === 'responsavel') {
                $this->replaceFamilyResponsible($family, $target);
            }

            return $family->fresh(['responsavel', 'members']);
        });
    }

    public function updateFamilyMemberRole(
        User $member,
        Familia $family,
        User $familyMember,
        string $role,
    ): Familia {
        return DB::transaction(function () use ($member, $family, $familyMember, $role): Familia {
            $lockedFamily = $this->familyForMember($member, $family->id, true);
            $target = User::query()->lockForUpdate()->findOrFail($familyMember->id);

            if (! $lockedFamily->members()->whereKey($target->id)->exists()) {
                abort(404);
            }

            if ($role === 'responsavel') {
                $this->replaceFamilyResponsible($lockedFamily, $target);
            } else {
                if ((string) $lockedFamily->responsavel_user_id === (string) $target->id) {
                    $replacement = $this->replacementResponsible($lockedFamily, $target);

                    if ($replacement === null) {
                        throw ValidationException::withMessages([
                            'papel_na_familia' => 'A família tem de manter um responsável.',
                        ]);
                    }

                    $this->replaceFamilyResponsible($lockedFamily, $replacement);
                }

                $lockedFamily->members()->updateExistingPivot(
                    $target->id,
                    $this->membershipPermissions($role),
                );
            }

            return $lockedFamily->fresh(['responsavel', 'members']);
        });
    }

    public function removeFamilyMember(User $member, Familia $family, User $familyMember): void
    {
        if ($member->is($familyMember)) {
            throw ValidationException::withMessages([
                'member_id' => 'Não pode remover desta família o membro cuja ficha está aberta.',
            ]);
        }

        DB::transaction(function () use ($member, $family, $familyMember): void {
            $lockedFamily = $this->familyForMember($member, $family->id, true);
            $target = User::query()->lockForUpdate()->findOrFail($familyMember->id);

            if (! $lockedFamily->members()->whereKey($target->id)->exists()) {
                abort(404);
            }

            if ((string) $lockedFamily->responsavel_user_id === (string) $target->id) {
                $replacement = $this->replacementResponsible($lockedFamily, $target);

                if ($replacement === null) {
                    throw ValidationException::withMessages([
                        'member_id' => 'A família tem de manter um responsável.',
                    ]);
                }

                $this->replaceFamilyResponsible($lockedFamily, $replacement);
            }

            $lockedFamily->members()->detach($target->id);
        });
    }

    private function resolveFamily(User $member, ?string $familyId): Familia
    {
        if ($familyId) {
            return $this->familyForMember($member, $familyId, true);
        }

        $existingFamily = Familia::query()
            ->where('ativo', true)
            ->whereHas('members', fn ($query) => $query->whereKey($member->id))
            ->lockForUpdate()
            ->first();

        if ($existingFamily) {
            return $existingFamily;
        }

        return $this->createFamilyFromCurrentRelations($member);
    }

    private function familyForMember(User $member, string $familyId, bool $lock = false): Familia
    {
        $query = Familia::query()
            ->whereKey($familyId)
            ->where('ativo', true)
            ->whereHas('members', fn ($memberQuery) => $memberQuery->whereKey($member->id));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function uniqueActualFamilyForMember(User $member): ?Familia
    {
        $families = Familia::query()
            ->where('ativo', true)
            ->whereHas('members', fn ($query) => $query->whereKey($member->id))
            ->orderBy('created_at')
            ->limit(2)
            ->lockForUpdate()
            ->get();

        return $families->count() === 1 ? $families->first() : null;
    }

    private function createFamilyFromCurrentRelations(User $member): Familia
    {
        $guardians = $member->relationLoaded('encarregados')
            ? $member->getRelation('encarregados')
            : $member->encarregados()->get();
        $dependents = $member->relationLoaded('educandos')
            ? $member->getRelation('educandos')
            : $member->educandos()->get();
        $responsible = $guardians->first() ?? $member;

        $family = Familia::query()->create([
            'nome' => sprintf(
                'Família %s',
                $this->memberIdentityDisplayResolver->displayNameOrFallback($responsible, 'Sem nome'),
            ),
            'responsavel_user_id' => $responsible->id,
            'ativo' => true,
        ]);

        collect([$member])
            ->merge($guardians)
            ->merge($dependents)
            ->unique('id')
            ->each(function (User $candidate) use ($family, $member, $guardians, $dependents, $responsible): void {
                $role = match (true) {
                    $candidate->is($responsible) => 'responsavel',
                    $guardians->contains('id', $candidate->id) => 'encarregado_educacao',
                    $dependents->contains('id', $candidate->id) => 'educando',
                    $guardians->isNotEmpty() && $candidate->is($member) => 'educando',
                    default => 'familiar',
                };

                $family->members()->attach($candidate->id, $this->membershipPayload($role));
            });

        return $family;
    }

    private function syncGuardianIntoExistingFamily(Familia $family, User $member, User $guardian): void
    {
        $guardianMembership = $family->members()->whereKey($guardian->id)->first();

        if ($guardianMembership === null) {
            $family->members()->attach($guardian->id, $this->membershipPayload('encarregado_educacao'));
        } elseif ($guardianMembership->pivot?->papel_na_familia !== 'responsavel') {
            $family->members()->updateExistingPivot(
                $guardian->id,
                $this->membershipPermissions('encarregado_educacao'),
            );
        }

        $memberMembership = $family->members()->whereKey($member->id)->first();
        if ($memberMembership !== null && $memberMembership->pivot?->papel_na_familia === 'familiar') {
            $family->members()->updateExistingPivot(
                $member->id,
                $this->membershipPermissions('educando'),
            );
        }
    }

    private function syncCompatibilityRelationIds(User $member, User $guardian, bool $associate): void
    {
        $guardianIds = $this->relationIds($member, 'encarregado_educacao');
        $dependentIds = $this->relationIds($guardian, 'educandos');

        if ($associate) {
            $guardianIds->push((string) $guardian->id);
            $dependentIds->push((string) $member->id);
        } else {
            $guardianIds = $guardianIds->reject(
                fn (string $id): bool => $id === (string) $guardian->id,
            );
            $dependentIds = $dependentIds->reject(
                fn (string $id): bool => $id === (string) $member->id,
            );
        }

        $this->persistRelationIds($member, 'encarregado_educacao', $guardianIds->all());
        $this->persistRelationIds($guardian, 'educandos', $dependentIds->all());
    }

    private function replaceFamilyResponsible(Familia $family, User $responsible): void
    {
        if ($family->responsavel_user_id && (string) $family->responsavel_user_id !== (string) $responsible->id) {
            $family->members()->updateExistingPivot(
                $family->responsavel_user_id,
                $this->membershipPermissions('familiar'),
            );
        }

        $family->members()->updateExistingPivot(
            $responsible->id,
            $this->membershipPermissions('responsavel'),
        );
        $family->forceFill(['responsavel_user_id' => $responsible->id])->save();
    }

    private function replacementResponsible(Familia $family, User $excluded): ?User
    {
        return $family->members()
            ->whereKeyNot($excluded->id)
            ->orderByRaw("CASE WHEN familia_user.papel_na_familia = 'encarregado_educacao' THEN 0 ELSE 1 END")
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipPayload(string $role): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
        ], $this->membershipPermissions($role));
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipPermissions(string $role): array
    {
        return [
            'papel_na_familia' => $role,
            'pode_editar' => in_array($role, ['responsavel', 'encarregado_educacao'], true),
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
            'updated_at' => now(),
        ];
    }

    private function relationIds(User $user, string $attribute): \Illuminate\Support\Collection
    {
        return collect(is_array($user->getAttribute($attribute)) ? $user->getAttribute($attribute) : [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param array<int, string> $ids
     */
    private function persistRelationIds(User $user, string $attribute, array $ids): void
    {
        $user->forceFill([
            $attribute => collect($ids)
                ->map(fn ($id) => (string) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ])->save();
    }
}
