<?php

namespace App\Services\Desportivo;

use App\Contracts\Financeiro\CompetitionFinanceGateway;
use App\Models\CompetitionRegistration;
use App\Models\Prova;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCompetitionRegistrationAction
{
    public function __construct(
        private readonly CompetitionFinanceContextService $financeContext,
        private readonly CompetitionFinanceGateway $financeGateway,
    ) {
    }

    public function execute(array $validatedData): CompetitionRegistration
    {
        return DB::transaction(function () use ($validatedData): CompetitionRegistration {
            $prova = Prova::query()->with('competition')->find($validatedData['prova_id']);
            if (! $prova || ! $prova->competition) {
                throw ValidationException::withMessages([
                    'prova_id' => 'A prova indicada nao foi encontrada.',
                ]);
            }

            $registration = CompetitionRegistration::query()->create([
                'prova_id' => $validatedData['prova_id'],
                'user_id' => $validatedData['user_id'],
                'estado' => $validatedData['estado'] ?? 'inscrito',
                'valor_inscricao' => $validatedData['valor_inscricao'] ?? null,
            ]);

            $this->financeGateway->synchronize(
                $this->financeContext->forAthleteCompetition(
                    (string) $prova->competition->id,
                    (string) $registration->user_id,
                )
            );

            return $registration->fresh(['prova.competition', 'athlete']);
        });
    }
}
