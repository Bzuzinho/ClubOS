import { useEffect, useMemo, useState } from 'react';
import { format } from 'date-fns';
import { toast } from 'sonner';

import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';

import { CentroCusto, ExtratoBancario, OpenInvoiceListItem, OpenMovementListItem } from './types';

type CreditTarget =
  | { key: string; kind: 'user'; id: string; label: string }
  | { key: string; kind: 'family'; id: string; label: string };

interface BankStatementReconciliationDialogProps {
  open: boolean;
  statement: ExtratoBancario | null;
  centrosCusto: CentroCusto[];
  buildRouteUrl: (name: string, params?: string | number | Record<string, unknown>, query?: Record<string, string>) => string;
  buildJsonHeaders: () => Record<string, string>;
  onCompleted: (statementId: string) => void;
  onOpenChange: (open: boolean) => void;
}

const toNumber = (value: unknown, fallback = 0): number => {
  if (typeof value === 'number' && !Number.isNaN(value)) return value;
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value);
    return Number.isNaN(parsed) ? fallback : parsed;
  }

  return fallback;
};

const getStatementStatus = (statement: ExtratoBancario | null): 'unreconciled' | 'partial' | 'reconciled' => {
  if (!statement) return 'unreconciled';
  if (statement.conciliacao_status === 'partial' || statement.conciliacao_status === 'reconciled' || statement.conciliacao_status === 'unreconciled') {
    return statement.conciliacao_status;
  }

  return statement.conciliado ? 'reconciled' : 'unreconciled';
};

const formatCurrency = (value: number) => `€${value.toFixed(2)}`;

