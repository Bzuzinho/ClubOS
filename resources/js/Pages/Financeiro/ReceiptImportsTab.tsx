import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';

import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';

import { ExtratoBancario, ReceiptImportBatch, ReceiptImportItem } from './types';

interface ReceiptImportUserOption {
  id: string;
  nome_completo?: string | null;
  numero_socio?: string | null;
}

interface ReceiptImportInvoiceOption {
  id: string;
  user_id: string;
  tipo: string;
  mes?: string | null;
  valor_total: number;
  valor_em_aberto?: number | null;
  estado_pagamento: string;
}

interface ReceiptImportsTabProps {
  users: ReceiptImportUserOption[];
  invoices: ReceiptImportInvoiceOption[];
  canEdit: boolean;
}

interface ReceiptImportResponse {
  batches: ReceiptImportBatch[];
  latest_batch_id?: string | null;
}

const displayStatusStyles: Record<string, string> = {
  matched: 'bg-emerald-100 text-emerald-800',
  needs_user: 'bg-amber-100 text-amber-800',
  needs_invoice: 'bg-orange-100 text-orange-800',
  duplicate: 'bg-slate-200 text-slate-700',
  ready: 'bg-sky-100 text-sky-800',
  imported: 'bg-green-100 text-green-800',
  failed: 'bg-rose-100 text-rose-800',
  pending_review: 'bg-muted text-muted-foreground',
};

const formatCurrency = (value?: number | null) => (value === null || value === undefined ? '-' : `€${value.toFixed(2)}`);

