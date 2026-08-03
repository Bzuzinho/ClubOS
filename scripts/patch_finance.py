from pathlib import Path

ROOT = Path('.')


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8')
    print(f'patched {path}')


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 occurrence, found {count}')
    return text.replace(old, new, 1)


def replace_between(text, start_marker, end_marker, replacement, label):
    start = text.find(start_marker)
    if start < 0:
        raise RuntimeError(f'{label}: start marker not found')
    end = text.find(end_marker, start)
    if end < 0:
        raise RuntimeError(f'{label}: end marker not found')
    return text[:start] + replacement + text[end:]


# DashboardTab
path = 'resources/js/Pages/Financeiro/DashboardTab.tsx'
text = read(path)
insert = r'''  const saldoCard = (
    <Card className="h-full p-2 sm:p-2.5">
      <div className="mb-1 flex items-center justify-between">
        <h3 className="text-xs font-semibold sm:text-sm">Saldo Atual</h3>
        <Wallet size={16} className="text-primary" />
      </div>
      <div className="space-y-1.5">
        <div className="flex items-center justify-between rounded-lg bg-muted/50 p-1.5">
          <span className="text-xs font-medium">Saldo Total</span>
          <span className={`text-base font-bold sm:text-lg ${normalizedDashboard.totalGeral >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            €{normalizedDashboard.totalGeral.toFixed(2)}
          </span>
        </div>
        <div className="flex items-center justify-between rounded-lg bg-muted/50 p-1.5">
          <span className="text-xs font-medium">Saldo do Mês</span>
          <span className={`text-base font-bold sm:text-lg ${saldoMes >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            €{saldoMes.toFixed(2)}
          </span>
        </div>
      </div>
    </Card>
  );

  const alertsCard = (
    <Card className="h-full p-2 sm:p-2.5">
      <div className="mb-1 text-xs font-semibold sm:text-sm">Alertas documentais</div>
      <div className="grid gap-1 text-xs">
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Pagos sem fatura</span><span className="font-semibold">{normalizedDashboard.alerts.paidWithoutInvoice}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Pagos sem recibo</span><span className="font-semibold">{normalizedDashboard.alerts.paidWithoutReceipt}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Sem comprovativo</span><span className="font-semibold">{normalizedDashboard.alerts.missingPaymentProof}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Vencidas por pagar</span><span className="font-semibold">{normalizedDashboard.alerts.overdueUnpaid}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Valor divergente</span><span className="font-semibold">{normalizedDashboard.alerts.amountMismatch}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Stock sem documento</span><span className="font-semibold">{normalizedDashboard.alerts.stockWithoutDocument}</span></div>
      </div>
    </Card>
  );

'''
text = replace_once(text, '  return (\n', insert + '  return (\n', 'DashboardTab insert summary cards')
start = '      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">\n'
end = '      {showCharts ? (\n'
start_pos = text.find(start)
end_pos = text.find(end, start_pos)
if start_pos < 0 or end_pos < 0:
    raise RuntimeError('DashboardTab old summary grid not found')
text = text[:start_pos] + text[end_pos:]
text = replace_once(
    text,
    '<Suspense fallback={<div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2"><Card className="p-4 text-xs text-muted-foreground">A carregar gráficos...</Card></div>}>',
    '<Suspense fallback={<div className="grid grid-cols-1 gap-2 md:grid-cols-3"><Card className="p-3 text-xs text-muted-foreground md:col-span-3">A carregar gráficos...</Card></div>}>',
    'DashboardTab fallback',
)
text = replace_once(
    text,
    '            colors={COLORS}\n',
    '            colors={COLORS}\n            summaryLeft={saldoCard}\n            summaryRight={alertsCard}\n',
    'DashboardTab chart props',
)
write(path, text)

