from pathlib import Path
import re

ROOT = Path('.')


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def write(path: str, text: str) -> None:
    (ROOT / path).write_text(text, encoding='utf-8')
    print(f'patched {path}')


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 occurrence, found {count}')
    return text.replace(old, new, 1)


def replace_between(text: str, start_marker: str, end_marker: str, replacement: str, label: str) -> str:
    start = text.find(start_marker)
    if start < 0:
        raise RuntimeError(f'{label}: start marker not found')
    end = text.find(end_marker, start)
    if end < 0:
        raise RuntimeError(f'{label}: end marker not found')
    return text[:start] + replacement + text[end:]


# Mensalidades: pesquisa por utilizador/data e seletor pesquisável no modal manual.
path = 'resources/js/Pages/Financeiro/FaturasTab.tsx'
text = read(path)
text = replace_once(
    text,
    "import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';\n",
    "import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';\nimport { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';\n",
    'Faturas import Popover',
)
text = replace_once(
    text,
    "  const [estadoFilter, setEstadoFilter] = useState<string>('all');\n  const [viewMode, setViewMode] = useState<'card' | 'table'>('table');\n",
    "  const [estadoFilter, setEstadoFilter] = useState<string>('all');\n  const [viewMode, setViewMode] = useState<'card' | 'table'>('table');\n  const [searchTerm, setSearchTerm] = useState('');\n  const [dateFrom, setDateFrom] = useState('');\n  const [dateTo, setDateTo] = useState('');\n  const [manualUserPickerOpen, setManualUserPickerOpen] = useState(false);\n  const [manualUserSearch, setManualUserSearch] = useState('');\n",
    'Faturas filter states',
)
text = replace_once(
    text,
    '  const filteredFaturas = useMemo(() => {\n',
    """  const selectedManualUser = useMemo(
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

  const filteredFaturas = useMemo(() => {
""",
    'Faturas user picker memo',
)
text = replace_once(
    text,
    """      .filter((fatura) => {
        const futureInvoice = isFutureInvoice(fatura) || !!fatura.oculta;
""",
    """      .filter((fatura) => {
        const ownerName = (fatura as Fatura & { owner_name?: string | null }).owner_name
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
""",
    'Faturas search filtering',
)
text = replace_once(
    text,
    '  }, [faturas, estadoFilter, showFutureInvoices]);',
    '  }, [faturas, estadoFilter, showFutureInvoices, searchTerm, dateFrom, dateTo, users]);',
    'Faturas filter dependencies',
)
text = replace_once(
    text,
    '<div className="flex gap-2 items-center w-full sm:w-auto">\n          <Select value={estadoFilter}',
    """<div className="grid w-full grid-cols-1 items-center gap-2 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_150px_150px_200px_auto_auto]">
          <Input
            value={searchTerm}
            onChange={(event) => setSearchTerm(event.target.value)}
            placeholder="Pesquisar utilizador"
            className="h-9"
          />
          <Input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="h-9" aria-label="Data inicial" />
          <Input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="h-9" aria-label="Data final" />
          <Select value={estadoFilter}""",
    'Faturas toolbar',
)
manual_anchor = text.find("<DialogTitle className=\"text-base sm:text-lg\">\n                  {editingFaturaId ? 'Editar Mensalidade' : 'Criar Mensalidade Manual'}")
if manual_anchor < 0:
    raise RuntimeError('Faturas manual dialog anchor not found')
select_start = text.find('                    <Select value={formData.user_id}', manual_anchor)
select_end = text.find('                    </Select>', select_start)
if select_start < 0 or select_end < 0:
    raise RuntimeError('Faturas manual user select not found')
select_end += len('                    </Select>')
user_picker = """                    <Popover open={manualUserPickerOpen} onOpenChange={setManualUserPickerOpen}>
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
                    </Popover>"""
text = text[:select_start] + user_picker + text[select_end:]
reset_start = text.find('  const resetForm = () => {')
reset_end = text.find('\n  };', reset_start)
if reset_start < 0 or reset_end < 0:
    raise RuntimeError('Faturas reset form not found')
