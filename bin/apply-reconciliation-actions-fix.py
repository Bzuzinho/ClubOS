#!/usr/bin/env python3
from pathlib import Path

flow_test = Path('tests/Feature/Financeiro/BankReconciliationSuggestionFlowTest.php')
text = flow_test.read_text()

old_assertion = "$this->assertFalse((bool) $suggestion['is_directly_reconcilable']);"
new_assertion = "$this->assertTrue((bool) $suggestion['is_directly_reconcilable']);"

count = text.count(old_assertion)
if count != 2:
    raise SystemExit(f'Expected 2 legacy direct-reconciliation assertions, found {count}')

text = text.replace(old_assertion, new_assertion)
text = text.replace(
    'public function test_multiple_equal_expenses_never_expose_direct_reconciliation(): void',
    'public function test_multiple_equal_expenses_allow_operator_confirmation_when_fully_allocated(): void',
    1,
)
flow_test.write_text(text)

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
