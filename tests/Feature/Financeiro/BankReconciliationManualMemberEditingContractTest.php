<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class BankReconciliationManualMemberEditingContractTest extends TestCase
{
    public function test_suggestion_ui_exposes_manual_member_add_and_remove_controls(): void
    {
        $bankTab = file_get_contents(resource_path('js/Pages/Financeiro/BancoTab.tsx'));
        $dialog = file_get_contents(resource_path('js/Pages/Financeiro/BankStatementReconciliationDialog.tsx'));

        $this->assertStringContainsString('Abrir conciliação manual', $bankTab);
        $this->assertStringContainsString('void loadOpenInvoices(searchTerm)', $dialog);
        $this->assertStringContainsString('Adicionar membro', $dialog);
        $this->assertStringContainsString('Retirar membro', $dialog);
        $this->assertStringContainsString('removeInvoiceMember(group.userId)', $dialog);
    }
}
