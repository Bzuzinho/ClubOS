<?php

namespace Tests\Feature\Portal;

use Tests\TestCase;

class PortalCurrentAccountLanguageContractTest extends TestCase
{
    public function test_portal_payments_uses_operational_language_and_hides_manual_legacy_balance_card(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Portal/Payments.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Conta Corrente', $source);
        $this->assertStringContainsString('Em aberto', $source);
        $this->assertStringContainsString('Crédito disponível', $source);
        $this->assertStringNotContainsString('Saldo manual legado', $source);
        $this->assertStringNotContainsString('Dívida líquida', $source);
        $this->assertStringNotContainsString('Dívida bruta', $source);
    }

    public function test_portal_family_uses_operational_language_and_hides_manual_legacy_balance_card(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Portal/Family.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Conta Corrente da família', $source);
        $this->assertStringContainsString('Conta Corrente', $source);
        $this->assertStringContainsString('Em aberto', $source);
        $this->assertStringContainsString('Crédito disponível', $source);
        $this->assertStringNotContainsString('Saldo manual legado', $source);
        $this->assertStringNotContainsString('Dívida líquida', $source);
        $this->assertStringNotContainsString('Dívida bruta', $source);
    }
}