export function ReceiptImportsTab({ users, invoices, canEdit }: ReceiptImportsTabProps) {
  const [batches, setBatches] = useState<ReceiptImportBatch[]>([]);
  const [selectedBatchId, setSelectedBatchId] = useState<string>('');
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [creating, setCreating] = useState(false);
  const [committing, setCommitting] = useState(false);
  const [editItem, setEditItem] = useState<ReceiptImportItem | null>(null);
  const [editOpen, setEditOpen] = useState(false);
  const [statementPickerOpen, setStatementPickerOpen] = useState(false);
  const [statementPickerItemId, setStatementPickerItemId] = useState<string | null>(null);
  const [statementRows, setStatementRows] = useState<ExtratoBancario[]>([]);
  const [statementSearch, setStatementSearch] = useState('');
  const [statementLoading, setStatementLoading] = useState(false);
  const [zipFile, setZipFile] = useState<File | null>(null);
  const [usePendingDirectory, setUsePendingDirectory] = useState(false);
  const [pendingDirectory, setPendingDirectory] = useState('private/imports/receipts/pending');
  const [uploadOpen, setUploadOpen] = useState(false);
  const [editForm, setEditForm] = useState({
    user_id: '',
    invoice_id: '',
    bank_statement_id: '',
    numero_recibo: '',
    recibo_emitido_em: '',
    notes: '',
  });

  const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    return token?.content || '';
  };

  const buildJsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': getCsrfToken(),
  });

  const selectedBatch = useMemo(
    () => batches.find((batch) => batch.id === selectedBatchId) ?? batches[0] ?? null,
    [batches, selectedBatchId],
  );
  const selectedItems = useMemo(
    () => selectedBatch?.items.filter((item) => selectedIds.includes(item.id)) ?? [],
    [selectedBatch, selectedIds],
  );

  const filteredInvoices = useMemo(() => {
    if (!editForm.user_id) return [];

    return invoices.filter((invoice) => (
      invoice.user_id === editForm.user_id
      && ['pendente', 'vencido', 'parcial'].includes(invoice.estado_pagamento)
    ));
  }, [editForm.user_id, invoices]);

  useEffect(() => {
    void loadBatches();
  }, []);

  useEffect(() => {
    if (!selectedBatchId && batches[0]) {
      setSelectedBatchId(batches[0].id);
    }
  }, [batches, selectedBatchId]);

  useEffect(() => {
    setSelectedIds([]);
  }, [selectedBatchId]);

  useEffect(() => {
    if (!statementPickerOpen || !statementPickerItemId) {
      return;
    }

    void loadStatements(statementSearch);
  }, [statementPickerOpen, statementPickerItemId, statementSearch]);

  const loadBatches = async (batchId?: string) => {
    setLoading(true);

    try {
      const url = new URL(route('financeiro.receipt-imports.index'), window.location.origin);
      if (batchId) {
        url.searchParams.set('batch_id', batchId);
      }

      const response = await fetch(url.toString(), {
        method: 'GET',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao carregar batches de importacao.');
      }

      const payload = await response.json() as ReceiptImportResponse;
      setBatches(payload.batches ?? []);
      if (payload.latest_batch_id) {
        setSelectedBatchId(payload.latest_batch_id);
      }
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao carregar importacoes.');
    } finally {
      setLoading(false);
    }
  };

  const loadStatements = async (search: string) => {
    const currentItem = selectedBatch?.items.find((item) => item.id === statementPickerItemId);
    setStatementLoading(true);

    try {
      const url = new URL(route('financeiro.bank-statements.unreconciled'), window.location.origin);
      url.searchParams.set('per_page', '25');
      if (search.trim() !== '') {
        url.searchParams.set('search', search.trim());
      }
      if (currentItem?.valor) {
        url.searchParams.set('amount', String(currentItem.valor));
      }

      const response = await fetch(url.toString(), {
        method: 'GET',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao carregar movimentos bancarios.');
      }

      const payload = await response.json();
      setStatementRows(Array.isArray(payload?.data) ? payload.data : []);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao carregar movimentos bancarios.');
    } finally {
      setStatementLoading(false);
    }
  };

  const handleCreateBatch = async () => {
    if (!canEdit) return;
    if (!usePendingDirectory && !zipFile) {
      toast.error('Selecione um ZIP ou escolha a pasta pendente.');
      return;
    }

    setCreating(true);

    try {
      const formData = new FormData();
      if (zipFile) {
        formData.append('zip_file', zipFile);
      }
      formData.append('use_pending_directory', usePendingDirectory ? '1' : '0');
      formData.append('pending_directory', pendingDirectory);

      const response = await fetch(route('financeiro.receipt-imports.store'), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: formData,
      });

      if (!response.ok) {
        throw new Error('Erro ao criar a importacao.');
      }

      const payload = await response.json() as { batch: ReceiptImportBatch };
      setUploadOpen(false);
      setZipFile(null);
      setUsePendingDirectory(false);
      setPendingDirectory('private/imports/receipts/pending');
      await loadBatches(payload.batch.id);
      toast.success('Importacao criada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao criar a importacao.');
    } finally {
      setCreating(false);
    }
  };

  const openEditDialog = (item: ReceiptImportItem) => {
    setEditItem(item);
    setEditForm({
      user_id: item.user_id ?? '',
      invoice_id: item.invoice_id ?? '',
      bank_statement_id: item.bank_statement_id ?? '',
      numero_recibo: item.numero_recibo ?? '',
      recibo_emitido_em: item.recibo_emitido_em ?? '',
      notes: '',
    });
    setEditOpen(true);
  };

  const handleUpdateItem = async () => {
    if (!editItem || !canEdit) return;

    try {
      const response = await fetch(route('financeiro.receipt-imports.items.update', editItem.id), {
        method: 'PATCH',
        headers: {
          ...buildJsonHeaders(),
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(editForm),
      });

      if (!response.ok) {
        throw new Error('Erro ao atualizar o item.');
      }

      await loadBatches(selectedBatchId);
      setEditOpen(false);
      toast.success('Item atualizado.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao atualizar o item.');
    }
  };

  const handleCommit = async () => {
    if (!selectedBatch || selectedItems.length === 0 || !canEdit) {
      return;
    }

    const invalidItem = selectedItems.find((item) => !item.is_ready);
    if (invalidItem) {
      toast.error('Existem itens selecionados sem utilizador, fatura, recibo ou movimento bancario.');
      return;
    }

    setCommitting(true);

    try {
      const response = await fetch(route('financeiro.receipt-imports.commit', selectedBatch.id), {
        method: 'POST',
        headers: {
          ...buildJsonHeaders(),
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ item_ids: selectedIds }),
      });

      if (!response.ok) {
        throw new Error('Erro ao confirmar a importacao.');
      }

      await loadBatches(selectedBatch.id);
      setSelectedIds([]);
      router.reload({
        only: ['faturas', 'mensalidadesFaturas', 'extratos', 'conciliacoes', 'lancamentos', 'dashboardData'],
      });
      toast.success('Importacao confirmada.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao confirmar a importacao.');
    } finally {
      setCommitting(false);
    }
  };

  const toggleSelection = (itemId: string, checked: boolean | 'indeterminate') => {
    setSelectedIds((current) => {
      if (checked === true) {
        return Array.from(new Set([...current, itemId]));
      }

      return current.filter((id) => id !== itemId);
    });
  };

  const selectStatement = async (statementId: string) => {
    if (!statementPickerItemId || !canEdit) return;

    const item = selectedBatch?.items.find((entry) => entry.id === statementPickerItemId);
    if (!item) return;

    try {
      const response = await fetch(route('financeiro.receipt-imports.items.update', item.id), {
        method: 'PATCH',
        headers: {
          ...buildJsonHeaders(),
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ bank_statement_id: statementId }),
      });

      if (!response.ok) {
        throw new Error('Erro ao selecionar o movimento bancario.');
      }

      setStatementPickerOpen(false);
      setStatementPickerItemId(null);
      setStatementSearch('');
      await loadBatches(selectedBatchId);
      toast.success('Movimento bancario associado.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro ao selecionar o movimento bancario.');
    }
  };

  return (
    <div className="space-y-4">
      <Card className="p-4 space-y-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h3 className="text-base font-semibold">Importação de Recibos</h3>
            <p className="text-sm text-muted-foreground">Importe PDFs antigos, valide os matches e confirme a liquidação no fluxo financeiro canónico.</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => void loadBatches(selectedBatchId)} disabled={loading}>
              Atualizar
            </Button>
            <Button onClick={() => setUploadOpen(true)} disabled={!canEdit}>
              Nova importação
            </Button>
          </div>
        </div>

        {batches.length > 0 ? (
          <div className="grid gap-3 lg:grid-cols-3">
            {batches.map((batch) => (
              <button
                key={batch.id}
                type="button"
                className={`rounded-lg border p-3 text-left transition ${selectedBatch?.id === batch.id ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50'}`}
                onClick={() => setSelectedBatchId(batch.id)}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-medium">{batch.source_name || batch.source_type}</span>
                  <Badge className={displayStatusStyles[batch.status] || displayStatusStyles.pending_review}>{batch.status}</Badge>
                </div>
                <div className="mt-2 text-xs text-muted-foreground">
                  <div>{batch.items_count} ficheiros</div>
                  <div>{batch.imported_count} importados</div>
                </div>
              </button>
            ))}
          </div>
        ) : (
          <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
            Nenhuma importação criada ainda.
          </div>
        )}
      </Card>

      {selectedBatch ? (
        <Card className="p-4 space-y-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h4 className="text-sm font-semibold">Itens da importação</h4>
              <p className="text-xs text-muted-foreground">Selecione os itens prontos para confirmar ou corrija manualmente os que ficaram pendentes.</p>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" disabled={selectedItems.length === 0 || committing} onClick={handleCommit}>
                Confirmar importação
              </Button>
            </div>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-10">Sel.</TableHead>
                <TableHead>Ficheiro</TableHead>
                <TableHead>Recibo</TableHead>
                <TableHead>Extraído</TableHead>
                <TableHead>Atleta</TableHead>
                <TableHead>Fatura</TableHead>
                <TableHead>Banco</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {selectedBatch.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    <Checkbox
                      checked={selectedIds.includes(item.id)}
                      onCheckedChange={(checked) => toggleSelection(item.id, checked)}
                      disabled={!canEdit || item.display_status === 'imported'}
                    />
                  </TableCell>
                  <TableCell>
                    <div className="font-medium">{item.file_name}</div>
                    <div className="text-xs text-muted-foreground">{formatCurrency(item.valor)}</div>
                  </TableCell>
                  <TableCell>
                    <div>{item.numero_recibo || '-'}</div>
                    <div className="text-xs text-muted-foreground">{item.recibo_emitido_em || '-'}</div>
                  </TableCell>
                  <TableCell>
                    <div>{item.extracted_name || '-'}</div>
                    <div className="text-xs text-muted-foreground">{item.extracted_nif || item.extracted_member_number || item.extracted_email || '-'}</div>
                  </TableCell>
                  <TableCell>
                    <div>{item.user?.nome_completo || '-'}</div>
                    <div className="text-xs text-muted-foreground">Confiança: {item.confidence_score.toFixed(0)}%</div>
                  </TableCell>
                  <TableCell>
                    <div>{item.invoice ? `${item.invoice.tipo} ${item.invoice.mes ?? ''}`.trim() : '-'}</div>
                    <div className="text-xs text-muted-foreground">{item.invoice ? `${formatCurrency(item.invoice.valor_total)} | ${item.invoice.estado_pagamento}` : '-'}</div>
                  </TableCell>
                  <TableCell>
                    <div>{item.bank_statement?.descricao || '-'}</div>
                    <div className="text-xs text-muted-foreground">
                      {item.bank_statement ? `${formatCurrency(item.bank_statement.valor)} | pendente ${formatCurrency(item.bank_statement.valor_por_conciliar)}` : '-'}
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge className={displayStatusStyles[item.display_status || item.status] || displayStatusStyles.pending_review}>
                      {item.display_status || item.status}
                    </Badge>
                    {item.failure_reason ? (
                      <div className="mt-1 text-xs text-rose-700">{item.failure_reason}</div>
                    ) : null}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => window.open(item.preview_url, '_blank', 'noopener,noreferrer')}>
                        PDF
                      </Button>
                      <Button type="button" size="sm" variant="outline" onClick={() => openEditDialog(item)} disabled={!canEdit}>
                        Corrigir
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setStatementPickerItemId(item.id);
                          setStatementPickerOpen(true);
                        }}
                        disabled={!canEdit}
                      >
                        Banco
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      ) : null}

      <Dialog open={uploadOpen} onOpenChange={setUploadOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Criar importação</DialogTitle>
            <DialogDescription>Carregue um ZIP de recibos ou reutilize a pasta privada pendente.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={usePendingDirectory} onChange={(event) => setUsePendingDirectory(event.target.checked)} />
              Usar pasta pendente em storage
            </label>

            {usePendingDirectory ? (
              <div className="space-y-2">
                <Label htmlFor="pendingDirectory">Pasta pendente</Label>
                <Input id="pendingDirectory" value={pendingDirectory} onChange={(event) => setPendingDirectory(event.target.value)} />
              </div>
            ) : (
              <div className="space-y-2">
                <Label htmlFor="zipFile">ZIP de recibos</Label>
                <Input id="zipFile" type="file" accept=".zip" onChange={(event) => setZipFile(event.target.files?.[0] ?? null)} />
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setUploadOpen(false)}>Cancelar</Button>
            <Button onClick={handleCreateBatch} disabled={creating}>{creating ? 'A processar...' : 'Criar importação'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Corrigir item</DialogTitle>
            <DialogDescription>Ajuste utilizador, fatura e número do recibo antes da confirmação.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Utilizador</Label>
              <select className="w-full rounded-md border px-3 py-2 text-sm" value={editForm.user_id} onChange={(event) => setEditForm((current) => ({ ...current, user_id: event.target.value, invoice_id: '' }))}>
                <option value="">Selecionar...</option>
                {users.map((user) => (
                  <option key={user.id} value={user.id}>{user.nome_completo} {user.numero_socio ? `(${user.numero_socio})` : ''}</option>
                ))}
              </select>
            </div>
            <div className="space-y-2">
              <Label>Fatura</Label>
              <select className="w-full rounded-md border px-3 py-2 text-sm" value={editForm.invoice_id} onChange={(event) => setEditForm((current) => ({ ...current, invoice_id: event.target.value }))}>
                <option value="">Selecionar...</option>
                {filteredInvoices.map((invoice) => (
                  <option key={invoice.id} value={invoice.id}>{invoice.tipo} {invoice.mes ?? ''} | {formatCurrency(invoice.valor_em_aberto ?? invoice.valor_total)}</option>
                ))}
              </select>
            </div>
            <div className="space-y-2">
              <Label>Número do recibo</Label>
              <Input value={editForm.numero_recibo} onChange={(event) => setEditForm((current) => ({ ...current, numero_recibo: event.target.value }))} />
            </div>
            <div className="space-y-2">
              <Label>Data do recibo</Label>
              <Input type="date" value={editForm.recibo_emitido_em} onChange={(event) => setEditForm((current) => ({ ...current, recibo_emitido_em: event.target.value }))} />
            </div>
            <div className="md:col-span-2 space-y-2">
              <Label>Notas internas</Label>
              <Textarea value={editForm.notes} onChange={(event) => setEditForm((current) => ({ ...current, notes: event.target.value }))} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
            <Button onClick={handleUpdateItem} disabled={!canEdit}>Guardar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={statementPickerOpen} onOpenChange={setStatementPickerOpen}>
        <DialogContent className="max-w-4xl">
          <DialogHeader>
            <DialogTitle>Escolher movimento bancário</DialogTitle>
            <DialogDescription>Filtre por descrição, conta ou valor pendente e selecione o movimento que vai conciliar a mensalidade.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <Input placeholder="Filtrar descrição, referência ou conta" value={statementSearch} onChange={(event) => setStatementSearch(event.target.value)} />
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Descrição</TableHead>
                  <TableHead>Valor</TableHead>
                  <TableHead>Já alocado</TableHead>
                  <TableHead>Pendente</TableHead>
                  <TableHead className="text-right">Ação</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {statementRows.map((statement) => (
                  <TableRow key={statement.id}>
                    <TableCell>{statement.data_movimento}</TableCell>
                    <TableCell>
                      <div>{statement.descricao}</div>
                      <div className="text-xs text-muted-foreground">{statement.conta || '-'}</div>
                    </TableCell>
                    <TableCell>{formatCurrency(statement.valor)}</TableCell>
                    <TableCell>{formatCurrency(statement.valor_conciliado)}</TableCell>
                    <TableCell>{formatCurrency(statement.valor_por_conciliar)}</TableCell>
                    <TableCell className="text-right">
                      <Button type="button" size="sm" variant="outline" onClick={() => void selectStatement(statement.id)} disabled={statementLoading || !canEdit}>
                        Selecionar
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {statementLoading ? <div className="text-sm text-muted-foreground">A carregar movimentos...</div> : null}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}