# DashboardCharts
path = 'resources/js/Pages/Financeiro/DashboardCharts.tsx'
text = read(path)
text = replace_once(
    text,
    '  colors: string[];\n}',
    '  colors: string[];\n  summaryLeft: ReactNode;\n  summaryRight: ReactNode;\n}',
    'DashboardCharts props',
)
text = replace_once(
    text,
    '  colors,\n}: DashboardChartsProps) {',
    '  colors,\n  summaryLeft,\n  summaryRight,\n}: DashboardChartsProps) {',
    'DashboardCharts destructuring',
)
text = replace_once(
    text,
    '<div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">\n        <Card className="p-2 sm:p-2.5">\n          <h3 className="font-semibold text-xs sm:text-sm mb-1.5">Distribuição de Faturas por Tipo</h3>\n          <ChartMountGuard className="h-[120px] sm:h-[140px]">',
    '<div className="grid grid-cols-1 items-stretch gap-2 md:grid-cols-3 sm:gap-3">\n        {summaryLeft}\n        <Card className="flex h-full min-w-0 flex-col p-2 sm:p-2.5">\n          <h3 className="mb-0.5 text-xs font-semibold leading-tight sm:text-sm">Distribuição de Faturas por Tipo</h3>\n          <ChartMountGuard className="h-[150px] min-h-[150px] flex-1">',
    'DashboardCharts top grid',
)
text = replace_once(
    text,
    '                    labelLine={false}\n                    label={(entry) => entry.name}\n                    outerRadius={45}',
    '                    labelLine={false}\n                    outerRadius={42}',
    'DashboardCharts pie labels',
)
text = replace_once(
    text,
    '                  <Tooltip />\n                </PieChart>',
    '                  <Tooltip />\n                  <Legend layout="vertical" verticalAlign="middle" align="right" wrapperStyle={{ fontSize: \'10px\', lineHeight: \'14px\' }} />\n                </PieChart>',
    'DashboardCharts legend',
)
needle = '        </Card>\n      </div>\n\n      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">'
text = replace_once(
    text,
    needle,
    '        </Card>\n        {summaryRight}\n      </div>\n\n      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">',
    'DashboardCharts summary right',
)
write(path, text)

# FinanceDashboardService
path = 'app/Services/Financeiro/FinanceDashboardService.php'
text = read(path)
text = text.replace('use Illuminate\\Support\\Facades\\Schema;\n', '')
text = replace_once(
    text,
    '        private readonly FinancialReportingFactService $financialReportingFactService,\n',
    '        private readonly FinancialReportingFactService $financialReportingFactService,\n        private readonly MovementDocumentControlService $movementDocumentControlService,\n',
    'FinanceDashboardService constructor',
)
start = '    private function buildExpenseAlerts(Carbon $referenceDate): array\n'
end = '    private function buildDistributionByType(Collection $facts): array\n'
replacement = r'''    private function buildExpenseAlerts(Carbon $referenceDate): array
    {
        $expenseMovements = Movement::query()
            ->where('classificacao', 'despesa')
            ->with('documents')
            ->get();

        $evaluated = $expenseMovements->map(function (Movement $movement): array {
            return [
                'movement' => $movement,
                'evaluation' => $this->movementDocumentControlService->evaluate($movement),
            ];
        });

        $isPaid = static fn (Movement $movement): bool => in_array(
            (string) $movement->estado_pagamento,
            ['pago', 'parcial', 'pago_parcial'],
            true,
        );

        return [
            'paid_without_invoice' => $evaluated->filter(fn (array $row): bool =>
                $isPaid($row['movement'])
                && in_array('invoice', $row['evaluation']['missing_documents'], true)
            )->count(),
            'paid_without_receipt' => $evaluated->filter(fn (array $row): bool =>
                $isPaid($row['movement'])
                && in_array('receipt', $row['evaluation']['missing_documents'], true)
            )->count(),
            'missing_payment_proof' => $evaluated->filter(fn (array $row): bool =>
                in_array('payment_proof', $row['evaluation']['missing_documents'], true)
            )->count(),
            'overdue_unpaid' => $expenseMovements->filter(function (Movement $movement) use ($referenceDate): bool {
                if (!in_array((string) $movement->estado_pagamento, ['pendente', 'por_pagar', 'vencido'], true)) {
                    return false;
                }

                return $movement->data_vencimento !== null
                    && Carbon::parse($movement->data_vencimento)->lt($referenceDate->copy()->startOfDay());
            })->count(),
            'amount_mismatch' => $evaluated->filter(fn (array $row): bool =>
                (bool) ($row['evaluation']['has_amount_mismatch'] ?? false)
            )->count(),
            'stock_without_document' => $evaluated->filter(fn (array $row): bool =>
                $row['movement']->origem_tipo === 'stock'
                && in_array(
                    (string) ($row['evaluation']['estado_documental'] ?? ''),
                    ['sem_documentos', 'falta_fatura', 'falta_recibo', 'falta_comprovativo_pagamento', 'pendente_validacao'],
                    true,
                )
            )->count(),
        ];
    }

'''
text = replace_between(text, start, end, replacement, 'FinanceDashboardService alerts')
write(path, text)

