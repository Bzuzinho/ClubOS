<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class BankReconciliationExactAllocationActionsContractTest extends TestCase
{
    public function test_fully_allocated_suggestion_exposes_confirmation_without_removing_direct_safety_gate(): void
    {
        $bankTab = file_get_contents(resource_path('js/Pages/Financeiro/BancoTab.tsx'));
        $controller = file_get_contents(app_path('Http/Controllers/Financeiro/BankReconciliationSuggestionController.php'));
        $service = file_get_contents(app_path('Services/Financeiro/BankReconciliationSuggestionService.php'));

        $this->assertStringNotContainsString('suggestion.score === 100', $bankTab);
        $this->assertStringContainsString('const isFullyAllocated =', $bankTab);
        $this->assertStringContainsString('body: JSON.stringify(confirmationPayload)', $bankTab);
        $this->assertStringContainsString('invoices: allocations', $bankTab);
        $this->assertStringContainsString('movements: allocations', $bankTab);
        $this->assertStringContainsString('(int) $suggestion->score === 100', $controller);
        $this->assertStringContainsString('(int) $suggestion->score !== 100', $service);
        $this->assertStringContainsString('Abrir conciliação manual', $bankTab);
    }
}
