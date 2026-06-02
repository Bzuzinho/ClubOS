<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class FinanceiroReceiptImportTabLocationTest extends TestCase
{
    public function test_financeiro_index_no_longer_contains_receipt_import_tab(): void
    {
        $financeiroIndex = file_get_contents(resource_path('js/Pages/Financeiro/Index.tsx'));

        $this->assertIsString($financeiroIndex);
        $this->assertStringNotContainsString('importacao-recibos', $financeiroIndex);
        $this->assertStringNotContainsString('Importar Recibos', $financeiroIndex);
        $this->assertStringNotContainsString('ReceiptImportsTab', $financeiroIndex);
    }
}
