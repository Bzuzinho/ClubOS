#!/usr/bin/env python3
from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"Expected exactly one match in {path}, found {count}: {old[:120]!r}")
    file.write_text(text.replace(old, new, 1))


replace_once(
    'resources/js/Pages/Financeiro/BancoTab.tsx',
    """      suggestion
      && suggestion.is_directly_reconcilable
      && toNumber(suggestion.unallocated_amount) <= 0.009
      && (suggestion.suggested_allocations || []).length > 0
""",
    """      suggestion
      && toNumber(suggestion.unallocated_amount) <= 0.009
      && (suggestion.suggested_allocations || []).length > 0
""",
)

replace_once(
    'resources/js/Pages/Financeiro/BancoTab.tsx',
    """  const handleConfirmSuggestion = async (suggestion: BankReconciliationSuggestion) => {
    if (suggestion.assisted_allocation_context && !suggestion.is_directly_reconcilable) {
      openAssistedSuggestionDialog(suggestion);
      return;
    }

    if (!suggestion.is_directly_reconcilable) {
      toast.error('A conciliacao direta exige que o valor esteja totalmente atribuido.');
      return;
    }

    setSuggestionActionId(suggestion.id);
""",
    """  const handleConfirmSuggestion = async (suggestion: BankReconciliationSuggestion) => {
    const allocations = suggestion.suggested_allocations || [];
    const isFullyAllocated = toNumber(suggestion.unallocated_amount) <= 0.009 && allocations.length > 0;

    if (!isFullyAllocated) {
      if (suggestion.assisted_allocation_context) {
        openAssistedSuggestionDialog(suggestion);
      } else {
        toast.error('A conciliacao exige que o valor esteja totalmente atribuido.');
      }
      return;
    }

    const confirmationPayload = suggestion.is_directly_reconcilable
      ? { create_credit: false }
      : {
          invoices: allocations
            .filter((allocation) => Boolean(allocation.invoice_id))
            .map((allocation) => ({
              invoice_id: allocation.invoice_id,
              amount: toNumber(allocation.amount),
              notes: allocation.reason ?? suggestion.explanation,
            })),
          movements: allocations
            .filter((allocation) => Boolean(allocation.movement_id))
            .map((allocation) => ({
              movement_id: allocation.movement_id,
              amount: toNumber(allocation.amount),
              centro_custo_id: allocation.movement?.centro_custo_id ?? null,
              notes: allocation.reason ?? suggestion.explanation,
            })),
        };

    setSuggestionActionId(suggestion.id);
""",
)

replace_once(
    'resources/js/Pages/Financeiro/BancoTab.tsx',
    """        body: JSON.stringify({
          create_credit: suggestion.unallocated_amount > 0.009,
        }),
""",
    """        body: JSON.stringify(confirmationPayload),
""",
)

replace_once(
    'app/Http/Controllers/Financeiro/BankReconciliationSuggestionController.php',
    """        $payload['is_directly_reconcilable'] = round((float) $suggestion->unallocated_amount, 2) <= 0.009
            && collect((array) $suggestion->suggested_allocations)->isNotEmpty();
""",
    """        $payload['is_directly_reconcilable'] = (int) $suggestion->score === 100
            && round((float) $suggestion->unallocated_amount, 2) <= 0.009
            && collect((array) $suggestion->suggested_allocations)->isNotEmpty();
""",
)

replace_once(
    'app/Services/Financeiro/BankReconciliationSuggestionService.php',
    """            if (
                round((float) $suggestion->unallocated_amount, 2) > 0.009
                || collect((array) $suggestion->suggested_allocations)->isEmpty()
            ) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A conciliacao direta exige que o valor esteja totalmente atribuido e tenha alocacoes validas. Use a alocacao assistida.',
                ]);
            }
""",
    """            if (
                (int) $suggestion->score !== 100
                || round((float) $suggestion->unallocated_amount, 2) > 0.009
            ) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A conciliacao direta exige uma sugestao exata com score de 100%. Use a alocacao assistida.',
                ]);
            }
""",
)

Path('tests/Feature/Financeiro/BankReconciliationExactAllocationActionsContractTest.php').write_text("""<?php

namespace Tests\\Feature\\Financeiro;

use Tests\\TestCase;

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
""")

for path in [
    '.github/reconciliation-patch-request.txt',
    '.github/reconciliation-patch-request-final.txt',
    '.github/reconciliation-patch-request-final3.txt',
    '.github/workflows/reconciliation-patch-runner.yml',
    '.github/workflows/reconciliation-comment-runner.yml',
    '.github/workflows/reconciliation-opened-runner.yml',
    '.github/workflows/reconciliation-pr-runner.yml',
    '.github/workflows/reconciliation-sync-runner.yml',
    'bin/apply-reconciliation-actions-fix.py',
]:
    Path(path).unlink(missing_ok=True)
