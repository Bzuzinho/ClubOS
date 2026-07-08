<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreCompetitionRegistrationRequest;
use App\Models\CompetitionRegistration;
use App\Services\Desportivo\CreateCompetitionRegistrationAction;
use App\Services\Desportivo\DeleteCompetitionRegistrationAction;
use Illuminate\Http\JsonResponse;

class CompetitionRegistrationController extends Controller
{
    public function __construct(
        private CreateCompetitionRegistrationAction $createCompetitionRegistrationAction,
        private DeleteCompetitionRegistrationAction $deleteCompetitionRegistrationAction,
    ) {
    }

    public function index(): JsonResponse
    {
        $rows = CompetitionRegistration::with(['prova.competition', 'athlete', 'fatura.items'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json($rows);
    }

    public function store(StoreCompetitionRegistrationRequest $request): JsonResponse
    {
        $registration = $this->createCompetitionRegistrationAction->execute($request->validated());

        return response()->json($registration->loadMissing(['prova.competition', 'athlete', 'fatura.items']), 201);
    }

    public function destroy(CompetitionRegistration $competitionRegistration): JsonResponse
    {
        $this->deleteCompetitionRegistrationAction->execute($competitionRegistration);

        return response()->json(['message' => 'Inscricao removida']);
    }
}