# FaturasTab
path = 'resources/js/Pages/Financeiro/FaturasTab.tsx'
text = read(path)
text = replace_once(
    text,
    "import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';\n",
    "import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';\nimport { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';\n",
    'Faturas import popover',
)
text = replace_once(
    text,
    "  const [estadoFilter, setEstadoFilter] = useState<string>('all');\n  const [viewMode, setViewMode] = useState<'card' | 'table'>('table');\n",
    "  const [estadoFilter, setEstadoFilter] = useState<string>('all');\n  const [viewMode, setViewMode] = useState<'card' | 'table'>('table');\n  const [searchTerm, setSearchTerm] = useState('');\n  const [dateFrom, setDateFrom] = useState('');\n  const [dateTo, setDateTo] = useState('');\n  const [manualUserPickerOpen, setManualUserPickerOpen] = useState(false);\n  const [manualUserSearch, setManualUserSearch] = useState('');\n",
    'Faturas states',
)
marker = '  const filteredFaturas = useMemo(() => {\n'
insert = r'''  const selectedManualUser = useMemo(
    () => (users || []).find((user) => user.id === formData.user_id) || null,
    [formData.user_id, users],
  );

  const filteredManualUsers = useMemo(() => {
    const term = manualUserSearch.trim().toLowerCase();
    if (!term) return users || [];

    return (users || []).filter((user) => [
      user.nome_completo,
      user.numero_socio,
      user.nif,
    ].some((value) => String(value || '').toLowerCase().includes(term)));
  }, [manualUserSearch, users]);

'''
text = replace_once(text, marker, insert + marker, 'Faturas user picker memo')
filter_marker = "      .filter((fatura) => {\n        const futureInvoice = isFutureInvoice(fatura) || !!fatura.oculta;\n"
filter_insert = r'''      .filter((fatura) => {
        const ownerName = fatura.owner_name
          || (users || []).find((user) => user.id === fatura.user_id)?.nome_completo
          || '';
        const normalizedSearch = searchTerm.trim().toLowerCase();
        const searchMatch = !normalizedSearch || [
          ownerName,
          fatura.mes,
          fatura.tipo,
          fatura.referencia_pagamento,
        ].some((value) => String(value || '').toLowerCase().includes(normalizedSearch));

        if (!searchMatch) return false;

        const invoiceDate = String(fatura.data_fatura || fatura.data_emissao || '').slice(0, 10);
        if (dateFrom && invoiceDate && invoiceDate < dateFrom) return false;
        if (dateTo && invoiceDate && invoiceDate > dateTo) return false;

        const futureInvoice = isFutureInvoice(fatura) || !!fatura.oculta;
'''
text = replace_once(text, filter_marker, filter_insert, 'Faturas filtering')
text = replace_once(
    text,
    '  }, [faturas, estadoFilter, showFutureInvoices]);',
    '  }, [faturas, estadoFilter, showFutureInvoices, searchTerm, dateFrom, dateTo, users]);',
    'Faturas filter dependencies',
)
old_parent = '<div className="flex gap-2 items-center w-full sm:w-auto">\n          <Select value={estadoFilter}'
new_parent = '''<div className="grid w-full grid-cols-1 items-center gap-2 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_150px_150px_200px_auto_auto]">\n          <Input\n            value={searchTerm}\n            onChange={(event) => setSearchTerm(event.target.value)}\n            placeholder="Pesquisar utilizador"\n            className="h-9"\n          />\n          <Input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="h-9" aria-label="Data inicial" />\n          <Input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="h-9" aria-label="Data final" />\n          <Select value={estadoFilter}'''
text = replace_once(text, old_parent, new_parent, 'Faturas filter bar')
manual_start = text.find('                    <Label className="text-sm">Utilizador *</Label>', text.find('Criar Mensalidade Manual'))
if manual_start < 0:
    raise RuntimeError('Faturas manual user label not found')
select_start = text.find('                    <Select value={formData.user_id}', manual_start)
select_end = text.find('                    </Select>', select_start)
if select_start < 0 or select_end < 0:
    raise RuntimeError('Faturas manual user select not found')
