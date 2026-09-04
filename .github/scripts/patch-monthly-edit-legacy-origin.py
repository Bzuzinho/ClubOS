from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected anchor once, found {count}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


faturas = 'resources/js/Pages/Financeiro/FaturasTab.tsx'
replace_once(
    faturas,
    """          centro_custo_id: faturaAtualizada.centro_custo_id || undefined,\n          observacoes: faturaAtualizada.observacoes || undefined,\n          origem_tipo: faturaAtualizada.origem_tipo || null,\n          origem_id: faturaAtualizada.origem_id || null,\n          oculta: faturaAtualizada.oculta || false,""",
    """          centro_custo_id: faturaAtualizada.centro_custo_id || undefined,\n          observacoes: faturaAtualizada.observacoes || undefined,\n          oculta: faturaAtualizada.oculta || false,""",
)

request = 'app/Http/Requests/UpdateInvoiceRequest.php'
replace_once(
    request,
    "            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual,monthly_fee'],",
    "            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual,monthly_fee,monthly_fee_legacy'],",
)

service = 'app/Services/Financeiro/ManualInvoiceService.php'
replace_once(
    service,
    """                'tipo' => $data['tipo'],\n                'origem_tipo' => $data['origem_tipo'] ?? $lockedInvoice->origem_tipo ?? 'manual',\n                'origem_id' => $data['origem_id'] ?? $lockedInvoice->origem_id,\n                'observacoes' => $data['observacoes'] ?? null,""",
    """                'tipo' => $data['tipo'],\n                // A origem e identidade/proveniencia do documento e nao e editavel administrativamente.\n                'origem_tipo' => $lockedInvoice->origem_tipo,\n                'origem_id' => $lockedInvoice->origem_id,\n                'observacoes' => $data['observacoes'] ?? null,""",
)

monthly_test = 'tests/Feature/Financeiro/MonthlyInvoiceManualEditTest.php'
replace_once(
    monthly_test,
    "            'origem_tipo' => 'monthly_fee',\n            'origem_id' => 'monthly-fee-plan-1',",
    "            'origem_tipo' => 'monthly_fee_legacy',\n            'origem_id' => null,",
)
replace_once(
    monthly_test,
    "            'origem_tipo' => 'monthly_fee',\n            'origem_id' => 'monthly-fee-plan-1',",
    "            'origem_tipo' => 'monthly_fee_legacy',\n            'origem_id' => null,",
)
replace_once(
    monthly_test,
    "            ->assertJsonPath('invoice.origem_tipo', 'monthly_fee')",
    "            ->assertJsonPath('invoice.origem_tipo', 'monthly_fee_legacy')",
)
replace_once(
    monthly_test,
    "            'origem_tipo' => 'monthly_fee',\n            'valor_total' => 30,",
    "            'origem_tipo' => 'monthly_fee_legacy',\n            'valor_total' => 30,",
)

contract = Path('tests/Feature/Financeiro/FaturasTabFlowContractTest.php')
text = contract.read_text(encoding='utf-8')
anchor = "\n    public function test_mensalidades_show_the_bank_reconciliation_trace_and_ignore_reversed_maps(): void\n"
if text.count(anchor) != 1:
    raise SystemExit('FaturasTabFlowContractTest.php: anchor mismatch')
addition = r'''
    public function test_invoice_update_does_not_resubmit_read_only_origin_fields(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Financeiro/FaturasTab.tsx'));

        $this->assertIsString($source);
        $start = strpos($source, 'const updated = await persistInvoiceUpdate(editingFaturaId, {');
        $end = $start === false ? false : strpos($source, 'items: novosItens.map', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $payload = substr($source, $start, $end - $start);
        $this->assertStringNotContainsString('origem_tipo:', $payload);
        $this->assertStringNotContainsString('origem_id:', $payload);
    }

'''
contract.write_text(text.replace(anchor, '\n' + addition + anchor.lstrip('\n'), 1), encoding='utf-8')

docs = 'docs/ESTADO_VIVO_DESENVOLVIMENTO.md'
replace_once(
    docs,
    "| Financeiro geral | 91% | Maduro; CRUDs legacy aposentados e liquidações convergem no fluxo canónico. H5d acrescenta `payment_reversals` imutáveis e cancela logicamente alocações, lançamentos e mapas sem apagar o rasto original; a compensação financeira da devolução fica explícita e idempotente. |",
    "| Financeiro geral | 91% | Maduro; CRUDs legacy aposentados e liquidações convergem no fluxo canónico. H5d acrescenta `payment_reversals` imutáveis e cancela logicamente alocações, lançamentos e mapas sem apagar o rasto original; a compensação financeira da devolução fica explícita e idempotente. A edição administrativa de mensalidades preserva agora a origem canónica/histórica (`monthly_fee`/`monthly_fee_legacy`) sem a reenviar como campo editável, eliminando o 422 em mensalidades legacy. |",
)
