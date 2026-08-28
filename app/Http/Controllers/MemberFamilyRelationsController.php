<?php

namespace App\Http\Controllers;

use App\Models\Familia;
use App\Models\User;
use App\Services\Family\FamilyRelationshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly FamilyRelationshipService $familyRelationshipService,
    ) {
    }

    public function storeGuardian(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'guardian_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $guardian = User::query()->findOrFail($validated['guardian_id']);

        if ($guardian->is($member)) {
            throw ValidationException::withMessages([
                'guardian_id' => 'Um membro não pode ser encarregado de educação de si próprio.',
            ]);
        }

        $this->familyRelationshipService->associateGuardian($member, $guardian);

        return back()->with('success', 'Encarregado de educação associado com sucesso.');
    }

    public function destroyGuardian(User $member, User $guardian): RedirectResponse
    {
        $this->familyRelationshipService->removeGuardian($member, $guardian);

        return back()->with('success', 'Encarregado de educação removido.');
    }

    public function storeFamilyMember(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'family_id' => ['nullable', 'uuid', 'exists:familias,id'],
            'member_id' => ['required', 'uuid', 'exists:users,id'],
            'papel_na_familia' => ['required', Rule::in(self::FAMILY_ROLES)],
        ]);

        $relatedMember = User::query()->findOrFail($validated['member_id']);

        if ($relatedMember->is($member)) {
            throw ValidationException::withMessages([
                'member_id' => 'Este membro já é o titular desta ficha.',
            ]);
        }

        $this->familyRelationshipService->addFamilyMember(
            $member,
            $relatedMember,
            (string) $validated['papel_na_familia'],
            $validated['family_id'] ?? null,
        );

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

        $this->familyRelationshipService->updateFamilyMemberRole(
            $member,
            $family,
            $familyMember,
            (string) $validated['papel_na_familia'],
        );

        return back()->with('success', 'Relação familiar atualizada.');
    }

    public function destroyFamilyMember(User $member, Familia $family, User $familyMember): RedirectResponse
    {
        if ($member->is($familyMember)) {
            throw ValidationException::withMessages([
                'member_id' => 'Não pode remover desta família o membro cuja ficha está aberta.',
            ]);
        }

        $this->familyRelationshipService->removeFamilyMember($member, $family, $familyMember);

        return back()->with('success', 'Membro removido da família.');
    }
}
