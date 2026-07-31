<?php

namespace App\Http\Controllers;

use App\Models\Familia;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberFamilyRelationsController extends Controller
{
    private const FAMILY_ROLES = [
        'responsavel',
        'encarregado_educacao',
        'educando',
        'familiar',
    ];

    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
    ) {
    }

    public function storeGuardian(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'guardian_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $guardianId = (string) $validated['guardian_id'];

        if ($guardianId === (string) $member->id) {
            throw ValidationException::withMessages([
                'guardian_id' => 'Um membro não pode ser encarregado de educação de si próprio.',
            ]);
        }

        DB::transaction(function () use ($member, $guardianId): void {
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);
            $guardian = User::query()->lockForUpdate()->findOrFail($guardianId);

            if (! DB::table('user_guardian')
                ->where('user_id', $lockedMember->id)
                ->where('guardian_id', $guardian->id)
                ->exists()) {
                DB::table('user_guardian')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $lockedMember->id,
                    'guardian_id' => $guardian->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->persistRelationIds(
                $lockedMember,
                'encarregado_educacao',
                $this->relationIds($lockedMember, 'encarregado_educacao')->push((string) $guardian->id)->all(),
            );
            $this->persistRelationIds(
                $guardian,
                'educandos',
                $this->relationIds($guardian, 'educandos')->push((string) $lockedMember->id)->all(),
            );
        });

        return back()->with('success', 'Encarregado de educação associado com sucesso.');
    }

    public function destroyGuardian(User $member, User $guardian): RedirectResponse
    {
        DB::transaction(function () use ($member, $guardian): void {
            $lockedMember = User::query()->lockForUpdate()->findOrFail($member->id);
            $lockedGuardian = User::query()->lockForUpdate()->findOrFail($guardian->id);

            DB::table('user_guardian')
                ->where('user_id', $lockedMember->id)
                ->where('guardian_id', $lockedGuardian->id)
                ->delete();

            $this->persistRelationIds(
                $lockedMember,
                'encarregado_educacao',
                $this->relationIds($lockedMember, 'encarregado_educacao')
                    ->reject(fn (string $id): bool => $id === (string) $lockedGuardian->id)
                    ->all(),
            );
            $this->persistRelationIds(
                $lockedGuardian,
                'educandos',
                $this->relationIds($lockedGuardian, 'educandos')
                    ->reject(fn (string $id): bool => $id === (string) $lockedMember->id)
                    ->all(),
            );
        });

        return back()->with('success', 'Encarregado de educação removido.');
    }

    public function storeFamilyMember(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'family_id' => ['nullable', 'uuid', 'exists:familias,id'],
            'member_id' => ['required', 'uuid', 'exists:users,id'],
            'papel_na_familia' => ['required', Rule::in(self::FAMILY_ROLES)],
        ]);

        if ((string) $validated['member_id'] === (string) $member->id) {
            throw ValidationException::withMessages([
                'member_id' => 'Este membro já é o titular desta ficha.',
            ]);
        }

        DB::transaction(function () use ($member, $validated): void {
            $lockedMember = User::query()
                ->with(['encarregados', 'educandos'])
                ->lockForUpdate()
                ->findOrFail($member->id);
            $family = $this->resolveFamily($lockedMember, $validated['family_id'] ?? null);
            $relatedMember = User::query()->lockForUpdate()->findOrFail($validated['member_id']);

            if ($family->members()->whereKey($relatedMember->id)->exists()) {
                throw ValidationException::withMessages([
                    'member_id' => 'Este membro já pertence à família.',
                ]);
            }

            $role = (string) $validated['papel_na_familia'];
            $family->members()->attach($relatedMember->id, $this->membershipPayload($role));

            if ($role === 'responsavel') {
                $this->replaceFamilyResponsible($family, $relatedMember);
            }
        });

        return back()->with('success', 'Membro adicionado à família.');
    }

    public function updateFamilyMember(
        Request $request,
        User $member,
        Familia $family,
        User $familyMember,
    ): RedirectResponse {
        $validated = $request->validate([
            'papel_na_familia' => ['required', Rule::in(self::FAMILY_ROLES)],
        ]);

        DB::transaction(function () use ($member, $family, $familyMember, $validated): void {
            $lockedFamily = $this->familyForMember($member, $family->id, true);
            $target = User::query()->lockForUpdate()->findOrFail($familyMember->id);

            if (! $lockedFamily->members()->whereKey($target->id)->exists()) {
                abort(404);
            }

            $role = (string) $validated['papel_na_familia'];

            if ($role === 'responsavel') {
                $this->replaceFamilyResponsible($lockedFamily, $target);
            } else {
                if ((string) $lockedFamily->responsavel_user_id === (string) $target->id) {
                    $replacement = $lockedFamily->members()
                        ->whereKeyNot($target->id)
                        ->orderByRaw("CASE WHEN familia_user.papel_na_familia = 'encarregado_educacao' THEN 0 ELSE 1 END")
                        ->first();

                    if (! $replacement) {
                        throw ValidationException::withMessages([
                            'papel_na_familia' => 'A família tem de manter um responsável.',
                        ]);
                    }

                    $this->replaceFamilyResponsible($lockedFamily, $replacement);
                }

                $lockedFamily->members()->updateExistingPivot($target->id, $this->membershipPermissions($role));
            }
        });

        return back()->with('success', 'Relação familiar atualizada.');
    }

    public function destroyFamilyMember(User $member, Familia $family, User $familyMember): RedirectResponse
    {
        if ((string) $member->id === (string) $familyMember->id) {
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
                $replacement = $lockedFamily->members()
                    ->whereKeyNot($target->id)
                    ->orderByRaw("CASE WHEN familia_user.papel_na_familia = 'encarregado_educacao' THEN 0 ELSE 1 END")
                    ->firstOrFail();
                $this->replaceFamilyResponsible($lockedFamily, $replacement);
            }

            $lockedFamily->members()->detach($target->id);
        });

        return back()->with('success', 'Membro removido da família.');
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

    private function createFamilyFromCurrentRelations(User $member): Familia
    {
        $guardians = $member->encarregados;
        $dependents = $member->educandos;
        $responsible = $guardians->first() ?? $member;

        $family = Familia::query()->create([
            'nome' => sprintf('Família %s', $this->memberIdentityDisplayResolver->displayNameOrFallback($responsible, 'Sem nome')),
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

    private function replaceFamilyResponsible(Familia $family, User $responsible): void
    {
        if ($family->responsavel_user_id && (string) $family->responsavel_user_id !== (string) $responsible->id) {
            $family->members()->updateExistingPivot($family->responsavel_user_id, $this->membershipPermissions('familiar'));
        }

        $family->members()->updateExistingPivot($responsible->id, $this->membershipPermissions('responsavel'));
        $family->forceFill(['responsavel_user_id' => $responsible->id])->save();
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
            $attribute => collect($ids)->map(fn ($id) => (string) $id)->filter()->unique()->values()->all(),
        ])->save();
    }
}
