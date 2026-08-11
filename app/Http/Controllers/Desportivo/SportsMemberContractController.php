<?php

declare(strict_types=1);

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Desportivo\SportsMemberProfileQueryService;
use App\Services\Desportivo\SportsMemberProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SportsMemberContractController extends Controller
{
    public function __construct(
        private readonly SportsMemberProfileQueryService $queryService,
        private readonly SportsMemberProfileService $profileService,
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function show(Request $request, User $member): JsonResponse
    {
        $this->authorizeView($request, $member);

        return response()->json([
            'data' => $this->queryService->forMember($member),
        ]);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        abort_unless($this->accessControlService->canAccessPermission($actor, 'membros.ficha', 'edit'), 403);

        $clubId = app(\App\Services\Desportivo\SportsClubContext::class)->id();

        $data = $request->validate([
            'participations' => ['sometimes', 'array'],
            'participations.*.sports_modality_id' => [
                'required',
                'uuid',
                Rule::exists('sports_modalities', 'id')->where('club_id', $clubId),
            ],
            'participations.*.active' => ['required', 'boolean'],
            'participations.*.starts_at' => ['nullable', 'date'],
            'participations.*.ends_at' => ['nullable', 'date'],
            'participations.*.reason' => ['nullable', 'string', 'max:2000'],

            'age_group_overrides' => ['sometimes', 'array'],
            'age_group_overrides.*.season_id' => [
                'required',
                'uuid',
                Rule::exists('seasons', 'id')->where('club_id', $clubId),
            ],
            'age_group_overrides.*.sports_modality_id' => [
                'required',
                'uuid',
                Rule::exists('sports_modalities', 'id')->where('club_id', $clubId),
            ],
            'age_group_overrides.*.age_group_id' => [
                'nullable',
                'uuid',
                Rule::exists('age_groups', 'id')->where('club_id', $clubId),
            ],
            'age_group_overrides.*.reason' => ['nullable', 'string', 'max:2000'],
            'age_group_overrides.*.effective_at' => ['nullable', 'date'],
            'age_group_overrides.*.end_override' => ['sometimes', 'boolean'],

            'federation_affiliations' => ['sometimes', 'array'],
            'federation_affiliations.*.sports_athlete_participation_id' => ['required', 'uuid'],
            'federation_affiliations.*.sports_modality_id' => ['required', 'uuid'],
            'federation_affiliations.*.sports_federation_id' => [
                'required',
                'uuid',
                Rule::exists('sports_federations', 'id')->where('club_id', $clubId),
            ],
            'federation_affiliations.*.membership_number' => ['nullable', 'string', 'max:120'],
            'federation_affiliations.*.license_number' => ['nullable', 'string', 'max:120'],
            'federation_affiliations.*.starts_at' => ['nullable', 'date'],
            'federation_affiliations.*.ends_at' => ['nullable', 'date'],
            'federation_affiliations.*.active' => ['sometimes', 'boolean'],
            'federation_affiliations.*.notes' => ['nullable', 'string', 'max:2000'],

            'limitations' => ['sometimes', 'array'],
            'limitations.*.action' => ['nullable', Rule::in(['create', 'end'])],
            'limitations.*.id' => ['nullable', 'uuid'],
            'limitations.*.sports_modality_id' => ['nullable', 'uuid'],
            'limitations.*.sports_limitation_type_id' => ['nullable', 'uuid'],
            'limitations.*.starts_at' => ['nullable', 'date'],
            'limitations.*.ends_at' => ['nullable', 'date'],
            'limitations.*.operational_instruction' => ['nullable', 'string', 'max:2000'],

            'legacy_identifiers' => ['sometimes', 'array'],
            'legacy_identifiers.numero_pmb' => ['nullable', 'string', 'max:120'],
            'legacy_identifiers.data_inscricao' => ['nullable', 'date'],
        ]);

        foreach ($data['age_group_overrides'] ?? [] as $index => $override) {
            $end = (bool) ($override['end_override'] ?? false);
            if (! $end && empty($override['age_group_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "age_group_overrides.$index.age_group_id" => 'O escalão é obrigatório para criar um override.',
                ]);
            }
            if (! $end && mb_strlen(trim((string) ($override['reason'] ?? ''))) < 3) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "age_group_overrides.$index.reason" => 'O motivo do override é obrigatório e deve ter pelo menos 3 caracteres.',
                ]);
            }
        }

        $this->profileService->updateFromMemberSurface($member, $data, $actor);

        return response()->json([
            'message' => 'Perfil desportivo atualizado.',
            'data' => $this->queryService->forMember($member->fresh()),
        ]);
    }

    private function authorizeView(Request $request, User $member): void
    {
        $viewer = $request->user();
        abort_unless($viewer !== null, 401);

        if ((string) $viewer->id === (string) $member->id) {
            return;
        }

        if ($viewer->educandos()->whereKey($member->id)->exists()) {
            return;
        }

        abort_unless($this->accessControlService->canAccessPermission($viewer, 'membros.ficha', 'view'), 403);
    }
}
