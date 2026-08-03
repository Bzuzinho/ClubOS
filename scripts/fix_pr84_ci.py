from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file_path = Path(path)
    content = file_path.read_text(encoding="utf-8")
    if old not in content:
        raise RuntimeError(f"Expected block not found in {path}")
    file_path.write_text(content.replace(old, new, 1), encoding="utf-8")
    print(f"patched {path}")


member_import = "app/Services/Members/MemberImportService.php"
replace_once(
    member_import,
    "use Illuminate\\Support\\Facades\\Hash;\nuse Illuminate\\Support\\Str;",
    "use Illuminate\\Support\\Facades\\Hash;\nuse Illuminate\\Support\\Facades\\Schema;\nuse Illuminate\\Support\\Str;",
)
replace_once(
    member_import,
    """        $existingNifs = DadosPessoais::query()
            ->whereNotNull('nif')
            ->pluck('nif')
            ->merge(
                User::query()
                    ->whereNotNull('nif')
                    ->pluck('nif')
            )
            ->map(fn ($value) => UniqueMemberNif::normalize($value))
            ->filter()
            ->flip();
""",
    """        $canonicalNifs = DadosPessoais::query()
            ->whereNotNull('nif')
            ->get(['nif'])
            ->map(fn (DadosPessoais $personalData) => $personalData->getAttribute('nif'));

        $legacyNifs = Schema::hasColumn('users', 'nif')
            ? User::query()
                ->whereNotNull('nif')
                ->get(['nif'])
                ->map(fn (User $legacyUser) => $legacyUser->getAttribute('nif'))
            : collect();

        $existingNifs = $canonicalNifs
            ->merge($legacyNifs)
            ->map(fn ($value) => UniqueMemberNif::normalize($value))
            ->filter()
            ->flip();
""",
)

suggestion_test = "tests/Feature/Financeiro/BankReconciliationSuggestionFlowTest.php"
replace_once(
    suggestion_test,
    """        $statement = $this->createBankStatement(55.00, 'TRF CR INTRAB 274 DE PEDRO GONZAGA');
        $suggestion = $this->generateSuggestion($admin, $statement, [$santiagoInvoice]);
""",
    """        $statement = $this->createBankStatement(55.00, 'TRF CR INTRAB 274 DE PEDRO GONZAGA');
        $suggestion = BankReconciliationSuggestion::create([
            'bank_statement_id' => $statement->id,
            'user_id' => $santiago->id,
            'family_id' => $santiago->families->first()?->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 85,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_HIGH,
            'total_bank_amount' => 55.00,
            'total_allocated_amount' => 30.00,
            'unallocated_amount' => 25.00,
            'suggested_allocations' => [[
                'invoice_id' => $santiagoInvoice->id,
                'amount' => 30.00,
                'reason' => 'contexto inicial do membro sugerido',
            ]],
            'matched_rules' => ['manual_assisted_context_seed'],
            'explanation' => 'Sugestao semeada para validar a inclusao manual de outra fatura elegivel.',
            'metadata' => [
                'allocation_signature' => $this->makeTestAllocationSignature($santiagoInvoice->id, 30.00),
                'candidate_invoice_ids' => [$santiagoInvoice->id],
            ],
        ]);
""",
)

print("PR84 CI fixes applied")