reset_block = text[reset_start:reset_end]
reset_block = replace_once(
    reset_block,
    '    setEditingFaturaId(null);',
    "    setEditingFaturaId(null);\n    setManualUserPickerOpen(false);\n    setManualUserSearch('');",
    'Faturas reset picker',
)
text = text[:reset_start] + reset_block + text[reset_end:]
write(path, text)


# Movimentos: filtros responsivos, eliminação visível e descrições sem UUID.
path = 'resources/js/Pages/Financeiro/MovimentosTab.tsx'
text = read(path)
text = replace_once(
    text,
    """    if (cleanDescricao.length === 0) {
      return `[ATLETA:${linha.atleta_id}] ${nomeAtleta}`;
    }

    return `[ATLETA:${linha.atleta_id}] ${cleanDescricao}`;""",
    """    if (cleanDescricao.length === 0) {
      return nomeAtleta;
    }

    if (cleanDescricao.toLowerCase().startsWith(nomeAtleta.toLowerCase())) {
      return cleanDescricao;
    }

    return `${nomeAtleta} — ${cleanDescricao}`;""",
    'Movimentos athlete description',
)
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
text = replace_once(
    text,
    '<Card key={movimento.id} className="p-3">\n                  <div className="space-y-3">',
    '<Card key={movimento.id} className="p-2.5">\n                  <div className="space-y-2">',
    'Movimentos mobile card size',
)
write(path, text)


# Banco: controlos no card de sugestões e cartões compactos.
path = 'resources/js/Pages/Financeiro/BancoTab.tsx'
text = read(path)
return_pos = text.find('  return (\n')
controls_start = text.find('      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">', return_pos)
stats_marker = '\n\n      <div className="grid gap-3 md:gap-4 grid-cols-2 md:grid-cols-4">'
stats_pos = text.find(stats_marker, controls_start)
if controls_start < 0 or stats_pos < 0:
    raise RuntimeError('Banco controls block not found')
controls = text[controls_start:stats_pos]
controls = controls.replace(
    '<div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">',
    '<Card className="flex flex-col gap-3 p-3 md:flex-row md:items-center md:justify-between">',
    1,
)
last_close = controls.rfind('      </div>')
if last_close < 0:
    raise RuntimeError('Banco controls closing tag not found')
controls = controls[:last_close] + '      </Card>' + controls[last_close + len('      </div>'):]
controls = replace_once(
    controls,
    '<div className="flex w-full flex-col gap-2 sm:flex-row md:w-auto">\n          <Dialog',
    """<div className="flex w-full flex-wrap gap-2 md:w-auto md:justify-end">
          <Button type="button" onClick={() => void handleGenerateSuggestionsBatch()} disabled={bulkGeneratingSuggestions} className="min-w-[108px]">
            <Gear size={16} className="mr-1.5" />
            {bulkGeneratingSuggestions ? 'A conciliar...' : 'Conciliar'}
          </Button>
          {bulkGeneratingSuggestions ? (
            <Button type="button" variant="outline" onClick={handleCancelBulkSuggestionGeneration} title="Cancelar conciliação" aria-label="Cancelar conciliação">
              <X size={16} />
            </Button>
          ) : null}
          <Dialog""",
    'Banco conciliar controls',
)
controls = controls.replace(
    '<FileArrowUp className="mr-2" />\n                Importar Extrato XLS',
    '<FileArrowUp className="mr-1.5" />\n                XLS',
    1,
)
controls = controls.replace(
    'className="w-full sm:w-auto"\n              >\n                <Plus className="mr-2" />\n                Adicionar Movimento',
    'className="h-10 w-10 p-0"\n                title="Adicionar movimento"\n                aria-label="Adicionar movimento"\n              >\n                <Plus />',
    1,
)
text = text[:controls_start] + controls + text[stats_pos:]
suggestions_start = text.find('      <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 md:flex-row md:items-center md:justify-between">')
suggestions_end = text.find('\n\n      <Card className="overflow-hidden">', suggestions_start)
if suggestions_start < 0 or suggestions_end < 0:
    raise RuntimeError('Banco old suggestions card not found')
