<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class BankReconciliationExactAllocationActionsContractTest extends TestCase
{
    public function test_fully_allocated_suggestion_is_not_blocked_by_confidence_score(): void
    {
        $bankTab = file_get_contents(resource_path('js/Pages/Financeiro/BancoTab.tsx'));
        $controller = file_get_contents(app_path('Http/Controllers/Financeiro/BankReconciliationSuggestionController.php'));
        $service = file_get_contents(app_path('Services/Financeiro/BankReconciliationSuggestionService.php'));

        $this->assertStringNotContainsString('suggestion.score === 100', $bankTab);
        $this->assertStringNotContainsString('(int) $suggestion->score === 100', $controller);
        $this->assertStringNotContainsString('(int) $suggestion->score !== 100', $service);
        $this->assertStringContainsString('round((float) $suggestion->unallocated_amount, 2) <= 0.009', $controller);
        $this->assertStringContainsString('collect((array) $suggestion->suggested_allocations)->isNotEmpty()', $controller);
        $this->assertStringContainsString('Abrir conciliação manual', $bankTab);
    }
}
