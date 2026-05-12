<?php

namespace App\Http\Controllers;

use App\Services\Financeiro\FinanceReportService;
use Illuminate\Http\JsonResponse;

class RelatoriosFinanceirosController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $financeReportService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->financeReportService->summary());
    }
}
