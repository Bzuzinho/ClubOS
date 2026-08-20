<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TransacoesController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->retired();
    }

    public function store(): JsonResponse
    {
        return $this->retired();
    }

    public function update(string $transaction): JsonResponse
    {
        return $this->retired();
    }

    public function destroy(string $transaction): JsonResponse
    {
        return $this->retired();
    }

    private function retired(): JsonResponse
    {
        return response()->json([
            'message' => 'O CRUD legacy de transações foi aposentado. Utilize os Movimentos do módulo Financeiro.',
            'canonical_route' => route('financeiro.index'),
        ], 410);
    }
}
