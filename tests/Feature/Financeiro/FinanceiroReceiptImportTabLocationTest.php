<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class FinanceiroReceiptImportTabLocationTest extends TestCase
{
    public function test_financeiro_index_owns_the_receipt_import_tab(): void
    {
        $financeiroIndex = file_get_contents(resource_path('js/Pages/Financeiro/Index.tsx'));
        $receiptImportsTab = file_get_contents(resource_path('js/Pages/Financeiro/ReceiptImportsTab.tsx'));

        $this->assertIsString($financeiroIndex);
        $this->assertIsString($receiptImportsTab);

        $this->assertStringContainsString("'importacao-recibos'", $financeiroIndex);
        $this->assertStringContainsString('Importar recibos', $financeiroIndex);
        $this->assertStringContainsString('ReceiptImportsTab', $financeiroIndex);
        $this->assertStringContainsString('financeiro.importacao_recibos', $financeiroIndex);
        $this->assertStringContainsString('<ReceiptImportsTab canEdit={canEditReceiptImports} />', $financeiroIndex);

        $this->assertStringContainsString("url.searchParams.set('include_options', '1')", $receiptImportsTab);
        $this->assertStringNotContainsString('users: ReceiptImportUserOption[];', $receiptImportsTab);
        $this->assertStringNotContainsString('invoices: ReceiptImportInvoiceOption[];', $receiptImportsTab);
    }
}
