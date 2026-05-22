<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class FaturasTabFlowContractTest extends TestCase
{
    public function test_financeiro_index_passes_full_invoice_state_to_faturas_tab(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/Index.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('faturas={faturasState}', $source);
        $this->assertStringNotContainsString('faturas={mensalidadesFaturasState}', $source);
    }

    public function test_faturas_tab_uses_only_active_payment_methods_and_bank_required_guardrails(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/FaturasTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('const activePaymentMethods = useMemo(() => {', $source);
        $this->assertStringContainsString('return activePaymentMethods.find((method) => method.codigo === paymentMethod) || null;', $source);
        $this->assertStringContainsString('activePaymentMethods.map((method) => (', $source);
        $this->assertStringContainsString('Nao existem linhas bancarias disponiveis para conciliar.', $source);
        $this->assertStringContainsString('if (paymentRequiresBankStatement && !hasAvailableBankStatements)', $source);
        $this->assertStringContainsString('{paymentRequiresBankStatement ? (', $source);
        $this->assertStringContainsString('disabled={!canConfirmPayment}', $source);
    }

    public function test_faturas_tab_keeps_the_canonical_payment_route_and_does_not_call_legacy_movement_liquidation(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/FaturasTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("route('financeiro.payments.allocate')", $source);
        $this->assertStringNotContainsString("route('financeiro.movimentos.liquidar')", $source);
        $this->assertStringNotContainsString('liquidarMovimento', $source);
        $this->assertStringNotContainsString('dialogRecibo', $source);
    }
}