text = text[:suggestions_start] + text[suggestions_end:]
text = text.replace(
    '<div className="grid gap-3 md:gap-4 grid-cols-2 md:grid-cols-4">',
    '<div className="grid grid-cols-2 gap-2 md:grid-cols-4">',
    1,
)
text = text.replace('<Card className="p-3 md:p-4">', '<Card className="p-2">', 4)
text = text.replace('text-xl md:text-2xl', 'text-lg md:text-xl', 4)
text = text.replace('p-1.5 md:p-2 rounded-lg', 'p-1 rounded-lg', 4)
write(path, text)


# Detalhe do movimento: reduzir cartões para evitar scroll vertical desnecessário.
path = 'resources/js/Pages/Financeiro/Show.tsx'
text = read(path)
text = replace_once(
    text,
    '<div className="flex flex-col gap-3 rounded-xl border bg-card p-4">',
    '<div className="flex flex-col gap-2 rounded-xl border bg-card p-3">',
    'Show header card size',
)
text = replace_once(
    text,
    '<div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">',
    '<div className="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-4">',
    'Show summary grid size',
)
text = replace_once(text, '<Card key={item.label} className="p-3">', '<Card key={item.label} className="p-2">', 'Show summary card size')
text = replace_once(text, '<div className="mt-1 text-sm font-semibold">{item.value}</div>', '<div className="mt-0.5 text-sm font-semibold">{item.value}</div>', 'Show summary value spacing')
write(path, text)


# Backend: movimentos abertos, dropdown bancário, notas no histórico e descrição limpa.
path = 'app/Http/Controllers/FinanceiroController.php'
text = read(path)
text = replace_once(
    text,
    "            ->whereIn('estado_pagamento', ['pendente', 'parcial']);",
    "            ->whereIn('estado_pagamento', ['pendente', 'por_pagar', 'vencido', 'parcial', 'pago_parcial']);",
    'Financeiro open movement states',
)
serialize_start = text.find('    private function serializeMovementDetail(Movement $movement): array')
serialize_end = text.find('    private function serializeMovementDocument', serialize_start)
if serialize_start < 0 or serialize_end < 0:
    raise RuntimeError('Financeiro movement serializer not found')
serialize_block = text[serialize_start:serialize_end]
serialize_block = replace_once(
    serialize_block,
    "                'descricao' => $item->descricao,",
    "                'descricao' => preg_replace('/^\\[ATLETA:[^\\]]+\\]\\s*/i', '', (string) $item->descricao),",
    'Financeiro movement item display description',
)
text = text[:serialize_start] + serialize_block + text[serialize_end:]
method_start = '    private function serializeAvailableBankStatementsForMovement(Movement $movement): array\n'
history_marker = '    /**\n     * @return array<int, array<string, mixed>>\n     */\n    private function buildMovementHistory(Movement $movement): array\n'
new_method = """    private function serializeAvailableBankStatementsForMovement(Movement $movement): array
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

"""
text = replace_between(text, method_start, history_marker, new_method, 'Financeiro bank statement options')
text = replace_once(
    text,
    '        foreach ($movement->documents as $document) {\n',
    """        if (filled($movement->observacoes)) {
            $events->push([
                'type' => 'movement_note',
                'label' => 'Nota do movimento',
                'at' => optional($movement->updated_at ?? $movement->created_at)?->toISOString(),
                'details' => $movement->observacoes,
            ]);
        }

        foreach ($movement->documents as $document) {
""",
    'Financeiro notes history',
)
text = replace_once(
    text,
    """        return (string) ($movement->referencia_pagamento
            ?? $firstItemDescription
            ?? $movement->categoria
            ?? $movement->nome_manual
            ?? 'Movimento financeiro');""",
    """        $description = (string) ($movement->referencia_pagamento
            ?? $firstItemDescription
            ?? $movement->categoria
            ?? $movement->nome_manual
            ?? 'Movimento financeiro');

        return preg_replace('/^\\[ATLETA:[^\\]]+\\]\\s*/i', '', $description) ?: 'Movimento financeiro';""",
    'Financeiro detail description',
)
write(path, text)

print('remaining finance fixes applied')
