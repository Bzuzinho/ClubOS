<?php

namespace Tests\Feature\Configuracoes;

use Tests\TestCase;

class ConfiguracoesReceiptImportTabTest extends TestCase
{
    public function test_configuracoes_no_longer_exposes_receipt_import_operation_or_payload(): void
    {
        $configuracoesIndex = file_get_contents(resource_path('js/Pages/Configuracoes/Index.tsx'));
        $controller = file_get_contents(app_path('Http/Controllers/ConfiguracoesController.php'));

        $this->assertIsString($configuracoesIndex);
        $this->assertIsString($controller);

        $this->assertStringNotContainsString('financeiro-importacao-recibos', $configuracoesIndex);
        $this->assertStringNotContainsString('ReceiptImportsTab', $configuracoesIndex);
        $this->assertStringNotContainsString('receiptImportUsers', $configuracoesIndex);
        $this->assertStringNotContainsString('receiptImportInvoices', $configuracoesIndex);

        $this->assertStringNotContainsString("'receiptImportUsers'", $controller);
        $this->assertStringNotContainsString("'receiptImportInvoices'", $controller);
    }
}