export function BankStatementReconciliationDialog({
  open,
  statement,
  centrosCusto,
  buildRouteUrl,
  buildJsonHeaders,
  onCompleted,
  onOpenChange,
}: BankStatementReconciliationDialogProps) {
  const [searchTerm, setSearchTerm] = useState('');
  const [openInvoices, setOpenInvoices] = useState<OpenInvoiceListItem[]>([]);
  const [openMovements, setOpenMovements] = useState<OpenMovementListItem[]>([]);
  const [invoiceAllocations, setInvoiceAllocations] = useState<Record<string, string>>({});
  const [movementAllocations, setMovementAllocations] = useState<Record<string, string>>({});
  const [movementCostCenters, setMovementCostCenters] = useState<Record<string, string>>({});
  const [createCredit, setCreateCredit] = useState(false);
  const [creditTarget, setCreditTarget] = useState('');
  const [notes, setNotes] = useState('');
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [loadingMovements, setLoadingMovements] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [invoiceTotal, setInvoiceTotal] = useState(0);
  const [movementTotal, setMovementTotal] = useState(0);

  useEffect(() => {
    if (!open || !statement) {
      return;
    }

    const initialSearch = [statement.descricao, statement.referencia]
      .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
      .join(' ')
      .trim();

    setSearchTerm(initialSearch);
    setOpenInvoices([]);
    setOpenMovements([]);
    setInvoiceAllocations({});
    setMovementAllocations({});
    setMovementCostCenters({});
    setCreateCredit(false);
    setCreditTarget('');
    setNotes('');
  }, [open, statement]);

  useEffect(() => {
    if (!open || !statement) {
      return;
    }

    const timeout = window.setTimeout(() => {
      void Promise.all([loadOpenInvoices(searchTerm), loadOpenMovements(searchTerm)]);
    }, 300);

    return () => window.clearTimeout(timeout);
  }, [open, statement, searchTerm]);

  const statementAvailableAmount = statement
    ? Math.abs(toNumber(statement.valor_por_conciliar, Math.abs(toNumber(statement.valor))))
    : 0;
  const statementTotalAmount = statement ? Math.abs(toNumber(statement.valor)) : 0;
  const invoiceAllocatedTotal = useMemo(
    () => Object.values(invoiceAllocations).reduce((sum, value) => sum + toNumber(value, 0), 0),
    [invoiceAllocations],
  );
  const movementAllocatedTotal = useMemo(
    () => Object.values(movementAllocations).reduce((sum, value) => sum + toNumber(value, 0), 0),
    [movementAllocations],
  );
  const allocatedTotal = invoiceAllocatedTotal + movementAllocatedTotal;
  const remainingAmount = Math.max(statementAvailableAmount - allocatedTotal, 0);
  const status = getStatementStatus(statement);

  const creditTargets = useMemo<CreditTarget[]>(() => {
    const mapped = new Map<string, CreditTarget>();

    [...openInvoices, ...openMovements].forEach((item) => {
      if (item.user_id && item.user_name) {
        mapped.set(`user:${item.user_id}`, {
          key: `user:${item.user_id}`,
          kind: 'user',
          id: item.user_id,
          label: item.user_name,
        });
      }

      if (item.family_id && item.family_name) {
        mapped.set(`family:${item.family_id}`, {
          key: `family:${item.family_id}`,
          kind: 'family',
          id: item.family_id,
          label: item.family_name,
        });
      }
    });

    return Array.from(mapped.values()).sort((left, right) => left.label.localeCompare(right.label));
  }, [openInvoices, openMovements]);

  const loadOpenInvoices = async (search: string) => {
    if (!statement) return;

    setLoadingInvoices(true);

    try {
      const response = await fetch(buildRouteUrl('financeiro.invoices.open', undefined, {
        per_page: '25',
        search: search.trim(),
      }), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao carregar faturas em aberto');
      }

      const payload = await response.json();
      setOpenInvoices(Array.isArray(payload?.data) ? payload.data : []);
      setInvoiceTotal(toNumber(payload?.total, 0));
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao carregar faturas em aberto';
      toast.error(message);
    } finally {
      setLoadingInvoices(false);
    }
  };

  const loadOpenMovements = async (search: string) => {
    if (!statement) return;

    setLoadingMovements(true);

    try {
      const response = await fetch(buildRouteUrl('financeiro.movements.open', undefined, {
        per_page: '25',
        search: search.trim(),
      }), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao carregar movimentos em aberto');
      }

      const payload = await response.json();
      const movements = Array.isArray(payload?.data) ? payload.data : [];
      setOpenMovements(movements);
      setMovementCostCenters((current) => {
        const next = { ...current };
        movements.forEach((movement: OpenMovementListItem) => {
          if (!next[movement.id] && movement.default_centro_custo_id) {
            next[movement.id] = movement.default_centro_custo_id;
          }
        });
        return next;
      });
      setMovementTotal(toNumber(payload?.total, 0));
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao carregar movimentos em aberto';
      toast.error(message);
    } finally {
      setLoadingMovements(false);
    }
  };

  const handleSubmit = async () => {
    if (!statement) {
      return;
    }

    const invoices = openInvoices
      .map((invoice) => ({
        invoice_id: invoice.id,
        amount: toNumber(invoiceAllocations[invoice.id], 0),
      }))
      .filter((allocation) => allocation.amount > 0);

    const movements = openMovements
      .map((movement) => ({
        movement_id: movement.id,
        amount: toNumber(movementAllocations[movement.id], 0),
        centro_custo_id: movementCostCenters[movement.id] || movement.default_centro_custo_id || undefined,
      }))
      .filter((allocation) => allocation.amount > 0);

    if (invoices.length === 0 && movements.length === 0) {
      toast.error('Indique pelo menos uma alocacao manual.');
      return;
    }

    if (allocatedTotal - statementAvailableAmount > 0.009) {
      toast.error('O total alocado nao pode exceder o valor disponivel da linha bancaria.');
      return;
    }

    for (const invoice of invoices) {
      const source = openInvoices.find((item) => item.id === invoice.invoice_id);
      if (source && invoice.amount - toNumber(source.valor_em_aberto, 0) > 0.009) {
        toast.error('Uma das alocacoes de fatura excede o valor em aberto.');
        return;
      }
    }

    for (const movement of movements) {
      const source = openMovements.find((item) => item.id === movement.movement_id);
      if (source && movement.amount - toNumber(source.valor_em_aberto, 0) > 0.009) {
        toast.error('Uma das alocacoes de movimento excede o valor em aberto.');
        return;
      }

      if (source?.requires_centro_custo && !movement.centro_custo_id) {
        toast.error('Existe um movimento sem centro de custo. Preencha essa linha antes de conciliar.');
        return;
      }
    }

    if (createCredit && remainingAmount > 0.009 && !creditTarget) {
      toast.error('Escolha explicitamente o utilizador ou a familia do credito.');
      return;
    }

    const resolvedCreditTarget = creditTargets.find((candidate) => candidate.key === creditTarget) ?? null;

    setSubmitting(true);

    try {
      const response = await fetch(buildRouteUrl('financeiro.bank-statements.allocate', statement.id), {
        method: 'POST',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
          invoices,
          movements,
          create_credit: createCredit && remainingAmount > 0.009,
          credit_user_id: resolvedCreditTarget?.kind === 'user' ? resolvedCreditTarget.id : undefined,
          credit_family_id: resolvedCreditTarget?.kind === 'family' ? resolvedCreditTarget.id : undefined,
          notes: notes || undefined,
        }),
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        const message = payload?.errors?.allocations?.[0]
          || payload?.errors?.movements?.[0]
          || payload?.errors?.create_credit?.[0]
          || payload?.message
          || 'Erro ao conciliar manualmente a linha bancaria';

        throw new Error(message);
      }

      const payload = await response.json();

      if ((payload?.summary?.new_fiscal_requests || 0) > 0) {
        toast.success('Conciliacao manual concluida. Foram criados pedidos fiscais para as faturas liquidadas.');
      } else if (payload?.summary?.created_credit) {
        toast.success('Conciliacao manual concluida com credito associado.');
      } else if (payload?.summary?.bank_statement_partial) {
        toast.success('Conciliacao manual registada. A linha bancaria ficou parcial.');
      } else {
        toast.success('Conciliacao manual registada com sucesso.');
      }

      onCompleted(statement.id);
      onOpenChange(false);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao conciliar manualmente a linha bancaria';
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-5xl max-h-[92vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Conciliacao Manual</DialogTitle>
          <DialogDescription>
            Use um unico fluxo para conciliar faturas abertas, movimentos pendentes e, se existir excedente, criar credito em conta corrente.
          </DialogDescription>
        </DialogHeader>

        {statement ? (
          <div className="space-y-4">
            <Card className="p-4 bg-muted/40">
              <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6 text-sm">
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Data</div>
                  <div className="mt-1 font-medium">{format(new Date(statement.data_movimento), 'dd/MM/yyyy')}</div>
                </div>
                <div className="xl:col-span-2">
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Descricao</div>
                  <div className="mt-1 font-medium break-words">{statement.descricao}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total / disponivel</div>
                  <div className="mt-1 font-medium">{formatCurrency(statementTotalAmount)} / {formatCurrency(statementAvailableAmount)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total alocado</div>
                  <div className="mt-1 font-medium">{formatCurrency(allocatedTotal)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Diferenca / restante</div>
                  <div className={`mt-1 font-semibold ${remainingAmount > 0.009 ? 'text-amber-600' : 'text-emerald-600'}`}>
                    {formatCurrency(remainingAmount)}
                  </div>
                </div>
              </div>

              <div className="mt-3 flex items-center gap-2">
                <span className="text-xs uppercase tracking-wide text-muted-foreground">Estado</span>
                <Badge variant="outline">
                  {status === 'reconciled' ? 'conciliado' : status === 'partial' ? 'parcial' : 'por conciliar'}
                </Badge>
              </div>
            </Card>

            <div className="space-y-2">
              <Label>Pesquisa</Label>
              <Input
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="Pesquisar por nome, NIF, numero de socio ou familia"
              />
              <div className="text-xs text-muted-foreground">
                Pesquisa com debounce e resultados paginados. Mostra as primeiras 25 faturas e 25 movimentos compatíveis.
              </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
              <Card className="p-4 space-y-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <h3 className="font-semibold">Mensalidades / Faturas em aberto</h3>
                    <p className="text-xs text-muted-foreground">{invoiceTotal} resultado(s) compatíveis</p>
                  </div>
                  <Badge variant="outline">Backend: valor_em_aberto</Badge>
                </div>

                {loadingInvoices ? (
                  <div className="py-8 text-center text-sm text-muted-foreground">A carregar faturas em aberto...</div>
                ) : openInvoices.length === 0 ? (
                  <div className="py-8 text-center text-sm text-muted-foreground">Nao foram encontradas faturas em aberto para esta pesquisa.</div>
                ) : (
                  <div className="space-y-3 max-h-[360px] overflow-y-auto pr-1">
                    {openInvoices.map((invoice) => (
                      <div key={invoice.id} className="rounded-lg border p-3 space-y-2">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <div className="font-medium text-sm">{invoice.user_name || 'Utilizador'}</div>
                            <div className="text-xs text-muted-foreground break-words">
                              {[invoice.family_name, invoice.tipo, invoice.mes].filter(Boolean).join(' · ') || 'Fatura aberta'}
                            </div>
                          </div>
                          <Badge variant="outline">{formatCurrency(toNumber(invoice.valor_em_aberto, 0))}</Badge>
                        </div>

                        <div className="grid gap-2 md:grid-cols-[minmax(0,1fr)_140px] md:items-end">
                          <div className="text-xs text-muted-foreground">
                            Vencimento {invoice.vencimento ? format(new Date(invoice.vencimento), 'dd/MM/yyyy') : '-'}
                            {invoice.centro_custo_name ? ` · ${invoice.centro_custo_name}` : ''}
                          </div>
                          <div className="space-y-1">
                            <Label className="text-xs">Valor a alocar</Label>
                            <Input
                              type="number"
                              min="0"
                              step="0.01"
                              max={toNumber(invoice.valor_em_aberto, 0).toFixed(2)}
                              value={invoiceAllocations[invoice.id] ?? ''}
                              onChange={(event) => setInvoiceAllocations((current) => ({ ...current, [invoice.id]: event.target.value }))}
                            />
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </Card>

              <Card className="p-4 space-y-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <h3 className="font-semibold">Movimentos financeiros em aberto</h3>
                    <p className="text-xs text-muted-foreground">{movementTotal} resultado(s) compatíveis</p>
                  </div>
                  <Badge variant="outline">Fluxo canonico</Badge>
                </div>

                {loadingMovements ? (
                  <div className="py-8 text-center text-sm text-muted-foreground">A carregar movimentos em aberto...</div>
                ) : openMovements.length === 0 ? (
                  <div className="py-8 text-center text-sm text-muted-foreground">Nao foram encontrados movimentos em aberto para esta pesquisa.</div>
                ) : (
                  <div className="space-y-3 max-h-[360px] overflow-y-auto pr-1">
                    {openMovements.map((movement) => {
                      const resolvedCostCenterId = movementCostCenters[movement.id] || movement.default_centro_custo_id || '';

                      return (
                        <div key={movement.id} className="rounded-lg border p-3 space-y-2">
                          <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                              <div className="font-medium text-sm break-words">{movement.descricao}</div>
                              <div className="text-xs text-muted-foreground break-words">
                                {[movement.user_name, movement.family_name, movement.tipo].filter(Boolean).join(' · ') || 'Movimento aberto'}
                              </div>
                            </div>
                            <Badge variant="outline">{formatCurrency(toNumber(movement.valor_em_aberto, 0))}</Badge>
                          </div>

                          <div className="grid gap-2 md:grid-cols-[minmax(0,1fr)_140px] md:items-end">
                            <div className="space-y-2 text-xs text-muted-foreground">
                              <div>
                                Emissao {movement.data_emissao ? format(new Date(movement.data_emissao), 'dd/MM/yyyy') : '-'}
                                {movement.centro_custo_name ? ` · ${movement.centro_custo_name}` : ''}
                              </div>

                              {movement.requires_centro_custo ? (
                                <div className="space-y-1">
                                  <Label className="text-xs">Centro de custo obrigatorio para esta linha</Label>
                                  <Select
                                    value={resolvedCostCenterId}
                                    onValueChange={(value) => setMovementCostCenters((current) => ({ ...current, [movement.id]: value }))}
                                  >
                                    <SelectTrigger>
                                      <SelectValue placeholder="Selecionar centro de custo" />
                                    </SelectTrigger>
                                    <SelectContent>
                                      {centrosCusto.filter((item) => item.ativo).map((centroCusto) => (
                                        <SelectItem key={centroCusto.id} value={centroCusto.id}>
                                          {centroCusto.nome}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </div>
                              ) : null}
                            </div>

                            <div className="space-y-1">
                              <Label className="text-xs">Valor a alocar</Label>
                              <Input
                                type="number"
                                min="0"
                                step="0.01"
                                max={toNumber(movement.valor_em_aberto, 0).toFixed(2)}
                                value={movementAllocations[movement.id] ?? ''}
                                onChange={(event) => setMovementAllocations((current) => ({ ...current, [movement.id]: event.target.value }))}
                              />
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </Card>
            </div>

            <Card className="p-4 space-y-4">
              <div className="flex items-start gap-3">
                <Checkbox
                  id="bank-statement-create-credit"
                  checked={createCredit}
                  onCheckedChange={(checked) => setCreateCredit(checked === true)}
                />
                <div className="space-y-1">
                  <Label htmlFor="bank-statement-create-credit">Credito em conta corrente</Label>
                  <p className="text-xs text-muted-foreground">
                    Se a soma alocada for inferior ao valor disponivel, o excedente pode ficar associado a um utilizador ou familia escolhido explicitamente.
                  </p>
                </div>
              </div>

              {createCredit && remainingAmount > 0.009 ? (
                <div className="space-y-2">
                  <Label>Destino do credito</Label>
                  <Select value={creditTarget} onValueChange={setCreditTarget}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecionar utilizador ou familia" />
                    </SelectTrigger>
                    <SelectContent>
                      {creditTargets.map((target) => (
                        <SelectItem key={target.key} value={target.key}>
                          {target.kind === 'user' ? `Utilizador: ${target.label}` : `Familia: ${target.label}`}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ) : null}

              <div className="grid gap-3 md:grid-cols-3 text-sm">
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total alocado</div>
                  <div className="mt-1 font-semibold">{formatCurrency(allocatedTotal)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Restante</div>
                  <div className={`mt-1 font-semibold ${remainingAmount > 0.009 ? 'text-amber-600' : 'text-emerald-600'}`}>
                    {formatCurrency(remainingAmount)}
                  </div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Estado previsto</div>
                  <div className="mt-1 font-semibold">
                    {remainingAmount <= 0.009 || (createCredit && creditTarget) ? 'conciliado' : allocatedTotal > 0 ? 'parcial' : 'por conciliar'}
                  </div>
                </div>
              </div>

              <div className="space-y-2">
                <Label>Observacoes</Label>
                <Textarea
                  rows={3}
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  placeholder="Observacoes internas da conciliacao manual"
                />
              </div>
            </Card>

            <DialogFooter className="gap-2 sm:justify-end">
              <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancelar
              </Button>
              <Button onClick={() => void handleSubmit()} disabled={submitting}>
                Conciliar
              </Button>
            </DialogFooter>
          </div>
        ) : null}
      </DialogContent>
    </Dialog>
  );
}