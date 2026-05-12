<?php

namespace App\Http\Controllers;

use App\Services\Financeiro\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RelatoriosFinanceirosController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $financeReportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'centro_custo_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'string'],
            'tipo' => ['nullable', Rule::in(['receita', 'despesa'])],
            'origem_modulo' => ['nullable', 'string', 'max:100'],
            'origem_tipo' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'summary' => $this->financeReportService->summary($validated),
            ...$this->financeReportService->reports($validated),
        ]);
    }
}
