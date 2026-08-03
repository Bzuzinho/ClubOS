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
    """        $santiagoInvoice = $this->createInvoice($santiago, 30.00, 'mensalidade', '2026-01-10', [
            'mes' => '2026-01',
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'estado_pagamento' => 'vencido',
        ]);
""",
    """        $santiagoInvoice = $this->createInvoice($santiago, 55.00, 'mensalidade', '2026-01-10', [
            'mes' => '2026-01',
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'estado_pagamento' => 'vencido',
        ]);
""",
)
replace_once(
    suggestion_test,
    """        $statement = $this->createBankStatement(55.00, 'TRF CR INTRAB 274 DE PEDRO GONZAGA');
        $suggestion = $this->generateSuggestion($admin, $statement, [$santiagoInvoice]);
""",
    """        $statement = $this->createBankStatement(55.00, 'TRF CR INTRAB 274 DE PEDRO GONZAGA');
        $this->learnStatementDescription(
            $statement,
            $santiago->id,
            $santiago->families->first()?->id,
            $admin,
        );
        $suggestion = $this->generateSuggestion($admin, $statement, [$santiagoInvoice]);
""",
)
replace_once(
    suggestion_test,
    """        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $ritaInvoice->id,
            'amount' => 25.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
        $repositoryEntry = BankReconciliationRepository::query()
""",
    """        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $ritaInvoice->id,
            'amount' => 25.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $santiagoInvoice->id,
            'estado_pagamento' => 'parcial',
            'valor_pago' => 30.00,
            'valor_em_aberto' => 25.00,
        ]);
        $repositoryEntry = BankReconciliationRepository::query()
""",
)

print("PR84 CI fixes applied")
