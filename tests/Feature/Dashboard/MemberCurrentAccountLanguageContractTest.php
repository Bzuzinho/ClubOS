<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

class MemberCurrentAccountLanguageContractTest extends TestCase
{
    public function test_member_dashboard_tab_uses_operational_current_account_labels(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/DashboardTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Conta Corrente', $source);
        $this->assertStringContainsString('Mensalidades', $source);
        $this->assertStringContainsString('Movimentos', $source);
        $this->assertStringContainsString('Valor Pago', $source);
        $this->assertStringNotContainsString('Saldo manual legado', $source);
        $this->assertStringNotContainsString('Dívida líquida', $source);
        $this->assertStringNotContainsString('Dívida bruta', $source);
    }

    public function test_member_financial_tab_uses_operational_current_account_labels_and_guidance(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/FinancialTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Conta Corrente', $source);
        $this->assertStringContainsString('Mensalidades', $source);
        $this->assertStringContainsString('Movimentos', $source);
        $this->assertStringContainsString('Valor Pago', $source);
        $this->assertStringContainsString('Ajustes operacionais', $source);
        $this->assertStringContainsString('Movimento manual no Financeiro', $source);
        $this->assertStringNotContainsString('Saldo manual legado', $source);
        $this->assertStringNotContainsString('Dívida líquida', $source);
        $this->assertStringNotContainsString('Dívida bruta', $source);
    }
}