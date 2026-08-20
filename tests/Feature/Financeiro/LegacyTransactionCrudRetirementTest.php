<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use Tests\TestCase;

class LegacyTransactionCrudRetirementTest extends TestCase
{
    public function test_legacy_transaction_and_category_endpoints_are_gone(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $cases = [
            ['GET', '/financeiro/transacoes', null],
            ['POST', '/financeiro/transacoes', []],
            ['PUT', '/financeiro/transacoes/legacy-id', []],
            ['DELETE', '/financeiro/transacoes/legacy-id', null],
            ['GET', '/financeiro/categorias', null],
            ['POST', '/financeiro/categorias', []],
            ['PUT', '/financeiro/categorias/legacy-id', []],
            ['DELETE', '/financeiro/categorias/legacy-id', null],
        ];

        foreach ($cases as [$method, $uri, $payload]) {
            $response = match ($method) {
                'GET' => $this->getJson($uri),
                'POST' => $this->postJson($uri, $payload ?? []),
                'PUT' => $this->putJson($uri, $payload ?? []),
                'DELETE' => $this->deleteJson($uri),
            };

            $response
                ->assertStatus(410)
                ->assertJsonStructure(['message', 'canonical_route']);
        }
    }

    public function test_legacy_controllers_no_longer_import_or_persist_legacy_financial_models(): void
    {
        $transactionController = file_get_contents(app_path('Http/Controllers/TransacoesController.php'));
        $categoryController = file_get_contents(app_path('Http/Controllers/CategoriasFinanceirasController.php'));

        $this->assertIsString($transactionController);
        $this->assertIsString($categoryController);

        foreach ([
            'App\\Models\\Transaction',
            'StoreTransactionRequest',
            'UpdateTransactionRequest',
            'Transaction::create',
            '->update($data)',
            '->delete()',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $transactionController);
        }

        foreach ([
            'App\\Models\\FinancialCategory',
            'StoreFinancialCategoryRequest',
            'UpdateFinancialCategoryRequest',
            'FinancialCategory::create',
            '->update($request->validated())',
            '->delete()',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $categoryController);
        }
    }
}
