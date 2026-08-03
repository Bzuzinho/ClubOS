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

    public function test_monthly_fee_generation_uses_shared_csrf_headers_and_preserves_an_empty_end_date(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/FaturasTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('headers: getFinanceiroJsonHeaders(),', $source);
        $this->assertStringContainsString('end_date: dataFimMensalidades || undefined,', $source);
        $this->assertStringContainsString('disabled={generatingMonthlyFees}', $source);
        $this->assertStringContainsString("{generatingMonthlyFees ? 'A gerar...' : 'Gerar Faturas'}", $source);
        $this->assertStringContainsString('const skippedUsers = summary?.skipped_users || [];', $source);
        $this->assertStringContainsString('Membros excluidos:', $source);
        $this->assertStringNotContainsString('getCsrfToken()', $source);
        $this->assertStringNotContainsString('const computedEndDate', $source);
    }

    public function test_invoice_deletion_surfaces_the_backend_validation_message(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/FaturasTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "throw new Error(getFinanceiroRequestErrorMessage(error, 'Erro ao apagar fatura'));",
            $source,
        );
    }
}
