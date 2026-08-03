<?php

namespace Tests\Feature\Financeiro;

use Tests\TestCase;

class BankExpenseCreationDialogContractTest extends TestCase
{
    public function test_bank_expense_action_requests_an_editable_prefilled_description_and_confirms_once(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/BancoTab.tsx'));

        $this->assertStringContainsString('setExpenseDescription(extrato.descricao || \'\');', $source);
        $this->assertStringContainsString('Criar e conciliar despesa', $source);
        $this->assertStringContainsString('Descricao da despesa *', $source);
        $this->assertStringContainsString('descricao: description,', $source);
        $this->assertStringContainsString("expenseCreating ? 'A criar...' : 'Criar e conciliar'", $source);
        $this->assertStringNotContainsString(
            'Criar despesa a partir desta saida bancaria? Esta operacao cria um movimento financeiro associado e concilia o valor automaticamente.',
            $source,
        );
    }
}
