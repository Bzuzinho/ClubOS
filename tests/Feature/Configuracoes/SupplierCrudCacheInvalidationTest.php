<?php

namespace Tests\Feature\Configuracoes;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SupplierCrudCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_create_clears_logistics_configuration_cache(): void
    {
        $user = User::factory()->create();

        Cache::put('configuracoes:logistica', [
            'products' => [],
            'sponsors' => [],
            'suppliers' => [],
            'itemCategories' => [],
        ], now()->addMinutes(5));

        $this->actingAs($user)
            ->post(route('configuracoes.fornecedores.store'), [
                'nome' => 'Fornecedor Cache Teste',
                'nif' => '123456789',
                'email' => 'fornecedor@example.com',
                'ativo' => true,
            ])
            ->assertRedirect(route('configuracoes'));

        $this->assertDatabaseHas('suppliers', [
            'nome' => 'Fornecedor Cache Teste',
            'nif' => '123456789',
            'email' => 'fornecedor@example.com',
            'ativo' => true,
        ]);

        $this->assertFalse(Cache::has('configuracoes:logistica'));
        $this->assertNotNull(Supplier::query()->where('nome', 'Fornecedor Cache Teste')->value('id'));
    }
}