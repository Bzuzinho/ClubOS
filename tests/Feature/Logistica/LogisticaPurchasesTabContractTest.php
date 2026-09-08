<?php

namespace Tests\Feature\Logistica;

use Tests\TestCase;

class LogisticaPurchasesTabContractTest extends TestCase
{
    public function test_logistics_exposes_purchases_tab_and_preserves_legacy_supplier_tab_links(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Logistica/Index.tsx'));

        $this->assertStringContainsString('TabsTrigger value="compras">Compras', $source);
        $this->assertStringContainsString('TabsContent value="compras"', $source);
        $this->assertStringContainsString("tab === 'fornecedores' ? 'compras' : tab", $source);
        $this->assertStringNotContainsString('TabsTrigger value="fornecedores">Fornecedores', $source);
    }
}
