<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProvaTipo;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Http\JsonResponse;

class ProvaTiposController extends Controller
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    public function index(): JsonResponse
    {
        $provaTipos = ProvaTipo::query()
            ->forClub($this->clubContext->id())
            ->ativo()
            ->ordenado()
            ->get(['id', 'codigo', 'nome', 'distancia', 'unidade', 'modalidade', 'ativo']);

        return response()->json($provaTipos);
    }
}
