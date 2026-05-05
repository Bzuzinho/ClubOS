import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import {
  ArrowsClockwise,
  CheckCircle,
  ClockCounterClockwise,
  Eye,
  FileText,
  PencilSimple,
  WarningCircle,
  XCircle,
} from '@phosphor-icons/react';
import { format, isBefore, parseISO, startOfDay, subDays } from 'date-fns';
import { pt } from 'date-fns/locale';
import { toast } from 'sonner';
import type {
  FiscalDocumentRequest,
  FiscalDocumentRequestDocumentType,
  FiscalDocumentRequestPriority,
  FiscalDocumentRequestStatus,
} from './types';

type Filters = {
  status: string;
  documentType: string;
  provider: string;
  search: string;
};

type PaginationMeta = {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage: number;
};

const STATUS_LABELS: Record<FiscalDocumentRequestStatus, string> = {
  pending: 'Pendente',
  in_progress: 'Em tratamento',
  issued: 'Emitido',
  error_data: 'Erro de dados',
  cancelled: 'Cancelado',
  not_applicable: 'Nao aplicavel',
  api_error: 'Erro API',
};

const STATUS_BADGE_CLASSNAMES: Record<FiscalDocumentRequestStatus, string> = {
  pending: 'border-amber-200 bg-amber-50 text-amber-700',
  in_progress: 'border-sky-200 bg-sky-50 text-sky-700',
  issued: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  error_data: 'border-rose-200 bg-rose-50 text-rose-700',
  cancelled: 'border-slate-200 bg-slate-100 text-slate-700',
  not_applicable: 'border-stone-200 bg-stone-100 text-stone-700',
  api_error: 'border-orange-200 bg-orange-50 text-orange-700',
};

const PRIORITY_LABELS: Record<FiscalDocumentRequestPriority, string> = {
  low: 'Baixa',
  normal: 'Normal',
  high: 'Alta',
  urgent: 'Urgente',
};

const PRIORITY_BADGE_CLASSNAMES: Record<FiscalDocumentRequestPriority, string> = {
  low: 'border-slate-200 bg-slate-100 text-slate-700',
  normal: 'border-sky-200 bg-sky-50 text-sky-700',
  high: 'border-amber-200 bg-amber-50 text-amber-700',
  urgent: 'border-rose-200 bg-rose-50 text-rose-700',
};

const DOCUMENT_TYPE_LABELS: Record<FiscalDocumentRequestDocumentType, string> = {
  invoice: 'Fatura',
  receipt: 'Recibo',
  invoice_receipt: 'Fatura-recibo',
  credit_note: 'Nota de credito',
  other: 'Outro',
};

const DEFAULT_FILTERS: Filters = {
  status: 'all',
  documentType: 'all',
  provider: 'all',
  search: '',
};

const DEFAULT_PAGINATION: PaginationMeta = {
  currentPage: 1,
  lastPage: 1,
  total: 0,
  perPage: 25,
};

function getCsrfToken() {
  const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
  return token?.content || '';
}

function formatDate(value?: string | null, includeTime = false) {
  if (!value) return '—';

  const parsed = parseISO(value);
  if (Number.isNaN(parsed.getTime())) return value;

  return format(parsed, includeTime ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy', { locale: pt });
}

function formatCurrency(value?: number | string | null) {
  if (value === null || value === undefined || value === '') return '—';
  const amount = typeof value === 'number' ? value : Number(value);
  if (Number.isNaN(amount)) return '—';

  return new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
  }).format(amount);
}

function getProviderLabel(provider?: string | null) {
  if (!provider) return '—';
  return provider === 'wintouch' ? 'Wintouch' : provider;
}

function getCustomerLabel(request: FiscalDocumentRequest) {
  return request.customer_name || request.user?.nome_completo || request.user?.name || 'Sem cliente';
}

function getRequestAlert(request: FiscalDocumentRequest) {
  if (!(request.status === 'pending' || request.status === 'in_progress')) {
    return null;
  }

  const today = startOfDay(new Date());

  if (request.due_at) {
    const dueDate = parseISO(request.due_at);
    if (!Number.isNaN(dueDate.getTime()) && isBefore(startOfDay(dueDate), today)) {
      return 'Atrasado';
    }
  }

  if (request.created_at) {
    const createdAt = parseISO(request.created_at);
    if (!Number.isNaN(createdAt.getTime()) && isBefore(createdAt, subDays(today, 5))) {
      return 'Por tratar ha +5 dias';
    }
  }

  return null;
}

