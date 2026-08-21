<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class LegacyInvoiceEditCompatibilityTest extends TestCase
{
    public function test_legacy_invoice_edit_page_redirects_to_the_canonical_invoices_workspace(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Financeiro/Edit.tsx'));

        $this->assertIsString($page);
        $this->assertStringContainsString("router.visit('/financeiro?tab=faturas'", $page);
        $this->assertStringContainsString('replace: true', $page);
        $this->assertStringNotContainsString('Em desenvolvimento', $page);
        $this->assertStringNotContainsString('Pagina Financeiro (Edit)', $page);
    }
}
