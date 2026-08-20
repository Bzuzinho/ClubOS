<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class CategoriasFinanceirasController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->retired();
    }

    public function store(): JsonResponse
    {
        return $this->retired();
    }

    public function update(string $category): JsonResponse
    {
        return $this->retired();
    }

    public function destroy(string $category): JsonResponse
    {
        return $this->retired();
    }

    private function retired(): JsonResponse
    {
        return response()->json([
            'message' => 'O CRUD legacy de categorias financeiras foi aposentado. Utilize a classificação e os centros de custo do Financeiro canónico.',
            'canonical_route' => route('financeiro.index'),
        ], 410);
    }
}