select_end += len('                    </Select>')
replacement = r'''                    <Popover open={manualUserPickerOpen} onOpenChange={setManualUserPickerOpen}>
                      <PopoverTrigger asChild>
                        <Button type="button" variant="outline" role="combobox" className="w-full justify-between font-normal">
                          <span className="truncate">
                            {selectedManualUser
                              ? `${selectedManualUser.nome_completo} - ${selectedManualUser.numero_socio}`
                              : 'Selecionar utilizador'}
                          </span>
                          <span className="ml-2 shrink-0 text-xs text-muted-foreground">Pesquisar</span>
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                        <div className="border-b p-2">
                          <Input
                            value={manualUserSearch}
                            onChange={(event) => setManualUserSearch(event.target.value)}
                            placeholder="Nome, número de sócio ou NIF"
                            className="h-9"
                            autoFocus
                          />
                        </div>
                        <div className="max-h-60 overflow-y-auto p-1">
                          {filteredManualUsers.length === 0 ? (
                            <div className="px-2 py-4 text-sm text-muted-foreground">Nenhum utilizador encontrado.</div>
                          ) : filteredManualUsers.map((user) => (
                            <button
                              key={user.id}
                              type="button"
                              className={`flex w-full items-center justify-between rounded-sm px-2 py-2 text-left text-sm hover:bg-muted ${formData.user_id === user.id ? 'bg-muted' : ''}`}
                              onClick={() => {
                                setFormData((current) => ({ ...current, user_id: user.id }));
                                setManualUserPickerOpen(false);
                              }}
                            >
                              <span className="min-w-0 truncate pr-2">{user.nome_completo} - {user.numero_socio}</span>
                              {formData.user_id === user.id ? <Check size={14} /> : null}
                            </button>
                          ))}
                        </div>
                      </PopoverContent>
                    </Popover>'''
text = text[:select_start] + replacement + text[select_end:]
reset_start = text.find('  const resetForm = () => {')
reset_end = text.find('\n  };', reset_start)
if reset_start < 0 or reset_end < 0:
    raise RuntimeError('Faturas resetForm not found')
reset_block = text[reset_start:reset_end]
reset_block = reset_block.replace(
    '    setEditingFaturaId(null);',
    "    setEditingFaturaId(null);\n    setManualUserPickerOpen(false);\n    setManualUserSearch('');",
    1,
)
text = text[:reset_start] + reset_block + text[reset_end:]
write(path, text)

# MovimentosTab
path = 'resources/js/Pages/Financeiro/MovimentosTab.tsx'
text = read(path)
old_norm = r'''    if (cleanDescricao.length === 0) {
      return `[ATLETA:${linha.atleta_id}] ${nomeAtleta}`;
    }

    return `[ATLETA:${linha.atleta_id}] ${cleanDescricao}`;'''
new_norm = r'''    if (cleanDescricao.length === 0) {
      return nomeAtleta;
    }

    if (cleanDescricao.toLowerCase().startsWith(nomeAtleta.toLowerCase())) {
      return cleanDescricao;
    }

    return `${nomeAtleta} — ${cleanDescricao}`;'''
text = replace_once(text, old_norm, new_norm, 'Movimentos athlete description')
text = replace_once(
    text,
    '          descricao: item.descricao,\n          valor_unitario:',
    '          descricao: stripAtletaMarker(item.descricao),\n          valor_unitario:',
    'Movimentos edit description',
)
text = replace_once(
    text,
    '      toast.success(`${movimentosParaApagar.length} movimento(s) apagado(s) com sucesso`);\n      setDialogDeleteOpen(false);',
    '      toast.success(`${movimentosParaApagar.length} movimento(s) apagado(s) com sucesso`);\n      refreshMovimentos();\n      setDialogDeleteOpen(false);',
    'Movimentos bulk delete refresh',
)
text = replace_once(
    text,
    "      toast.success('Movimento apagado com sucesso');\n    } catch",
    "      toast.success('Movimento apagado com sucesso');\n      refreshMovimentos();\n    } catch",
    'Movimentos single delete refresh',
)
text = replace_once(
    text,
    '<div className="flex gap-2 items-center">\n          <Select value={classificacaoFilter}',
    '<div className="grid w-full grid-cols-2 items-center gap-2 lg:grid-cols-4">\n          <Select value={classificacaoFilter}',
    'Movimentos filter grid',
)
text = text.replace('className="w-[200px]"', 'className="w-full min-w-0"', 2)
text = text.replace('className="w-[220px]"', 'className="w-full min-w-0"', 2)
text = text.replace('<SelectItem value="all">Todas Classificacoes</SelectItem>', '<SelectItem value="all">Todas</SelectItem>', 1)
text = text.replace('<SelectItem value="all">Todos os Estados</SelectItem>', '<SelectItem value="all">Todos</SelectItem>', 1)
text = text.replace('<SelectItem value="all">Todos os documentos</SelectItem>', '<SelectItem value="all">Todos</SelectItem>', 1)
text = text.replace('<SelectItem value="all">Toda a conciliacao</SelectItem>', '<SelectItem value="all">Todas</SelectItem>', 1)
text = text.replace(
    '<Card key={movimento.id} className="p-3">\n                  <div className="space-y-3">',
    '<Card key={movimento.id} className="p-2.5">\n                  <div className="space-y-2">',
    1,
)
write(path, text)

