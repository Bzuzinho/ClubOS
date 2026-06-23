<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AthleteController extends Controller
{
    /**
     * GET /api/desportivo/athletes
     * Retorna lista de atletas do sistema.
     */
    public function index(): JsonResponse
    {
        $athletes = User::with(['athleteSportsData.escalao'])
            ->where('estado', 'ativo')
            ->whereJsonContains('tipo_membro', 'atleta')
            ->select([
                'id',
                'nome_completo',
                'email',
                'estado',
                'tipo_membro',
            ])
            ->orderBy('nome_completo')
            ->limit(200)
            ->get()
            ->map(fn($user) => [
                'id' => $user->id,
                'nome_completo' => $user->nome_completo,
                'email' => $user->email,
                'estado' => $user->estado,
                'escalao' => $user->athleteSportsData?->escalao?->nome
                    ? [$user->athleteSportsData->escalao->nome]
                    : [],
                'tipo_membro' => $user->tipo_membro,
                'data_atestado_medico' => $user->athleteSportsData?->data_atestado_medico,
                'medico_ok' => !is_null($user->athleteSportsData?->data_atestado_medico),
            ]);

        return response()->json($athletes);
    }

    /**
     * GET /api/desportivo/athletes/{id}
     * Retorna dados detalhados de um atleta.
     */
    public function show(User $athlete): JsonResponse
    {
        $athlete->loadMissing(['athleteSportsData.escalao', 'dadosPessoais']);

        $birthDate = $athlete->dadosPessoais?->data_nascimento ?? $athlete->data_nascimento;

        return response()->json([
            'id' => $athlete->id,
            'nome_completo' => $athlete->nome_completo,
            'email' => $athlete->email,
            'estado' => $athlete->estado,
            'escalao' => $athlete->athleteSportsData?->escalao?->nome
                ? [$athlete->athleteSportsData->escalao->nome]
                : [],
            'tipo_membro' => $athlete->tipo_membro,
            'data_atestado_medico' => $athlete->athleteSportsData?->data_atestado_medico,
            'data_nascimento' => $birthDate,
            'telefone' => $athlete->telefone,
        ]);
    }
}
