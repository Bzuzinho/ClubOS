<?php

namespace Tests\Feature\Loja;

use App\Http\Controllers\AdminLojaEncomendaController;
use App\Http\Controllers\AdminLojaHeroController;
use App\Http\Controllers\AdminLojaProdutoController;
use App\Http\Controllers\LojaCarrinhoController;
use App\Http\Controllers\LojaController;
use App\Http\Controllers\LojaEncomendaController;
use App\Http\Controllers\LojaProdutoController;
use App\Services\Loja\LojaCarrinhoService;
use App\Services\Loja\LojaEncomendaService;
use App\Services\Loja\LojaHeroService;
use App\Services\Loja\StorefrontCatalogService;
use App\Support\LegacyStoreCatalogGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyStoreCatalogGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_lists_forbidden_legacy_store_tokens(): void
    {
        $guard = app(LegacyStoreCatalogGuard::class);

        $this->assertSame([
            'loja_produtos',
            'loja_produto_variantes',
            'loja_produto_id',
            'loja_produto_variante_id',
            'use app\\models\\lojaproduto;',
            'use app\\models\\lojaprodutovariante;',
            'lojaproduto::',
            'lojaprodutovariante::',
        ], $guard->forbiddenSourceTokens());
    }

    public function test_active_store_runtime_classes_do_not_reference_legacy_store_catalog(): void
    {
        $guard = app(LegacyStoreCatalogGuard::class);

        $guard->assertSourceIsLegacyFree(StorefrontCatalogService::class);
        $guard->assertSourceIsLegacyFree(LojaHeroService::class);
        $guard->assertSourceIsLegacyFree(LojaCarrinhoService::class);
        $guard->assertSourceIsLegacyFree(LojaEncomendaService::class);
        $guard->assertSourceIsLegacyFree(LojaController::class);
        $guard->assertSourceIsLegacyFree(LojaProdutoController::class);
        $guard->assertSourceIsLegacyFree(LojaCarrinhoController::class);
        $guard->assertSourceIsLegacyFree(LojaEncomendaController::class);
        $guard->assertSourceIsLegacyFree(AdminLojaProdutoController::class);
        $guard->assertSourceIsLegacyFree(AdminLojaHeroController::class);
        $guard->assertSourceIsLegacyFree(AdminLojaEncomendaController::class);

        $this->assertTrue(true);
    }
}