# BancoTab
path = 'resources/js/Pages/Financeiro/BancoTab.tsx'
text = read(path)
return_pos = text.find('  return (\n')
root_controls = text.find('      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">', return_pos)
stats_marker = '\n\n      <div className="grid gap-3 md:gap-4 grid-cols-2 md:grid-cols-4">'
stats_pos = text.find(stats_marker, root_controls)
if root_controls < 0 or stats_pos < 0:
    raise RuntimeError('Banco top controls block not found')
block = text[root_controls:stats_pos]
block = block.replace(
    '<div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">',
    '<Card className="flex flex-col gap-3 p-3 md:flex-row md:items-center md:justify-between">',
    1,
)
last_close = block.rfind('      </div>')
if last_close < 0:
    raise RuntimeError('Banco top controls closing not found')
block = block[:last_close] + '      </Card>' + block[last_close + len('      </div>'):]
right_group = '<div className="flex w-full flex-col gap-2 sm:flex-row md:w-auto">\n'
conciliar_button = r'''<div className="flex w-full flex-wrap gap-2 md:w-auto md:justify-end">
          <Button type="button" onClick={() => void handleGenerateSuggestionsBatch()} disabled={bulkGeneratingSuggestions} className="min-w-[108px]">
            <Gear size={16} className="mr-1.5" />
            {bulkGeneratingSuggestions ? 'A conciliar...' : 'Conciliar'}
          </Button>
          {bulkGeneratingSuggestions ? (
            <Button type="button" variant="outline" onClick={handleCancelBulkSuggestionGeneration} title="Cancelar conciliação">
              <X size={16} />
            </Button>
          ) : null}
'''
block = replace_once(block, right_group, conciliar_button, 'Banco conciliar controls')
block = block.replace(
    '<FileArrowUp className="mr-2" />\n                Importar Extrato XLS',
    '<FileArrowUp className="mr-1.5" />\n                XLS',
    1,
)
block = block.replace(
    '<Plus className="mr-2" />\n                Adicionar Movimento',
    '<Plus />',
    1,
)
block = block.replace(
    'className="w-full sm:w-auto"\n              >\n                <Plus />',
    'className="h-10 w-10 p-0"\n                title="Adicionar movimento"\n                aria-label="Adicionar movimento"\n              >\n                <Plus />',
    1,
)
text = text[:root_controls] + block + text[stats_pos:]
sugg_start = text.find('      <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 md:flex-row md:items-center md:justify-between">')
sugg_end_marker = '\n\n      <Card className="overflow-hidden">'
sugg_end = text.find(sugg_end_marker, sugg_start)
if sugg_start < 0 or sugg_end < 0:
    raise RuntimeError('Banco old suggestions card not found')
text = text[:sugg_start] + text[sugg_end:]
text = text.replace(
    '<div className="grid gap-3 md:gap-4 grid-cols-2 md:grid-cols-4">',
    '<div className="grid grid-cols-2 gap-2 md:grid-cols-4">',
    1,
)
text = text.replace('<Card className="p-3 md:p-4">', '<Card className="p-2">', 4)
text = text.replace('text-xl md:text-2xl', 'text-lg md:text-xl', 4)
text = text.replace('p-1.5 md:p-2 rounded-lg', 'p-1 rounded-lg', 4)
write(path, text)

