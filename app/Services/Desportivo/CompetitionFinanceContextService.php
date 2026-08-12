<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Contracts\Financeiro\CompetitionFinanceRequest;
use App\Models\Competition;
use App\Models\CompetitionRegistration;
use Illuminate\Support\Str;

final class CompetitionFinanceContextService
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    /** @param list<string> $additionalLegacyInvoiceIds */
    public function forAthleteCompetition(
        string $competitionId,
        string $athleteId,
        array $additionalLegacyInvoiceIds = [],
    ): CompetitionFinanceRequest
    {
        $clubId = $this->clubContext->id();

        $competition = Competition::query()
            ->forClub($clubId)
            ->with(['evento', 'eventProjection.event'])
            ->findOrFail($competitionId);

        $registrationModels = CompetitionRegistration::query()
            ->where('user_id', $athleteId)
            ->whereHas('prova', fn ($query) => $query->where('competicao_id', $competition->id))
            ->with('prova')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $registrations = $registrationModels
            ->map(function (CompetitionRegistration $registration): array {
                $prova = $registration->prova;
                $label = trim(implode(' ', array_filter([
                    $prova?->estilo,
                    $prova?->distancia_m ? $prova->distancia_m.'m' : null,
                    $prova?->genero,
                ])));

                return [
                    'registration_id' => (string) $registration->id,
                    'state' => Str::lower(trim((string) $registration->estado)),
                    'amount_override' => $registration->valor_inscricao !== null
                        ? (float) $registration->valor_inscricao
                        : null,
                    'age_group_id' => $prova?->escalao_id ? (string) $prova->escalao_id : null,
                    'label' => $label !== '' ? $label : 'Prova',
                ];
            })
            ->values()
            ->all();

        $legacyEvent = $competition->eventProjection?->event ?: $competition->evento;
        $legacyInvoiceIds = $registrationModels
            ->pluck('fatura_id')
            ->merge($additionalLegacyInvoiceIds)
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        return new CompetitionFinanceRequest(
            clubId: $clubId,
            competitionId: (string) $competition->id,
            athleteId: $athleteId,
            competitionName: (string) $competition->nome,
            competitionDate: $competition->data_inicio?->toDateString() ?? now()->toDateString(),
            registrations: $registrations,
            legacyEventFee: $legacyEvent?->taxa_inscricao !== null ? (float) $legacyEvent->taxa_inscricao : null,
            legacyCostCenterId: $legacyEvent?->centro_custo_id ? (string) $legacyEvent->centro_custo_id : null,
            legacyEventTitle: filled($legacyEvent?->titulo) ? (string) $legacyEvent->titulo : null,
            legacyInvoiceIds: $legacyInvoiceIds,
        );
    }
}
