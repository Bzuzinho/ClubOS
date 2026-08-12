<?php

namespace App\Services\Desportivo;

use App\Contracts\Financeiro\CompetitionFinanceGateway;
use App\Models\CompetitionRegistration;
use Illuminate\Support\Facades\DB;

class DeleteCompetitionRegistrationAction
{
    public function __construct(
        private readonly CompetitionFinanceContextService $financeContext,
        private readonly CompetitionFinanceGateway $financeGateway,
    ) {
    }

    public function execute(CompetitionRegistration $competitionRegistration): void
    {
        DB::transaction(function () use ($competitionRegistration): void {
            $registration = CompetitionRegistration::query()
                ->whereKey($competitionRegistration->id)
                ->lockForUpdate()
                ->with('prova.competition')
                ->firstOrFail();

            $competitionId = (string) $registration->prova->competition->id;
            $athleteId = (string) $registration->user_id;

            $registration->delete();

            // Financeiro owns cancellation/recalculation and may throw if the
            // obligation already entered a closed financial lifecycle. The
            // outer transaction then restores the sports registration.
            $this->financeGateway->synchronize(
                $this->financeContext->forAthleteCompetition($competitionId, $athleteId)
            );
        });
    }
}