# Show.tsx
path = 'resources/js/Pages/Financeiro/Show.tsx'
text = read(path)
text = text.replace(
    '<div className="flex flex-col gap-3 rounded-xl border bg-card p-4">',
    '<div className="flex flex-col gap-2 rounded-xl border bg-card p-3">',
    1,
)
text = text.replace(
    '<div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">',
    '<div className="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-4">',
    1,
)
text = text.replace('<Card key={item.label} className="p-3">', '<Card key={item.label} className="p-2">', 1)
text = text.replace(
    '<div className="mt-1 text-sm font-semibold">{item.value}</div>',
    '<div className="mt-0.5 text-sm font-semibold">{item.value}</div>',
    1,
)
write(path, text)

# FinanceiroController
path = 'app/Http/Controllers/FinanceiroController.php'
text = read(path)
text = replace_once(
    text,
    "            ->whereIn('estado_pagamento', ['pendente', 'parcial']);",
    "            ->whereIn('estado_pagamento', ['pendente', 'por_pagar', 'vencido', 'parcial', 'pago_parcial']);",
    'Financeiro open movements states',
)
serialize_start = text.find('    private function serializeMovementDetail(Movement $movement): array')
serialize_end = text.find('    private function serializeMovementDocument', serialize_start)
if serialize_start < 0 or serialize_end < 0:
    raise RuntimeError('Financeiro serializeMovementDetail not found')
serialize_block = text[serialize_start:serialize_end]
serialize_block = replace_once(
    serialize_block,
    "                'descricao' => $item->descricao,",
    "                'descricao' => preg_replace('/^\\[ATLETA:[^\\]]+\\]\\s*/i', '', (string) $item->descricao),",
    'Financeiro movement item display',
)
text = text[:serialize_start] + serialize_block + text[serialize_end:]
start = '    private function serializeAvailableBankStatementsForMovement(Movement $movement): array\n'
end = '    /**\n     * @return array<int, array<string, mixed>>\n     */\n    private function buildMovementHistory(Movement $movement): array\n'
replacement = r'''    private function serializeAvailableBankStatementsForMovement(Movement $movement): array
    {
        $query = BankStatement::query()
            ->where(function ($query): void {
                $query->where('conciliado', false)
                    ->orWhereIn('conciliacao_status', ['unreconciled', 'partial'])
                    ->orWhereNull('conciliacao_status');
            });

        if ($movement->classificacao === 'despesa') {
            $query->where('valor', '<', 0);
        } else {
            $query->where('valor', '>', 0);
        }

        if ($movement->centro_custo_id) {
            $query->orderByRaw('case when centro_custo_id = ? then 0 else 1 end', [$movement->centro_custo_id]);
        }

        return $query
            ->orderByDesc('data_movimento')
            ->limit(50)
            ->get()
            ->map(fn (BankStatement $statement): array => [
                'id' => (string) $statement->id,
                'data_movimento' => optional($statement->data_movimento)?->toDateString(),
                'descricao' => $statement->descricao,
                'referencia' => $statement->referencia,
                'valor' => (float) $statement->valor,
                'conciliado' => (bool) $statement->conciliado,
                'conciliacao_status' => $statement->conciliacao_status,
                'valor_por_conciliar' => $statement->valor_por_conciliar !== null ? (float) $statement->valor_por_conciliar : null,
            ])
            ->values()
            ->all();
    }

'''
text = replace_between(text, start, end, replacement, 'Financeiro bank statement options')
created_event = r'''        if (filled($movement->observacoes)) {
            $events->push([
                'type' => 'movement_note',
                'label' => 'Nota do movimento',
                'at' => optional($movement->updated_at ?? $movement->created_at)?->toISOString(),
                'details' => $movement->observacoes,
            ]);
        }

'''
insert_marker = '        foreach ($movement->documents as $document) {\n'
text = replace_once(text, insert_marker, created_event + insert_marker, 'Financeiro notes history')
old_resolve = r'''        return (string) ($movement->referencia_pagamento
            ?? $firstItemDescription
            ?? $movement->categoria
            ?? $movement->nome_manual
            ?? 'Movimento financeiro');'''
new_resolve = r'''        $description = (string) ($movement->referencia_pagamento
            ?? $firstItemDescription
            ?? $movement->categoria
            ?? $movement->nome_manual
            ?? 'Movimento financeiro');

        return preg_replace('/^\[ATLETA:[^\]]+\]\s*/i', '', $description) ?: 'Movimento financeiro';'''
text = replace_once(text, old_resolve, new_resolve, 'Financeiro detail description')
write(path, text)

print('all patches applied')