async function parseJsonResponse(response: Response) {
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    return response.json();
  }

  return response.text();
}

export function FiscalDocumentsTab() {
  const [filters, setFilters] = useState<Filters>(DEFAULT_FILTERS);
  const [requests, setRequests] = useState<FiscalDocumentRequest[]>([]);
  const [pagination, setPagination] = useState<PaginationMeta>(DEFAULT_PAGINATION);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [selectedRequest, setSelectedRequest] = useState<FiscalDocumentRequest | null>(null);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [issuedOpen, setIssuedOpen] = useState(false);
  const [errorOpen, setErrorOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [issuedForm, setIssuedForm] = useState({
    external_document_number: '',
    external_series: '',
    issued_at: format(new Date(), 'yyyy-MM-dd'),
    external_document_url: '',
    notes: '',
  });
  const [errorForm, setErrorForm] = useState({
    last_error: '',
    notes: '',
  });
  const [cancelReason, setCancelReason] = useState('');

  const providerOptions = useMemo(() => {
    const providers = new Set(['wintouch']);
    requests.forEach((request) => {
      if (request.provider) providers.add(request.provider);
    });
    if (filters.provider !== 'all') providers.add(filters.provider);
    return Array.from(providers);
  }, [filters.provider, requests]);

  const loadRequests = async (page = 1, nextFilters?: Filters) => {
    setLoading(true);
    setErrorMessage(null);

    const activeFilters = nextFilters ?? filters;
    const perPage = pagination.perPage || 25;

    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', String(perPage));

    if (activeFilters.status !== 'all') params.set('status', activeFilters.status);
    if (activeFilters.documentType !== 'all') params.set('document_type', activeFilters.documentType);
    if (activeFilters.provider !== 'all') params.set('provider', activeFilters.provider);
    if (activeFilters.search.trim()) params.set('search', activeFilters.search.trim());

    try {
      const response = await fetch(`${route('financeiro.fiscal-document-requests.index')}?${params.toString()}`, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      const payload = await parseJsonResponse(response);

      if (!response.ok) {
        const message = typeof payload === 'string'
          ? payload
          : payload?.message || 'Nao foi possivel carregar a fila de emissao fiscal.';
        throw new Error(message);
      }

      setRequests(Array.isArray(payload?.data) ? payload.data : []);
      setPagination({
        currentPage: Number(payload?.current_page || 1),
        lastPage: Number(payload?.last_page || 1),
        total: Number(payload?.total || 0),
        perPage: Number(payload?.per_page || perPage),
      });
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Erro inesperado ao carregar a fila fiscal.');
      setRequests([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadRequests(1);
  }, []);

  const handleFilterSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    await loadRequests(1);
  };

  const handleResetFilters = async () => {
    setFilters(DEFAULT_FILTERS);
    await loadRequests(1, DEFAULT_FILTERS);
  };

  const handleAction = async (actionKey: string, endpoint: string, payload: Record<string, unknown>, successMessage: string, onSuccess?: () => void) => {
    setSubmitting(actionKey);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });

      const data = await parseJsonResponse(response);

      if (!response.ok) {
        let message = typeof data === 'string'
          ? data
          : data?.message || 'Nao foi possivel concluir a operacao.';

        if (typeof data !== 'string' && data?.errors) {
          const flattened = Object.values(data.errors).flat().join(' ');
          if (flattened) message = flattened;
        }

        throw new Error(message);
      }

      toast.success(successMessage);
      onSuccess?.();
      await loadRequests(pagination.currentPage);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'Erro inesperado ao executar a acao.');
    } finally {
      setSubmitting(null);
    }
  };

  const summary = useMemo(() => {
    return requests.reduce(
      (accumulator, request) => {
        accumulator[request.status] = (accumulator[request.status] || 0) + 1;
        return accumulator;
      },
      {
        pending: 0,
        in_progress: 0,
        issued: 0,
        error_data: 0,
        cancelled: 0,
        not_applicable: 0,
        api_error: 0,
      } as Record<FiscalDocumentRequestStatus, number>
    );
  }, [requests]);

  const openIssuedModal = (request: FiscalDocumentRequest) => {
    setSelectedRequest(request);
    setIssuedForm({
      external_document_number: request.external_document_number || '',
      external_series: request.external_series || '',
      issued_at: request.issued_at ? format(parseISO(request.issued_at), 'yyyy-MM-dd') : format(new Date(), 'yyyy-MM-dd'),
      external_document_url: request.external_document_url || '',
      notes: request.notes || '',
    });
    setIssuedOpen(true);
  };

  const openErrorModal = (request: FiscalDocumentRequest) => {
    setSelectedRequest(request);
    setErrorForm({
      last_error: request.last_error || '',
      notes: request.notes || '',
    });
    setErrorOpen(true);
  };

  const openCancelModal = (request: FiscalDocumentRequest) => {
    setSelectedRequest(request);
    setCancelReason(request.last_error || '');
    setCancelOpen(true);
  };

  const openDetailsModal = (request: FiscalDocumentRequest) => {
    setSelectedRequest(request);
    setDetailsOpen(true);
  };

  return (
    <div className="space-y-4">
      <Card className="p-4 sm:p-5">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-foreground">Emissao Fiscal</h2>
            <p className="mt-1 text-xs text-muted-foreground">
              Gestao manual da fila fiscal pendente para emissao e tratamento operacional.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
            {Object.entries(summary).map(([status, total]) => (
              <div key={status} className="rounded-lg border bg-muted/30 px-3 py-2">
                <div className="text-[11px] text-muted-foreground">{STATUS_LABELS[status as FiscalDocumentRequestStatus]}</div>
                <div className="text-base font-semibold text-foreground">{total}</div>
              </div>
            ))}
          </div>
        </div>
      </Card>

      <Card className="p-4 sm:p-5">
        <form className="space-y-3" onSubmit={handleFilterSubmit}>
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div className="space-y-1.5">
              <Label htmlFor="fiscal-status">Estado</Label>
              <Select value={filters.status} onValueChange={(value) => setFilters((current) => ({ ...current, status: value }))}>
                <SelectTrigger id="fiscal-status">
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  {Object.entries(STATUS_LABELS).map(([value, label]) => (
                    <SelectItem key={value} value={value}>{label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="fiscal-document-type">Tipo documento</Label>
              <Select value={filters.documentType} onValueChange={(value) => setFilters((current) => ({ ...current, documentType: value }))}>
                <SelectTrigger id="fiscal-document-type">
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  {Object.entries(DOCUMENT_TYPE_LABELS).map(([value, label]) => (
                    <SelectItem key={value} value={value}>{label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="fiscal-provider">Provider</Label>
              <Select value={filters.provider} onValueChange={(value) => setFilters((current) => ({ ...current, provider: value }))}>
                <SelectTrigger id="fiscal-provider">
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  {providerOptions.map((provider) => (
                    <SelectItem key={provider} value={provider}>{getProviderLabel(provider)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="fiscal-search">Pesquisa livre</Label>
              <Input
                id="fiscal-search"
                value={filters.search}
                onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
                placeholder="Cliente, NIF, referencia, numero externo..."
              />
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" size="sm" disabled={loading}>
              <FileText size={16} className="mr-1.5" />
              Filtrar
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => void handleResetFilters()} disabled={loading}>
              Limpar
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => void loadRequests(pagination.currentPage)} disabled={loading}>
              <ArrowsClockwise size={16} className="mr-1.5" />
              Atualizar
            </Button>
          </div>
        </form>
      </Card>

      <Card className="overflow-hidden">
        {errorMessage ? (
          <div className="border-b bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {errorMessage}
          </div>
        ) : null}

        {loading ? (
          <div className="px-4 py-10 text-sm text-muted-foreground">A carregar fila fiscal...</div>
        ) : requests.length === 0 ? (
          <div className="px-4 py-10 text-sm text-muted-foreground">
            Nao existem documentos fiscais pendentes para os filtros selecionados.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Estado</TableHead>
                  <TableHead>Prioridade</TableHead>
                  <TableHead>Cliente/Utilizador</TableHead>
                  <TableHead>Tipo documento</TableHead>
                  <TableHead>Valor</TableHead>
                  <TableHead>Data pagamento</TableHead>
                  <TableHead>Prazo/limite</TableHead>
                  <TableHead>Referencia interna</TableHead>
                  <TableHead>Nº documento externo</TableHead>
                  <TableHead>Criado em</TableHead>
                  <TableHead className="text-right">Acoes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {requests.map((request) => {
                  const alert = getRequestAlert(request);
                  const canMarkIssued = request.status === 'pending' || request.status === 'in_progress' || request.status === 'error_data';
                  const canMarkError = request.status !== 'issued' && request.status !== 'cancelled';
                  const canCancel = request.status !== 'issued' && request.status !== 'cancelled';
                  const actionPrefix = `request:${request.id}`;

                  return (
                    <TableRow key={request.id}>
                      <TableCell>
                        <div className="flex flex-col gap-1">
                          <Badge variant="outline" className={STATUS_BADGE_CLASSNAMES[request.status]}>
                            {STATUS_LABELS[request.status]}
                          </Badge>
                          {alert ? (
                            <Badge variant="outline" className="w-fit border-orange-200 bg-orange-50 text-orange-700">
                              <ClockCounterClockwise size={12} className="mr-1" />
                              {alert}
                            </Badge>
                          ) : null}
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className={PRIORITY_BADGE_CLASSNAMES[request.priority]}>
                          {PRIORITY_LABELS[request.priority]}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="min-w-[180px]">
                          <div className="font-medium text-foreground">{getCustomerLabel(request)}</div>
                          <div className="text-xs text-muted-foreground">{request.customer_tax_number || request.user?.nif || 'Sem NIF'}</div>
                        </div>
                      </TableCell>
                      <TableCell>{DOCUMENT_TYPE_LABELS[request.document_type] || request.document_type}</TableCell>
                      <TableCell>{formatCurrency(request.amount)}</TableCell>
                      <TableCell>{formatDate(request.paid_at)}</TableCell>
                      <TableCell>{formatDate(request.due_at)}</TableCell>
                      <TableCell>
                        <div className="min-w-[140px] text-sm text-foreground">{request.internal_reference || '—'}</div>
                        <div className="text-xs text-muted-foreground">{getProviderLabel(request.provider)}</div>
                      </TableCell>
                      <TableCell>{request.external_document_number || '—'}</TableCell>
                      <TableCell>{formatDate(request.created_at, true)}</TableCell>
                      <TableCell>
                        <div className="flex flex-wrap justify-end gap-2">
                          {request.status === 'pending' ? (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={submitting === `${actionPrefix}:progress`}
                              onClick={() => void handleAction(
                                `${actionPrefix}:progress`,
                                route('financeiro.fiscal-document-requests.mark-in-progress', request.id),
                                {},
                                'Pedido marcado como em tratamento.',
                              )}
                            >
                              <PencilSimple size={16} className="mr-1.5" />
                              Tratar
                            </Button>
                          ) : null}

                          {canMarkIssued ? (
                            <Button type="button" size="sm" variant="outline" onClick={() => openIssuedModal(request)}>
                              <CheckCircle size={16} className="mr-1.5" />
                              Emitido
                            </Button>
                          ) : null}

                          {canMarkError ? (
                            <Button type="button" size="sm" variant="outline" onClick={() => openErrorModal(request)}>
                              <WarningCircle size={16} className="mr-1.5" />
                              Erro dados
                            </Button>
                          ) : null}

                          {canCancel ? (
                            <Button type="button" size="sm" variant="outline" onClick={() => openCancelModal(request)}>
                              <XCircle size={16} className="mr-1.5" />
                              Cancelar
                            </Button>
                          ) : null}

                          <Button type="button" size="sm" variant="ghost" onClick={() => openDetailsModal(request)}>
                            <Eye size={16} className="mr-1.5" />
                            Ver
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        )}

        <div className="flex flex-col gap-3 border-t px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
          <div className="text-muted-foreground">
            Pagina {pagination.currentPage} de {pagination.lastPage} · {pagination.total} registos
          </div>

          <div className="flex items-center gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={loading || pagination.currentPage <= 1}
              onClick={() => void loadRequests(pagination.currentPage - 1)}
            >
              Anterior
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={loading || pagination.currentPage >= pagination.lastPage}
              onClick={() => void loadRequests(pagination.currentPage + 1)}
            >
              Seguinte
            </Button>
          </div>
        </div>
      </Card>

      <Dialog open={issuedOpen} onOpenChange={setIssuedOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Marcar como emitido</DialogTitle>
            <DialogDescription>Regista os dados do documento emitido externamente.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="issued-number">Numero documento Wintouch</Label>
              <Input
                id="issued-number"
                value={issuedForm.external_document_number}
                onChange={(event) => setIssuedForm((current) => ({ ...current, external_document_number: event.target.value }))}
              />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="issued-series">Serie</Label>
                <Input
                  id="issued-series"
                  value={issuedForm.external_series}
                  onChange={(event) => setIssuedForm((current) => ({ ...current, external_series: event.target.value }))}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="issued-date">Data emissao</Label>
                <Input
                  id="issued-date"
                  type="date"
                  value={issuedForm.issued_at}
                  onChange={(event) => setIssuedForm((current) => ({ ...current, issued_at: event.target.value }))}
                />
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="issued-url">URL/documento externo</Label>
              <Input
                id="issued-url"
                value={issuedForm.external_document_url}
                onChange={(event) => setIssuedForm((current) => ({ ...current, external_document_url: event.target.value }))}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="issued-notes">Notas</Label>
              <Textarea
                id="issued-notes"
                rows={4}
                value={issuedForm.notes}
                onChange={(event) => setIssuedForm((current) => ({ ...current, notes: event.target.value }))}
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setIssuedOpen(false)}>
              Fechar
            </Button>
            <Button
              type="button"
              disabled={!selectedRequest || !issuedForm.external_document_number.trim() || submitting === `request:${selectedRequest?.id}:issued`}
              onClick={() => {
                if (!selectedRequest) return;
                void handleAction(
                  `request:${selectedRequest.id}:issued`,
                  route('financeiro.fiscal-document-requests.mark-issued', selectedRequest.id),
                  {
                    external_document_number: issuedForm.external_document_number.trim(),
                    external_series: issuedForm.external_series.trim() || null,
                    issued_at: issuedForm.issued_at || null,
                    external_document_url: issuedForm.external_document_url.trim() || null,
                    notes: issuedForm.notes.trim() || null,
                    document_type: selectedRequest.document_type,
                  },
                  'Pedido marcado como emitido.',
                  () => setIssuedOpen(false),
                );
              }}
            >
              Confirmar emitido
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={errorOpen} onOpenChange={setErrorOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Marcar erro de dados</DialogTitle>
            <DialogDescription>Regista o motivo para bloquear a emissao deste pedido.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-3 py-2">
            <div className="space-y-1.5">
              <Label htmlFor="error-reason">Motivo</Label>
              <Textarea
                id="error-reason"
                rows={4}
                value={errorForm.last_error}
                onChange={(event) => setErrorForm((current) => ({ ...current, last_error: event.target.value }))}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="error-notes">Notas</Label>
              <Textarea
                id="error-notes"
                rows={3}
                value={errorForm.notes}
                onChange={(event) => setErrorForm((current) => ({ ...current, notes: event.target.value }))}
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setErrorOpen(false)}>
              Fechar
            </Button>
            <Button
              type="button"
              disabled={!selectedRequest || !errorForm.last_error.trim() || submitting === `request:${selectedRequest?.id}:error`}
              onClick={() => {
                if (!selectedRequest) return;
                void handleAction(
                  `request:${selectedRequest.id}:error`,
                  route('financeiro.fiscal-document-requests.mark-error-data', selectedRequest.id),
                  {
                    last_error: errorForm.last_error.trim(),
                    notes: errorForm.notes.trim() || null,
                  },
                  'Pedido marcado com erro de dados.',
                  () => setErrorOpen(false),
                );
              }}
            >
              Confirmar erro
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancelar pedido</DialogTitle>
            <DialogDescription>Indica o motivo do cancelamento da emissao fiscal.</DialogDescription>
          </DialogHeader>

          <div className="space-y-1.5 py-2">
            <Label htmlFor="cancel-reason">Motivo</Label>
            <Textarea
              id="cancel-reason"
              rows={4}
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setCancelOpen(false)}>
              Fechar
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={!selectedRequest || !cancelReason.trim() || submitting === `request:${selectedRequest?.id}:cancel`}
              onClick={() => {
                if (!selectedRequest) return;
                void handleAction(
                  `request:${selectedRequest.id}:cancel`,
                  route('financeiro.fiscal-document-requests.mark-cancelled', selectedRequest.id),
                  { reason: cancelReason.trim() },
                  'Pedido cancelado com sucesso.',
                  () => setCancelOpen(false),
                );
              }}
            >
              Confirmar cancelamento
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Detalhes do pedido fiscal</DialogTitle>
            <DialogDescription>Consulta operacional do pedido e das ligacoes internas associadas.</DialogDescription>
          </DialogHeader>

          {selectedRequest ? (
            <div className="grid gap-4 py-2 sm:grid-cols-2">
              <div className="space-y-3">
                <DetailItem label="Cliente" value={getCustomerLabel(selectedRequest)} />
                <DetailItem label="NIF" value={selectedRequest.customer_tax_number || selectedRequest.user?.nif || '—'} />
                <DetailItem label="Email" value={selectedRequest.customer_email || selectedRequest.user?.email || '—'} />
                <DetailItem label="Morada" value={selectedRequest.customer_address || selectedRequest.user?.morada || '—'} multiline />
                <DetailItem label="Valor" value={formatCurrency(selectedRequest.amount)} />
                <DetailItem label="Descricao" value={selectedRequest.description || '—'} multiline />
                <DetailItem label="Referencia interna" value={selectedRequest.internal_reference || '—'} />
              </div>

              <div className="space-y-3">
                <DetailItem
                  label="Fatura associada"
                  value={selectedRequest.invoice?.id
                    ? `${selectedRequest.invoice.id} · ${selectedRequest.invoice.numero_recibo || selectedRequest.invoice.referencia_pagamento || 'sem referencia'}`
                    : '—'}
                  multiline
                />
                <DetailItem
                  label="Extrato bancario associado"
                  value={(selectedRequest.bankStatement || selectedRequest.bank_statement)?.id
                    ? `${(selectedRequest.bankStatement || selectedRequest.bank_statement)?.id} · ${(selectedRequest.bankStatement || selectedRequest.bank_statement)?.referencia || (selectedRequest.bankStatement || selectedRequest.bank_statement)?.descricao || 'sem referencia'}`
                    : '—'}
                  multiline
                />
                <DetailItem
                  label="Conciliacao associada"
                  value={(selectedRequest.mapaConciliacao || selectedRequest.mapa_conciliacao)?.id
                    ? `${(selectedRequest.mapaConciliacao || selectedRequest.mapa_conciliacao)?.id} · ${formatCurrency((selectedRequest.mapaConciliacao || selectedRequest.mapa_conciliacao)?.valor_conciliado || null)}`
                    : '—'}
                  multiline
                />
                <DetailItem label="Notas" value={selectedRequest.notes || '—'} multiline />
                <DetailItem label="Erro" value={selectedRequest.last_error || '—'} multiline />
                <DetailItem label="Documento externo" value={selectedRequest.external_document_number || '—'} />
                <DetailItem label="URL externa" value={selectedRequest.external_document_url || '—'} multiline />
              </div>

              <div className="sm:col-span-2">
                <Label className="text-xs uppercase tracking-wide text-muted-foreground">Metadados</Label>
                <div className="mt-1 rounded-md border bg-muted/30 p-3 text-xs text-foreground">
                  <pre className="whitespace-pre-wrap break-all">
                    {selectedRequest.metadata ? JSON.stringify(selectedRequest.metadata, null, 2) : 'Sem metadados.'}
                  </pre>
                </div>
              </div>
            </div>
          ) : null}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDetailsOpen(false)}>
              Fechar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function DetailItem({ label, value, multiline = false }: { label: string; value: string; multiline?: boolean }) {
  return (
    <div>
      <Label className="text-xs uppercase tracking-wide text-muted-foreground">{label}</Label>
      <div className={multiline ? 'mt-1 whitespace-pre-wrap text-sm text-foreground' : 'mt-1 text-sm text-foreground'}>
        {value}
      </div>
    </div>
  );
}