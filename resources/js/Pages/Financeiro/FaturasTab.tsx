import { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Fatura, FaturaItem, User, CentroCusto, Product, LancamentoFinanceiro, MonthlyFee, InvoiceType, ConciliacaoMapa, ExtratoBancario, PaymentMethod } from './types';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogTrigger, DialogFooter } from '@/Components/ui/dialog';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, MagicWand, X, Check, Trash, PencilSimple, SquaresFour, ListBullets } from '@phosphor-icons/react';
import { format, addMonths, isBefore, isAfter, startOfMonth } from 'date-fns';
import { toast } from 'sonner';

interface FaturasTabProps {
  faturas: Fatura[];
  setFaturas: React.Dispatch<React.SetStateAction<Fatura[]>>;
  faturaItens: FaturaItem[];
  setFaturaItens: React.Dispatch<React.SetStateAction<FaturaItem[]>>;
  lancamentos: LancamentoFinanceiro[];
  setLancamentos: React.Dispatch<React.SetStateAction<LancamentoFinanceiro[]>>;
  conciliacoes: ConciliacaoMapa[];
  setConciliacoes: React.Dispatch<React.SetStateAction<ConciliacaoMapa[]>>;
  extratos: ExtratoBancario[];
  setExtratos: React.Dispatch<React.SetStateAction<ExtratoBancario[]>>;
  users: User[];
  mensalidades: MonthlyFee[];
  centrosCusto: CentroCusto[];
  products: Product[];
  setProducts: React.Dispatch<React.SetStateAction<Product[]>>;
  invoiceTypes: InvoiceType[];
  paymentMethods: PaymentMethod[];
}

export function FaturasTab({
  faturas,
  setFaturas,
  faturaItens,
  setFaturaItens,
  lancamentos,
  setLancamentos,
  conciliacoes,
  setConciliacoes,
  extratos,
  setExtratos,
  users,
  mensalidades,
  centrosCusto,
  products,
  setProducts,
  invoiceTypes,
  paymentMethods,
}: FaturasTabProps) {
  const [estadoFilter, setEstadoFilter] = useState<string>('all');
  const [viewMode, setViewMode] = useState<'card' | 'table'>('table');
  const toNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const parsed = Number(value);
      return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
  };
  const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    return token?.content || '';
  };
  const getApiHeaders = () => {
    const csrfToken = getCsrfToken();

    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    };
  };
  const getAxiosJsonConfig = () => ({
    headers: getApiHeaders(),
    withCredentials: true,
  });
  const getRequestErrorMessage = (error: unknown, fallbackMessage: string) => {
    if (axios.isAxiosError(error)) {
      if (error.response?.status === 419) {
        return 'Sessao expirada. Atualize a pagina e tente novamente.';
      }

      const responseMessage = error.response?.data?.message;
      const validationErrors = Object.values(error.response?.data?.errors || {}).flat().join(' ');

      return responseMessage || validationErrors || fallbackMessage;
    }

    if (error instanceof Error) {
      return error.message;
    }

    return fallbackMessage;
  };
  const persistInvoice = async (payload: {
    user_id: string;
    data_emissao: string;
    data_vencimento: string;
    data_fatura?: string;
    mes?: string | null;
    tipo: Fatura['tipo'];
    valor_total: number;
    estado_pagamento?: Fatura['estado_pagamento'];
    centro_custo_id?: string | null;
    observacoes?: string | null;
    origem_tipo?: Fatura['origem_tipo'] | null;
    origem_id?: string | null;
    oculta?: boolean;
    items: Array<{
      descricao: string;
      quantidade: number;
      valor_unitario: number;
      imposto_percentual?: number;
      total_linha: number;
      produto_id?: string;
      centro_custo_id?: string | null;
    }>;
  }) => {
    const response = await fetch(route('financeiro.store'), {
      method: 'POST',
      headers: getApiHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      let message = 'Erro ao criar fatura';
      try {
        const data = await response.json();
        if (data?.message) message = data.message;
        if (data?.errors) {
          const errors = Object.values(data.errors).flat().join(' ');
          if (errors) message = errors;
        }
      } catch (error) {
        const fallback = await response.text();
        if (fallback) message = fallback;
      }
      throw new Error(message);
    }

    const data = await response.json();
    return data.invoice as Fatura & { items?: FaturaItem[] };
  };
  const persistInvoiceUpdate = async (invoiceId: string, payload: {
    user_id: string;
    data_emissao: string;
    data_vencimento: string;
    data_fatura?: string;
    mes?: string | null;
    tipo: Fatura['tipo'];
    valor_total: number;
    estado_pagamento?: Fatura['estado_pagamento'];
    numero_recibo?: string | null;
    centro_custo_id?: string | null;
    observacoes?: string | null;
    origem_tipo?: Fatura['origem_tipo'] | null;
    origem_id?: string | null;
    oculta?: boolean;
    items: Array<{
      descricao: string;
      quantidade: number;
      valor_unitario: number;
      imposto_percentual?: number;
      total_linha: number;
      produto_id?: string;
      centro_custo_id?: string | null;
    }>;
  }) => {
    const response = await fetch(route('financeiro.update', invoiceId), {
      method: 'PUT',
      headers: getApiHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      let message = 'Erro ao atualizar fatura';
      try {
        const data = await response.json();
        if (data?.message) message = data.message;
        if (data?.errors) {
          const errors = Object.values(data.errors).flat().join(' ');
          if (errors) message = errors;
        }
      } catch (error) {
        const fallback = await response.text();
        if (fallback) message = fallback;
      }
      throw new Error(message);
    }

    const data = await response.json();
    return data.invoice as Fatura & { items?: FaturaItem[] };
  };

  const deleteInvoice = async (invoiceId: string) => {
    await axios.post(route('financeiro.destroy.post', invoiceId), {}, getAxiosJsonConfig());
  };
  const getStartOfToday = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today;
  };
  const isFutureInvoice = (fatura: Fatura) => new Date(fatura.data_fatura) > getStartOfToday();
  const addBusinessDays = (startDate: Date, businessDays: number) => {
    const date = new Date(startDate);
    let added = 0;
    while (added < businessDays) {
      date.setDate(date.getDate() + 1);
      const day = date.getDay();
      if (day !== 0 && day !== 6) {
        added += 1;
      }
    }
    return date;
  };
  const getInscricaoDate = (user: User) => {
    if (!user.data_inscricao) return null;
    const parsed = new Date(user.data_inscricao);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  };
  const getFinalMes = (startDate: Date) => {
    const month = startDate.getMonth();
    const year = startDate.getFullYear();
    const finalYear = month <= 6 ? year : year + 1;
    return new Date(finalYear, 6, 1);
  };
  const [dialogOpen, setDialogOpen] = useState(false);
  const [dialogAutoOpen, setDialogAutoOpen] = useState(false);
  const [dialogReciboOpen, setDialogReciboOpen] = useState(false);
  const [dialogDeleteOpen, setDialogDeleteOpen] = useState(false);
  const [selectedUserId, setSelectedUserId] = useState<string>('');
  const [selectedFaturaId, setSelectedFaturaId] = useState<string | null>(null);
  const [selectedFaturas, setSelectedFaturas] = useState<Set<string>>(new Set());
  const [selectedBankStatementId, setSelectedBankStatementId] = useState<string>('none');
  const [paymentDate, setPaymentDate] = useState<string>(format(new Date(), 'yyyy-MM-dd'));
  const [paymentMethod, setPaymentMethod] = useState<string>('');
  const [paymentReference, setPaymentReference] = useState<string>('');
  const [paymentNotes, setPaymentNotes] = useState<string>('');
  const [paymentAmount, setPaymentAmount] = useState<string>('0.00');
  const [paymentAllocations, setPaymentAllocations] = useState<Record<string, string>>({});
  const [paymentCreateCredit, setPaymentCreateCredit] = useState(false);
  const [paymentSubmitting, setPaymentSubmitting] = useState(false);
  const [gerarParaTodos, setGerarParaTodos] = useState(false);
  const [selectedMonthlyFeeId, setSelectedMonthlyFeeId] = useState<string>('all');
  const [dataInicioMensalidades, setDataInicioMensalidades] = useState('');
  const [dataFimMensalidades, setDataFimMensalidades] = useState('');
  const [editingFaturaId, setEditingFaturaId] = useState<string | null>(null);
  const [statusTransitionInvoiceId, setStatusTransitionInvoiceId] = useState<string | null>(null);
  const [showFutureInvoices, setShowFutureInvoices] = useState(false);
  const getInvoiceTypeLabel = (tipo: string) => {
    const match = (invoiceTypes || []).find((type) => type.codigo === tipo);
    return match ? match.nome : tipo;
  };

  const defaultManualPaymentMethod = useMemo(() => {
    const manualMethod = (paymentMethods || []).find((method) => !method.requer_linha_bancaria);
    return manualMethod?.codigo || paymentMethods?.[0]?.codigo || 'dinheiro';
  }, [paymentMethods]);

  const defaultBankPaymentMethod = useMemo(() => {
    const bankMethod = (paymentMethods || []).find((method) => method.requer_linha_bancaria);
    return bankMethod?.codigo || paymentMethods?.[0]?.codigo || 'transferencia';
  }, [paymentMethods]);

  const reloadFinanceiroData = () => {
    router.reload({
      only: ['dashboardData', 'faturas', 'mensalidadesFaturas', 'faturaItens', 'movimentosFinanceiros', 'extratos', 'lancamentos', 'fiscalRequests', 'conciliacoes'],
      preserveScroll: true,
    });
  };

  const formatAmount = (value: number) => value.toFixed(2);

  const getInvoiceCompetenceLabel = (invoice: Fatura) => {
    if (invoice.tipo !== 'mensalidade') {
      return null;
    }

    const monthSource = invoice.mes ? `${invoice.mes}-01T00:00:00` : invoice.data_emissao;
    const monthDate = new Date(monthSource);

    if (Number.isNaN(monthDate.getTime())) {
      return null;
    }

    return new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(monthDate);
  };

  const getInvoiceOutstandingAmount = (invoice: Fatura) => {
    if (invoice.estado_pagamento === 'pago') {
      return 0;
    }

    if (invoice.valor_em_aberto !== null && invoice.valor_em_aberto !== undefined) {
      return Math.max(toNumber(invoice.valor_em_aberto, 0), 0);
    }

    return Math.max(toNumber(invoice.valor_total, 0), 0);
  };

  const getInvoicePaidAmount = (invoice: Fatura) => Math.max(toNumber(invoice.valor_pago, 0), 0);

  const resetPaymentDialog = () => {
    setDialogReciboOpen(false);
    setSelectedFaturaId(null);
    setSelectedBankStatementId('none');
    setPaymentDate(format(new Date(), 'yyyy-MM-dd'));
    setPaymentMethod(defaultManualPaymentMethod);
    setPaymentReference('');
    setPaymentNotes('');
    setPaymentAmount('0.00');
    setPaymentAllocations({});
    setPaymentCreateCredit(false);
    setPaymentSubmitting(false);
    setStatusTransitionInvoiceId(null);
  };

  const [formData, setFormData] = useState({
    user_id: '',
    tipo: 'mensalidade' as Fatura['tipo'],
    valor_total: 0,
    data_emissao: format(new Date(), 'yyyy-MM-dd'),
    data_vencimento: format(addMonths(new Date(), 0), 'yyyy-MM-dd'),
    estado_pagamento: 'pendente' as Fatura['estado_pagamento'],
    centro_custo_id: '',
    origem_tipo: null as Fatura['origem_tipo'],
    origem_id: '',
    observacoes: '',
  });

  const [linhas, setLinhas] = useState<
    Array<{
      descricao: string;
      valor_unitario: number;
      quantidade: number;
      imposto_percentual: number;
      produto_id?: string;
    }>
  >([{ descricao: '', valor_unitario: 0, quantidade: 1, imposto_percentual: 0 }]);

  const filteredFaturas = useMemo(() => {
    return (faturas || [])
      .map((fatura) => {
        const futureInvoice = isFutureInvoice(fatura);
        const normalized = fatura.oculta && !futureInvoice
          ? { ...fatura, oculta: false }
          : fatura;

        return normalized;
      })
      .filter((fatura) => {
        const futureInvoice = isFutureInvoice(fatura) || !!fatura.oculta;

        if (estadoFilter === 'future') {
          return futureInvoice;
        }

        if (!showFutureInvoices && futureInvoice) {
          return false;
        }

        switch (estadoFilter) {
          case 'due':
            return ['pendente', 'vencido', 'parcial'].includes(fatura.estado_pagamento);
          case 'overdue':
            return fatura.estado_pagamento === 'vencido';
          case 'paid':
            return fatura.estado_pagamento === 'pago';
          case 'partial':
            return fatura.estado_pagamento === 'parcial';
          case 'all':
          default:
            return true;
        }
      });
  }, [faturas, estadoFilter, showFutureInvoices]);

  const paymentInvoices = useMemo(() => {
    const ids = selectedFaturaId ? [selectedFaturaId] : Array.from(selectedFaturas);

    return ids
      .map((invoiceId) => (faturas || []).find((invoice) => invoice.id === invoiceId))
      .filter((invoice): invoice is Fatura => Boolean(invoice));
  }, [faturas, selectedFaturaId, selectedFaturas]);

  const availableBankStatements = useMemo(() => {
    return (extratos || []).filter((statement) => {
      const remaining = statement.valor_por_conciliar !== null && statement.valor_por_conciliar !== undefined
        ? Math.abs(toNumber(statement.valor_por_conciliar, 0))
        : Math.abs(toNumber(statement.valor, 0));

      if (remaining <= 0.009) {
        return false;
      }

      return !statement.conciliado || statement.conciliacao_status === 'partial';
    });
  }, [extratos]);

  const selectedBankStatement = useMemo(() => {
    if (selectedBankStatementId === 'none') {
      return null;
    }

    return availableBankStatements.find((statement) => statement.id === selectedBankStatementId) || null;
  }, [availableBankStatements, selectedBankStatementId]);

  const selectedPaymentMethod = useMemo(() => {
    return (paymentMethods || []).find((method) => method.codigo === paymentMethod) || null;
  }, [paymentMethod, paymentMethods]);

  const paymentRequiresBankStatement = Boolean(selectedPaymentMethod?.requer_linha_bancaria);

  const editingInvoice = useMemo(() => {
    if (!editingFaturaId) {
      return null;
    }

    return (faturas || []).find((invoice) => invoice.id === editingFaturaId) || null;
  }, [editingFaturaId, faturas]);

  const totalOpenAmount = useMemo(() => {
    return paymentInvoices.reduce((sum, invoice) => sum + getInvoiceOutstandingAmount(invoice), 0);
  }, [paymentInvoices]);

  const totalAllocatedAmount = useMemo(() => {
    return paymentInvoices.reduce((sum, invoice) => sum + toNumber(paymentAllocations[invoice.id], 0), 0);
  }, [paymentAllocations, paymentInvoices]);

  const totalAvailableAmount = selectedBankStatement
    ? Math.abs(
        toNumber(
          selectedBankStatement.valor_por_conciliar,
          Math.abs(toNumber(selectedBankStatement.valor, 0)),
        ),
      )
    : toNumber(paymentAmount, 0);

  const paymentDifference = totalAvailableAmount - totalAllocatedAmount;
  const hasValidPaymentAllocations = paymentInvoices.length > 0 && totalAllocatedAmount > 0;
  const paymentValidationMessage = useMemo(() => {
    if (paymentSubmitting) {
      return 'A registar o pagamento. Aguarde.';
    }

    if (!paymentMethod) {
      return 'Selecione um metodo de pagamento configurado.';
    }

    if (paymentRequiresBankStatement && !selectedBankStatement) {
      return 'Este metodo de pagamento exige selecao de uma linha de extrato bancario.';
    }

    if (!hasValidPaymentAllocations) {
      return 'Indique pelo menos uma alocacao valida com valor superior a zero.';
    }

    if (totalAvailableAmount <= 0) {
      return 'Indique um valor de pagamento valido.';
    }

    if (totalAllocatedAmount - totalAvailableAmount > 0.009) {
      return 'As alocacoes excedem o valor disponivel para pagamento.';
    }

    return null;
  }, [
    hasValidPaymentAllocations,
    paymentMethod,
    paymentRequiresBankStatement,
    paymentSubmitting,
    selectedBankStatement,
    totalAllocatedAmount,
    totalAvailableAmount,
  ]);
  const canConfirmPayment = !paymentValidationMessage;

  const handleAbrirDialogoRecibo = (faturaId?: string, fromStatusTransition = false) => {
    const invoiceIds = faturaId ? [faturaId] : Array.from(selectedFaturas);
    const eligibleInvoices = invoiceIds
      .map((invoiceId) => (faturas || []).find((invoice) => invoice.id === invoiceId))
      .filter((invoice): invoice is Fatura => Boolean(invoice))
      .filter((invoice) => !['pago', 'cancelado'].includes(invoice.estado_pagamento));

    if (eligibleInvoices.length === 0) {
      toast.error('Selecione pelo menos uma fatura em aberto para registar pagamento');
      return;
    }

    if (!faturaId && eligibleInvoices.length !== invoiceIds.length) {
      toast.info('As faturas pagas ou canceladas foram ignoradas neste pagamento.');
    }

    const defaultAllocations = Object.fromEntries(
      eligibleInvoices.map((invoice) => [invoice.id, formatAmount(getInvoiceOutstandingAmount(invoice))])
    );

    setSelectedFaturaId(faturaId || null);
    if (faturaId) {
      setSelectedFaturas(new Set());
    }
    setSelectedBankStatementId('none');
    setPaymentDate(format(new Date(), 'yyyy-MM-dd'));
    setPaymentMethod(defaultManualPaymentMethod);
    setPaymentReference('');
    setPaymentNotes('');
    setPaymentAllocations(defaultAllocations);
    setPaymentAmount(formatAmount(eligibleInvoices.reduce((sum, invoice) => sum + getInvoiceOutstandingAmount(invoice), 0)));
    setPaymentCreateCredit(false);
    setStatusTransitionInvoiceId(fromStatusTransition && faturaId ? faturaId : null);
    setDialogReciboOpen(true);
  };

  const handleConfirmarLiquidacao = async () => {
    if (paymentInvoices.length === 0) {
      return;
    }

    const allocations = paymentInvoices
      .map((invoice) => ({
        invoice_id: invoice.id,
        amount: roundToCents(toNumber(paymentAllocations[invoice.id], 0)),
      }))
      .filter((allocation) => allocation.amount > 0);

    if (allocations.length === 0) {
      toast.error('Indique pelo menos uma alocacao com valor superior a zero');
      return;
    }

    if (!paymentMethod) {
      toast.error('Selecione um metodo de pagamento configurado');
      return;
    }

    if (paymentValidationMessage) {
      toast.error(paymentValidationMessage);
      return;
    }

    setPaymentSubmitting(true);

    try {
      if (statusTransitionInvoiceId) {
        const result = await transitionMonthlyInvoiceStatus(statusTransitionInvoiceId, {
          estado_pagamento: 'pago',
          bank_statement_id: selectedBankStatement?.id,
          payment_date: selectedBankStatement ? undefined : paymentDate,
          method: paymentMethod || undefined,
          reference: paymentReference || undefined,
          notes: paymentNotes || undefined,
        });

        setFaturas((current) => (current || []).map((invoice) => (
          invoice.id === statusTransitionInvoiceId ? result.invoice : invoice
        )));
        toast.success(result.message || 'Pagamento registado e pedido fiscal criado.');
      } else {
        const response = await axios.post(route('financeiro.payments.allocate'), {
          bank_statement_id: selectedBankStatement?.id,
          amount: selectedBankStatement ? undefined : totalAvailableAmount,
          payment_date: selectedBankStatement ? undefined : paymentDate,
          method: paymentMethod || undefined,
          reference: paymentReference || undefined,
          notes: paymentNotes || undefined,
          create_credit: paymentCreateCredit,
          allocations,
        }, getAxiosJsonConfig());

        const updatedInvoices = Array.isArray(response.data?.invoices) ? response.data.invoices as Fatura[] : [];
        const updatedStatement = response.data?.bank_statement as ExtratoBancario | undefined;
        const summary = response.data?.summary as {
          all_paid?: boolean;
          has_partial_invoice?: boolean;
          created_credit?: boolean;
          bank_statement_reconciled?: boolean;
        } | undefined;

        if (updatedInvoices.length > 0) {
          const updatedById = new Map(updatedInvoices.map((invoice) => [invoice.id, invoice]));
          setFaturas((current) => (current || []).map((invoice) => updatedById.get(invoice.id) || invoice));
        }

        if (updatedStatement?.id) {
          setExtratos((current) => (current || []).map((statement) => (
            statement.id === updatedStatement.id ? { ...statement, ...updatedStatement } : statement
          )));
        }

        if (summary?.created_credit) {
          toast.success('Pagamento registado e excedente guardado em conta corrente.');
        } else if (summary?.bank_statement_reconciled) {
          toast.success('Pagamento registado e linha bancaria conciliada.');
        } else if (summary?.has_partial_invoice) {
          toast.success('Pagamento parcial registado.');
        } else if (summary?.all_paid) {
          toast.success('Pagamento registado e pedido fiscal criado.');
        } else {
          toast.success('Pagamento registado com sucesso.');
        }
      }

      reloadFinanceiroData();
      resetPaymentDialog();
      setSelectedFaturas(new Set());
    } catch (error) {
      const message = getRequestErrorMessage(error, 'Erro ao registar pagamento');
      toast.error(message);
    } finally {
      setPaymentSubmitting(false);
    }
  };

  const roundToCents = (value: number) => Math.round(value * 100) / 100;

  const updatePaymentAllocation = (invoiceId: string, value: string) => {
    setPaymentAllocations((current) => ({
      ...current,
      [invoiceId]: value,
    }));
  };

  const handleToggleFaturaSelection = (faturaId: string) => {
    setSelectedFaturas((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(faturaId)) {
        newSet.delete(faturaId);
      } else {
        newSet.add(faturaId);
      }
      return newSet;
    });
  };

  const handleToggleAllFaturas = () => {
    if (selectedFaturas.size === filteredFaturas.length) {
      setSelectedFaturas(new Set());
    } else {
      setSelectedFaturas(new Set(filteredFaturas.map((f) => f.id)));
    }
  };

  const handleAbrirDialogoDelete = () => {
    if (selectedFaturas.size === 0) {
      toast.error('Selecione pelo menos uma fatura para apagar');
      return;
    }
    setDialogDeleteOpen(true);
  };

  const handleConfirmarDelete = async () => {
    const faturasParaApagar = Array.from(selectedFaturas);

    try {
      for (const faturaId of faturasParaApagar) {
        await deleteInvoice(faturaId);
      }

      setFaturas((current) => (current || []).filter((f) => !faturasParaApagar.includes(f.id)));
      setFaturaItens((current) => (current || []).filter((item) => !faturasParaApagar.includes(item.fatura_id)));
      setLancamentos((current) => (current || []).filter((l) => !l.fatura_id || !faturasParaApagar.includes(l.fatura_id)));

      toast.success(`${faturasParaApagar.length} fatura(s) apagada(s) com sucesso`);
      reloadFinanceiroData();
      setDialogDeleteOpen(false);
      setSelectedFaturas(new Set());
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao apagar faturas';
      toast.error(message);
    }
  };

  const handleDeleteSingleFatura = async (faturaId: string) => {
    const confirmed = window.confirm('Tem a certeza que deseja apagar esta fatura?');
    if (!confirmed) return;

    try {
      await deleteInvoice(faturaId);
      setFaturas((current) => (current || []).filter((f) => f.id !== faturaId));
      setFaturaItens((current) => (current || []).filter((item) => item.fatura_id !== faturaId));
      setLancamentos((current) => (current || []).filter((l) => l.fatura_id !== faturaId));
      toast.success('Fatura apagada com sucesso');
      reloadFinanceiroData();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao apagar fatura';
      toast.error(message);
    }
  };

  const transitionMonthlyInvoiceStatus = async (invoiceId: string, payload: {
    estado_pagamento: 'pago' | 'pendente' | 'vencido';
    bank_statement_id?: string;
    payment_date?: string;
    method?: string;
    reference?: string;
    notes?: string;
  }) => {
    try {
      const response = await axios.post(route('financeiro.mensalidades.estado', invoiceId), payload, getAxiosJsonConfig());
      const data = response.data;

      return {
        invoice: data.invoice as Fatura,
        message: data.message as string | undefined,
      };
    } catch (error) {
      throw new Error(getRequestErrorMessage(error, 'Erro ao alterar estado da mensalidade'));
    }
  };

  const getDataInicioMensalidades = (user: User) => {
    if (dataInicioMensalidades) {
      const parsed = new Date(dataInicioMensalidades);
      if (!Number.isNaN(parsed.getTime())) {
        return parsed;
      }
    }

    return getInscricaoDate(user);
  };

  const gerarFaturasParaUtilizador = (userId: string) => {
    const user = (users || []).find((u) => u.id === userId);
    if (!user || !user.tipo_mensalidade) {
      return { faturas: [], itens: [], skipped: false };
    }

    const mensalidade = (mensalidades || []).find((m) => m.id === user.tipo_mensalidade);
    if (!mensalidade) {
      return { faturas: [], itens: [], skipped: false };
    }

    const dataInicio = getDataInicioMensalidades(user);
    if (!dataInicio) {
      return { faturas: [], itens: [], skipped: true };
    }

    const mesInicial = startOfMonth(dataInicio);
    const julhoSeguinte = getFinalMes(dataInicio);

    let dataAtual = mesInicial;
    const novasFaturas: Fatura[] = [];
    const novosItens: FaturaItem[] = [];

    const centros = (user.centro_custo_pesos && user.centro_custo_pesos.length > 0)
      ? user.centro_custo_pesos
      : (user.centro_custo || []).map((id) => ({ id, peso: 1 }));

    const splitValor = (total: number, weights: Array<{ id: string; peso: number }>) => {
      if (weights.length === 0) return [{ valor: total } as { id?: string; valor: number }];
      const pesoTotal = weights.reduce((sum, w) => sum + (w.peso || 0), 0) || 1;
      const valores = weights.map((w) => ({
        id: w.id,
        valor: (total * (w.peso || 0)) / pesoTotal,
      }));

      const arredondados = valores.map((v) => ({ ...v, valor: Math.round(v.valor * 100) / 100 }));
      const soma = arredondados.reduce((sum, v) => sum + v.valor, 0);
      const ajuste = Math.round((total - soma) * 100) / 100;
      if (ajuste !== 0) {
        arredondados[arredondados.length - 1].valor += ajuste;
      }
      return arredondados as Array<{ id?: string; valor: number }>;
    };

    const getPrimaryCentroCustoId = (weights: Array<{ id: string; peso: number }>) => {
      if (weights.length === 0) return undefined;
      if (weights.length === 1) return weights[0].id;
      const sorted = [...weights].sort((a, b) => (b.peso || 0) - (a.peso || 0));
      return sorted[0]?.id;
    };

    while (isBefore(dataAtual, julhoSeguinte) || dataAtual.getTime() === julhoSeguinte.getTime()) {
      const mesKey = format(dataAtual, 'yyyy-MM');
      const faturaExistente = (faturas || []).find((f) => f.user_id === userId && f.mes === mesKey);

      if (!faturaExistente) {
        const faturaId = crypto.randomUUID();
        const primeiroDiaMes = startOfMonth(dataAtual);
        const dataVencimento = addBusinessDays(primeiroDiaMes, 8);
        const ocultar = isAfter(primeiroDiaMes, getStartOfToday());
        const centrosValores = splitValor(mensalidade.valor, centros as Array<{ id: string; peso: number }>);
        const primaryCentroCustoId = getPrimaryCentroCustoId(centros as Array<{ id: string; peso: number }>);

        const novaFatura: Fatura = {
          id: faturaId,
          user_id: userId,
          data_fatura: format(primeiroDiaMes, 'yyyy-MM-dd'),
          mes: mesKey,
          data_emissao: format(primeiroDiaMes, 'yyyy-MM-dd'),
          data_vencimento: format(dataVencimento, 'yyyy-MM-dd'),
          valor_total: mensalidade.valor,
          oculta: ocultar,
          estado_pagamento: 'pendente',
          centro_custo_id: primaryCentroCustoId,
          tipo: 'mensalidade',
          created_at: new Date().toISOString(),
        };

        const itensParaCentro = centrosValores.length > 0 ? centrosValores : [{ id: undefined, valor: mensalidade.valor }];
        const novosItensMes = itensParaCentro.map((item) => ({
          id: crypto.randomUUID(),
          fatura_id: faturaId,
          descricao: mensalidade.designacao,
          valor_unitario: item.valor,
          quantidade: 1,
          imposto_percentual: 0,
          total_linha: item.valor,
          centro_custo_id: item.id || undefined,
        }));

        novasFaturas.push(novaFatura);
        novosItens.push(...novosItensMes);
      }

      dataAtual = addMonths(dataAtual, 1);
    }

    return { faturas: novasFaturas, itens: novosItens, skipped: false };
  };

  const handleGerarFaturasMensais = async () => {
    if (!gerarParaTodos && !selectedUserId) {
      toast.error('Selecione um utilizador ou escolha gerar para todos');
      return;
    }

    try {
      const computedEndDate = dataFimMensalidades || (
        dataInicioMensalidades
          ? format(getFinalMes(new Date(dataInicioMensalidades)), 'yyyy-MM-dd')
          : undefined
      );

      const response = await fetch(route('financeiro.monthly-fees.generate'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          generate_for_all: gerarParaTodos,
          user_id: gerarParaTodos ? undefined : selectedUserId,
          start_date: dataInicioMensalidades || undefined,
          end_date: computedEndDate,
          only_active: true,
          monthly_fee_id: selectedMonthlyFeeId !== 'all' ? selectedMonthlyFeeId : undefined,
        }),
      });

      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        const message = payload?.message || Object.values(payload?.errors || {}).flat().join(' ') || 'Erro ao gerar mensalidades';
        throw new Error(message);
      }

      const createdInvoices = Array.isArray(payload?.invoices)
        ? payload.invoices as Array<Fatura & { items?: FaturaItem[] }>
        : [];
      const createdItems = createdInvoices.flatMap((invoice) => invoice.items || []);
      const summary = payload?.summary as {
        created_count?: number;
        skipped_existing_count?: number;
        skipped_without_start?: number;
        skipped_without_plan?: number;
        future_hidden_count?: number;
        activated_count?: number;
        errors?: Array<{ message?: string }>;
      } | undefined;

      if ((summary?.created_count || 0) === 0) {
        toast.info('Nenhuma mensalidade nova foi criada para o periodo indicado.');
      } else {
        setFaturas((current) => {
          const existingIds = new Set((current || []).map((invoice) => invoice.id));
          return [...(current || []), ...createdInvoices.filter((invoice) => !existingIds.has(invoice.id))];
        });

        if (createdItems.length > 0) {
          setFaturaItens((current) => [...(current || []), ...createdItems]);
        }

        toast.success(
          `${summary?.created_count || createdInvoices.length} mensalidade(s) gerada(s)`
          + ((summary?.skipped_existing_count || 0) > 0 ? `, ${summary?.skipped_existing_count} ja existiam` : '')
          + ((summary?.skipped_without_start || 0) > 0 ? `, ${summary?.skipped_without_start} sem data de inicio` : '')
          + ((summary?.skipped_without_plan || 0) > 0 ? `, ${summary?.skipped_without_plan} sem plano` : '')
          + ((summary?.future_hidden_count || 0) > 0 ? `, ${summary?.future_hidden_count} futuras ocultas` : '')
          + ((summary?.activated_count || 0) > 0 ? `, ${summary?.activated_count} ativadas` : '')
          + ((summary?.errors?.length || 0) > 0 ? `, ${summary?.errors?.length} erro(s)` : '')
        );
      }

      reloadFinanceiroData();
      setDialogAutoOpen(false);
      setSelectedUserId('');
      setSelectedMonthlyFeeId('all');
      setGerarParaTodos(false);
      setDataInicioMensalidades('');
      setDataFimMensalidades('');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao gerar mensalidades';
      toast.error(message);
    }
  };

  const handleCriarFaturaManual = async () => {
    if (!formData.user_id) {
      toast.error('Selecione um utilizador');
      return;
    }

    if (linhas.every((l) => !l.descricao || l.valor_unitario <= 0)) {
      toast.error('Adicione pelo menos uma linha valida');
      return;
    }

    if (editingFaturaId) {
      const faturaOriginal = (faturas || []).find((f) => f.id === editingFaturaId);
      const linhasValidas = linhas.filter((l) => l.descricao && l.valor_unitario > 0);
      const total = linhasValidas.reduce(
        (sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100),
        0
      );

      const faturaAtualizada: Fatura = {
        ...faturaOriginal!,
        user_id: formData.user_id,
        data_emissao: formData.data_emissao,
        data_vencimento: formData.data_vencimento,
        valor_total: total,
        estado_pagamento: formData.estado_pagamento,
        centro_custo_id: formData.centro_custo_id || undefined,
        tipo: 'mensalidade',
        origem_tipo: formData.origem_tipo || null,
        origem_id: formData.origem_id || null,
        observacoes: formData.observacoes || undefined,
      };

      const novosItens: FaturaItem[] = linhasValidas.map((linha) => {
        const totalLinha = linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100);

        return {
          id: crypto.randomUUID(),
          fatura_id: editingFaturaId,
          descricao: linha.descricao,
          valor_unitario: linha.valor_unitario,
          quantidade: linha.quantidade,
          imposto_percentual: linha.imposto_percentual,
          total_linha: totalLinha,
          produto_id: linha.produto_id,
          centro_custo_id: formData.centro_custo_id || undefined,
        };
      });

      try {
        const originalStatus = faturaOriginal?.estado_pagamento;
        const isTransitionToPaid = !!faturaOriginal
          && faturaAtualizada.estado_pagamento === 'pago'
          && originalStatus !== 'pago';
        const isReopenTransition = !!faturaOriginal
          && ['pago', 'parcial'].includes(originalStatus || '')
          && ['pendente', 'vencido'].includes(faturaAtualizada.estado_pagamento);

        if (isReopenTransition) {
          const confirmed = window.confirm(
            'Esta operacao vai desfazer pagamento, conciliacao bancaria e pedido fiscal associado, se ainda nao existir documento Wintouch emitido.'
          );

          if (!confirmed) {
            return;
          }
        }

        const administrativeStatus = (isTransitionToPaid || isReopenTransition)
          ? (originalStatus as Fatura['estado_pagamento'])
          : faturaAtualizada.estado_pagamento;

        const updated = await persistInvoiceUpdate(editingFaturaId, {
          user_id: faturaAtualizada.user_id,
          data_emissao: faturaAtualizada.data_emissao,
          data_vencimento: faturaAtualizada.data_vencimento,
          data_fatura: faturaAtualizada.data_fatura,
          mes: faturaAtualizada.mes || null,
          tipo: faturaAtualizada.tipo,
          valor_total: faturaAtualizada.valor_total,
          estado_pagamento: administrativeStatus,
          numero_recibo: faturaAtualizada.numero_recibo || null,
          centro_custo_id: faturaAtualizada.centro_custo_id || undefined,
          observacoes: faturaAtualizada.observacoes || undefined,
          origem_tipo: faturaAtualizada.origem_tipo || null,
          origem_id: faturaAtualizada.origem_id || null,
          oculta: faturaAtualizada.oculta || false,
          items: novosItens.map((item) => ({
            descricao: item.descricao,
            quantidade: item.quantidade,
            valor_unitario: item.valor_unitario,
            imposto_percentual: item.imposto_percentual,
            total_linha: item.total_linha,
            produto_id: item.produto_id || undefined,
            centro_custo_id: item.centro_custo_id || undefined,
          })),
        });

        setFaturas((current) => (current || []).map((f) => (f.id === editingFaturaId ? updated : f)));
        setFaturaItens((current) => {
          const filtered = (current || []).filter((item) => item.fatura_id !== editingFaturaId);
          return [...filtered, ...(updated.items || [])];
        });

        if (isTransitionToPaid) {
          toast.info('Confirme agora a liquidacao da mensalidade pelo fluxo canonico.');
          setDialogOpen(false);
          setEditingFaturaId(null);
          handleAbrirDialogoRecibo(editingFaturaId, true);
          return;
        }

        if (isReopenTransition) {
          const result = await transitionMonthlyInvoiceStatus(editingFaturaId, {
            estado_pagamento: faturaAtualizada.estado_pagamento as 'pendente' | 'vencido',
            notes: formData.observacoes || undefined,
          });

          setFaturas((current) => (current || []).map((f) => (f.id === editingFaturaId ? result.invoice : f)));
          toast.success(result.message || 'Mensalidade reaberta com sucesso.');
          reloadFinanceiroData();
          setDialogOpen(false);
          setEditingFaturaId(null);
          resetForm();

          return;
        }
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao atualizar fatura';
        toast.error(message);
        return;
      }

      setLancamentos((current) => {
        const existing = (current || []).find((l) => l.fatura_id === editingFaturaId);
        const novoLancamento: LancamentoFinanceiro = {
          id: existing?.id || crypto.randomUUID(),
          data: formData.data_emissao,
          tipo: 'receita' as const,
          categoria: 'Fatura manual',
          descricao: `Fatura manual ${formData.tipo} - ${getUserName(formData.user_id)}`,
          valor: total,
          centro_custo_id: formData.centro_custo_id || undefined,
          user_id: formData.user_id,
          fatura_id: editingFaturaId,
          origem_tipo: formData.origem_tipo || 'manual',
          origem_id: formData.origem_id || undefined,
          metodo_pagamento: existing?.metodo_pagamento || 'manual',
          created_at: existing?.created_at || new Date().toISOString(),
        };

        if (existing) {
          return (current || []).map((l) => (l.id === existing.id ? novoLancamento : l));
        }
        return [...(current || []), novoLancamento];
      });
      toast.success('Fatura atualizada com sucesso');
      reloadFinanceiroData();
      setEditingFaturaId(null);
    } else {
      const faturaId = crypto.randomUUID();
      const linhasValidas = linhas.filter((l) => l.descricao && l.valor_unitario > 0);
      const total = linhasValidas.reduce(
        (sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100),
        0
      );

      const novaFatura: Fatura = {
        id: faturaId,
        user_id: formData.user_id,
        data_fatura: formData.data_emissao,
        data_emissao: formData.data_emissao,
        data_vencimento: formData.data_vencimento,
        valor_total: total,
        estado_pagamento: formData.estado_pagamento === 'pago' ? 'pendente' : formData.estado_pagamento,
        centro_custo_id: formData.centro_custo_id || undefined,
        tipo: 'mensalidade',
        origem_tipo: formData.origem_tipo || null,
        origem_id: formData.origem_id || null,
        observacoes: formData.observacoes || undefined,
        created_at: new Date().toISOString(),
      };

      const novosItens: FaturaItem[] = linhasValidas.map((linha) => {
        const totalLinha = linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100);

        if (linha.produto_id) {
          const product = (products || []).find((p) => p.id === linha.produto_id);
          if (product) {
            const novoStock = product.stock - linha.quantidade;
            const updatedProducts = (products || []).map((p) =>
              p.id === linha.produto_id ? { ...p, stock: novoStock } : p
            );
            setProducts(updatedProducts);
          }
        }

        return {
          id: crypto.randomUUID(),
          fatura_id: faturaId,
          descricao: linha.descricao,
          valor_unitario: linha.valor_unitario,
          quantidade: linha.quantidade,
          imposto_percentual: linha.imposto_percentual,
          total_linha: totalLinha,
          produto_id: linha.produto_id,
          centro_custo_id: formData.centro_custo_id || undefined,
        };
      });

      try {
        const created = await persistInvoice({
          user_id: novaFatura.user_id,
          data_emissao: novaFatura.data_emissao,
          data_vencimento: novaFatura.data_vencimento,
          data_fatura: novaFatura.data_fatura,
          mes: novaFatura.mes || null,
          tipo: novaFatura.tipo,
          valor_total: novaFatura.valor_total,
          estado_pagamento: novaFatura.estado_pagamento,
          centro_custo_id: novaFatura.centro_custo_id || undefined,
          observacoes: novaFatura.observacoes || undefined,
          origem_tipo: novaFatura.origem_tipo || null,
          origem_id: novaFatura.origem_id || null,
          oculta: novaFatura.oculta || false,
          items: novosItens.map((item) => ({
            descricao: item.descricao,
            quantidade: item.quantidade,
            valor_unitario: item.valor_unitario,
            imposto_percentual: item.imposto_percentual,
            total_linha: item.total_linha,
            produto_id: item.produto_id || undefined,
            centro_custo_id: item.centro_custo_id || undefined,
          })),
        });

        setFaturas((current) => [...(current || []), created]);
        if (created.items) {
          setFaturaItens((current) => [...(current || []), ...created.items!]);
        }
        setLancamentos((current) => [
          ...(current || []),
          {
            id: crypto.randomUUID(),
            data: formData.data_emissao,
            tipo: 'receita' as const,
            categoria: 'Fatura manual',
            descricao: `Fatura manual ${formData.tipo} - ${getUserName(formData.user_id)}`,
            valor: total,
            centro_custo_id: formData.centro_custo_id || undefined,
            user_id: formData.user_id,
            fatura_id: created.id,
            origem_tipo: formData.origem_tipo || 'manual',
            origem_id: formData.origem_id || undefined,
            metodo_pagamento: 'manual',
            created_at: new Date().toISOString(),
          },
        ]);

        toast.success('Fatura criada com sucesso');
        reloadFinanceiroData();
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao gravar fatura na base de dados';
        toast.error(message);
        return;
      }
    }

    setDialogOpen(false);
    resetForm();
  };

  const resetForm = () => {
    setFormData({
      user_id: '',
      tipo: 'mensalidade',
      valor_total: 0,
      data_emissao: format(new Date(), 'yyyy-MM-dd'),
      data_vencimento: format(addMonths(new Date(), 0), 'yyyy-MM-dd'),
      estado_pagamento: 'pendente',
      centro_custo_id: '',
      origem_tipo: null,
      origem_id: '',
      observacoes: '',
    });
    setLinhas([{ descricao: '', valor_unitario: 0, quantidade: 1, imposto_percentual: 0 }]);
    setEditingFaturaId(null);
  };

  const handleEditarFatura = (faturaId: string) => {
    const fatura = (faturas || []).find((f) => f.id === faturaId);
    if (!fatura) return;

    const itens = (faturaItens || []).filter((item) => item.fatura_id === faturaId);

    const toInputDate = (value?: string | null) => {
      if (!value) return '';
      const parsed = new Date(value);
      if (Number.isNaN(parsed.getTime())) return '';
      return format(parsed, 'yyyy-MM-dd');
    };

    setFormData({
      user_id: fatura.user_id,
      tipo: fatura.tipo,
      valor_total: fatura.valor_total,
      data_emissao: toInputDate(fatura.data_emissao),
      data_vencimento: toInputDate(fatura.data_vencimento),
      estado_pagamento: fatura.estado_pagamento,
      centro_custo_id: fatura.centro_custo_id || '',
      origem_tipo: fatura.origem_tipo || null,
      origem_id: fatura.origem_id || '',
      observacoes: fatura.observacoes || '',
    });

    if (itens.length > 0) {
      setLinhas(
        itens.map((item) => ({
          descricao: item.descricao,
          valor_unitario: item.valor_unitario,
          quantidade: item.quantidade,
          imposto_percentual: item.imposto_percentual,
          produto_id: item.produto_id || undefined,
        }))
      );
    }

    setEditingFaturaId(faturaId);
    setDialogOpen(true);
  };

  const addLinha = () => {
    setLinhas([...linhas, { descricao: '', valor_unitario: 0, quantidade: 1, imposto_percentual: 0 }]);
  };

  const removeLinha = (index: number) => {
    setLinhas(linhas.filter((_, i) => i !== index));
  };

  const updateLinha = (index: number, field: string, value: any) => {
    const newLinhas = [...linhas];
    newLinhas[index] = { ...newLinhas[index], [field]: value };
    setLinhas(newLinhas);
  };

  const getUserName = (userId: string) => {
    const user = (users || []).find((u) => u.id === userId);
    return user ? user.nome_completo : 'Utilizador desconhecido';
  };

  const getCentroCustoName = (id?: string) => {
    if (!id) return '-';
    const cc = (centrosCusto || []).find((c) => c.id === id);
    return cc ? cc.nome : '-';
  };

  const getEstadoBadge = (estado: Fatura['estado_pagamento'], isFuture = false) => {
    if (isFuture) {
      return <Badge className="bg-slate-100 text-slate-700">FUTURA</Badge>;
    }

    const variants = {
      pendente: 'bg-yellow-100 text-yellow-800',
      pago: 'bg-green-100 text-green-800',
      vencido: 'bg-red-100 text-red-800',
      parcial: 'bg-blue-100 text-blue-800',
      cancelado: 'bg-gray-100 text-gray-800',
    };
    return <Badge className={variants[estado]}>{estado.toUpperCase()}</Badge>;
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col gap-3 sm:gap-4 md:flex-row md:items-center md:justify-between">
        <div className="flex gap-2 items-center w-full sm:w-auto">
          <Select value={estadoFilter} onValueChange={setEstadoFilter}>
            <SelectTrigger className="w-full sm:w-[200px]">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todas</SelectItem>
              <SelectItem value="due">Mensalidades devidas</SelectItem>
              <SelectItem value="overdue">Mensalidades em atraso</SelectItem>
              <SelectItem value="paid">Mensalidades pagas</SelectItem>
              <SelectItem value="partial">Mensalidades parciais</SelectItem>
              <SelectItem value="future">Mensalidades futuras</SelectItem>
            </SelectContent>
          </Select>
          <div className="flex items-center gap-2">
            <Checkbox
              id="mostrar-futuras"
              checked={showFutureInvoices}
              onCheckedChange={(checked) => setShowFutureInvoices(checked === true)}
            />
            <Label htmlFor="mostrar-futuras" className="text-xs">
              Mostrar faturas futuras
            </Label>
          </div>
          <div className="flex gap-1 border rounded p-1 bg-muted">
            <Button
              variant={viewMode === 'card' ? 'default' : 'ghost'}
              size="sm"
              className="h-7 w-7 p-0"
              onClick={() => setViewMode('card')}
              title="Visualização em cards"
            >
              <SquaresFour size={16} />
            </Button>
            <Button
              variant={viewMode === 'table' ? 'default' : 'ghost'}
              size="sm"
              className="h-7 w-7 p-0"
              onClick={() => setViewMode('table')}
              title="Visualização em lista"
            >
              <ListBullets size={16} />
            </Button>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
          {selectedFaturas.size > 0 && (
            <>
              <Button
                variant="outline"
                onClick={() => handleAbrirDialogoRecibo()}
                className="w-full sm:w-auto text-xs sm:text-sm"
                size="sm"
              >
                <Check className="mr-1 sm:mr-2" size={16} />
                Pagamento ({selectedFaturas.size})
              </Button>
              <Button
                variant="destructive"
                onClick={handleAbrirDialogoDelete}
                className="w-full sm:w-auto text-xs sm:text-sm"
                size="sm"
              >
                <Trash className="mr-1 sm:mr-2" size={16} />
                Apagar ({selectedFaturas.size})
              </Button>
            </>
          )}
          <Dialog open={dialogAutoOpen} onOpenChange={setDialogAutoOpen}>
            <DialogTrigger asChild>
              <Button type="button" variant="outline" className="w-full sm:w-auto text-xs sm:text-sm" size="sm" title="Gerar mensalidades pelo ciclo financeiro configurado">
                <MagicWand className="mr-1 sm:mr-2" size={16} />
                <span>Gerar</span>
              </Button>
            </DialogTrigger>
            <DialogContent className="w-[95vw] sm:w-full max-w-md">
              <DialogHeader>
                <DialogTitle className="text-base sm:text-lg">Gerar mensalidades pelo ciclo financeiro configurado</DialogTitle>
                <DialogDescription>
                  As mensalidades sao geradas com base na configuracao financeira do clube e no plano de cada utilizador. A epoca desportiva pode servir de referencia, mas nao condiciona a geracao.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="gerar-todos"
                    checked={gerarParaTodos}
                    onCheckedChange={(checked) => {
                      setGerarParaTodos(checked === true);
                      if (checked) {
                        setSelectedUserId('');
                      }
                    }}
                  />
                  <Label htmlFor="gerar-todos" className="cursor-pointer text-sm">
                    Gerar para todos os utilizadores com mensalidade
                  </Label>
                </div>

                {!gerarParaTodos && (
                  <div className="space-y-2">
                    <Label className="text-sm">Selecionar Utilizador</Label>
                    <Select value={selectedUserId} onValueChange={setSelectedUserId}>
                      <SelectTrigger>
                        <SelectValue placeholder="Escolher utilizador" />
                      </SelectTrigger>
                      <SelectContent className="max-h-72">
                        {(users || []).length === 0 ? (
                          <div className="px-2 py-6 text-center text-sm text-muted-foreground">
                            Nenhum utilizador disponivel
                          </div>
                        ) : (
                          (users || []).map((user) => {
                            const temMensalidade = !!user.tipo_mensalidade;
                            const mensalidade = temMensalidade
                              ? (mensalidades || []).find((m) => m.id === user.tipo_mensalidade)
                              : null;

                            return (
                              <SelectItem key={user.id} value={user.id} disabled={!temMensalidade}>
                                <div className="flex items-center justify-between w-full">
                                  <span className="text-sm">
                                    {user.nome_completo} - {user.numero_socio}
                                  </span>
                                  {temMensalidade && mensalidade && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                      (€{mensalidade.valor})
                                    </span>
                                  )}
                                  {!temMensalidade && (
                                    <span className="ml-2 text-xs text-muted-foreground">(sem mensalidade)</span>
                                  )}
                                </div>
                              </SelectItem>
                            );
                          })
                        )}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                <div className="space-y-2">
                  <Label className="text-sm">Plano de mensalidade</Label>
                  <Select value={selectedMonthlyFeeId} onValueChange={setSelectedMonthlyFeeId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Todos os planos" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos os planos</SelectItem>
                      {(mensalidades || []).map((mensalidade) => (
                        <SelectItem key={mensalidade.id} value={mensalidade.id}>
                          {mensalidade.designacao} (€{mensalidade.valor})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    Opcional. Limita a geracao aos utilizadores com o plano selecionado.
                  </p>
                </div>

                <div className="space-y-2">
                  <Label className="text-sm">Data de Inicio</Label>
                  <Input
                    type="date"
                    value={dataInicioMensalidades}
                    onChange={(e) => setDataInicioMensalidades(e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Se ficar vazio, o sistema usa o ciclo financeiro configurado e respeita a data de inscricao/inicio financeiro quando aplicavel.
                  </p>
                </div>

                <div className="space-y-2">
                  <Label className="text-sm">Data de Fim</Label>
                  <Input
                    type="date"
                    value={dataFimMensalidades}
                    onChange={(e) => setDataFimMensalidades(e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    Se ficar vazio, o sistema usa o fim do ciclo financeiro configurado para o clube.
                  </p>
                </div>

                <p className="text-xs text-muted-foreground">
                  {gerarParaTodos
                    ? 'Serao geradas mensalidades para todos os utilizadores elegiveis, sem duplicar periodos ja existentes, mantendo futuras ocultas ate ao vencimento.'
                    : 'Serao geradas mensalidades para o utilizador escolhido, sem duplicar periodos ja existentes, mantendo futuras ocultas ate ao vencimento.'}
                </p>
              </div>
              <DialogFooter className="flex-col sm:flex-row gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => {
                    setDialogAutoOpen(false);
                    setGerarParaTodos(false);
                    setSelectedUserId('');
                    setSelectedMonthlyFeeId('all');
                    setDataInicioMensalidades('');
                    setDataFimMensalidades('');
                  }}
                  className="w-full sm:w-auto"
                >
                  Cancelar
                </Button>
                <Button type="button" onClick={handleGerarFaturasMensais} className="w-full sm:w-auto">
                  Gerar Faturas
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            <DialogTrigger asChild>
              <Button onClick={resetForm} className="w-full sm:w-auto text-xs sm:text-sm" size="sm">
                <Plus className="mr-1 sm:mr-2" size={16} />
                <span className="hidden sm:inline">Mensalidade Manual</span>
                <span className="sm:hidden">Manual</span>
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto w-[95vw] sm:w-full">
              <DialogHeader>
                <DialogTitle className="text-base sm:text-lg">
                  {editingFaturaId ? 'Editar Mensalidade' : 'Criar Mensalidade Manual'}
                </DialogTitle>
                <DialogDescription>
                  {editingFaturaId ? 'Altere apenas os dados administrativos da mensalidade. A liquidacao continua a ser feita no modal de pagamento.' : 'Registe manualmente uma mensalidade sem misturar outros tipos de invoice nesta tab.'}
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label className="text-sm">Utilizador *</Label>
                    <Select value={formData.user_id} onValueChange={(v) => setFormData({ ...formData, user_id: v })}>
                      <SelectTrigger>
                        <SelectValue placeholder="Selecionar" />
                      </SelectTrigger>
                      <SelectContent>
                        {(users || []).map((user) => (
                          <SelectItem key={user.id} value={user.id}>
                            {user.nome_completo} - {user.numero_socio}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Tipo</Label>
                    <div className="flex h-10 items-center rounded-md border border-input bg-muted px-3 text-sm text-foreground">
                      Mensalidade
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Data Emissao</Label>
                    <Input
                      type="date"
                      value={formData.data_emissao}
                      onChange={(e) => setFormData({ ...formData, data_emissao: e.target.value })}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Data Vencimento</Label>
                    <Input
                      type="date"
                      value={formData.data_vencimento}
                      onChange={(e) => setFormData({ ...formData, data_vencimento: e.target.value })}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Estado de pagamento</Label>
                    <Select
                      value={formData.estado_pagamento}
                      onValueChange={(v) => setFormData({ ...formData, estado_pagamento: v as Fatura['estado_pagamento'] })}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="pendente">Pendente</SelectItem>
                        <SelectItem value="vencido">Vencido</SelectItem>
                        {editingFaturaId && <SelectItem value="pago">Pago</SelectItem>}
                        {editingFaturaId && <SelectItem value="parcial" disabled>Parcial (apenas via fluxo canonico)</SelectItem>}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                      Alteracoes para `pago` abrem o fluxo canonico de liquidacao. Reabrir para `pendente` ou `vencido` desfaz pagamento, conciliacao e pedido fiscal ainda nao emitido.
                    </p>
                  </div>

                  <div className="space-y-2 sm:col-span-2">
                    <Label className="text-sm">Centro de Custo</Label>
                    <Select
                      value={formData.centro_custo_id}
                      onValueChange={(v) => setFormData({ ...formData, centro_custo_id: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Opcional" />
                      </SelectTrigger>
                      <SelectContent>
                        {(centrosCusto || [])
                          .filter((cc) => cc.ativo)
                          .map((cc) => (
                            <SelectItem key={cc.id} value={cc.id}>
                              {cc.nome}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Origem</Label>
                    <div className="flex h-10 items-center rounded-md border border-input bg-muted px-3 text-sm text-muted-foreground">
                      Mantida automaticamente pela mensalidade. Esta tab nao liquida nem reclassifica invoices por edicao.
                    </div>
                  </div>
                </div>

                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label className="text-sm">Linhas da Fatura</Label>
                    <Button type="button" size="sm" variant="outline" onClick={addLinha} className="text-xs h-8">
                      <Plus size={14} className="mr-1" />
                      <span className="hidden sm:inline">Adicionar Linha</span>
                      <span className="sm:hidden">Adicionar</span>
                    </Button>
                  </div>

                  <div className="space-y-2">
                    {linhas.map((linha, index) => (
                      <Card key={index} className="p-3 sm:p-4">
                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-2">
                          <div className="sm:col-span-4 space-y-2">
                            <Label className="text-xs">Descricao</Label>
                            <Input
                              placeholder="Item"
                              value={linha.descricao}
                              onChange={(e) => updateLinha(index, 'descricao', e.target.value)}
                              className="text-sm"
                            />
                          </div>
                          <div className="sm:col-span-2 space-y-2">
                            <Label className="text-xs">Valor Unit.</Label>
                            <Input
                              type="number"
                              step="0.01"
                              min="0"
                              value={linha.valor_unitario}
                              onChange={(e) => updateLinha(index, 'valor_unitario', parseFloat(e.target.value) || 0)}
                              className="text-sm"
                            />
                          </div>
                          <div className="sm:col-span-2 space-y-2">
                            <Label className="text-xs">Qtd.</Label>
                            <Input
                              type="number"
                              min="1"
                              value={linha.quantidade}
                              onChange={(e) => updateLinha(index, 'quantidade', parseInt(e.target.value) || 1)}
                              className="text-sm"
                            />
                          </div>
                          <div className="sm:col-span-2 space-y-2">
                            <Label className="text-xs">IVA %</Label>
                            <Input
                              type="number"
                              min="0"
                              value={linha.imposto_percentual}
                              onChange={(e) => updateLinha(index, 'imposto_percentual', parseFloat(e.target.value) || 0)}
                              className="text-sm"
                            />
                          </div>
                          <div className="sm:col-span-1 space-y-2">
                            <Label className="text-xs">Total</Label>
                            <div className="text-sm font-medium pt-2">
                              €{(
                                linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100)
                              ).toFixed(2)}
                            </div>
                          </div>
                          <div className="sm:col-span-1 flex items-end sm:justify-end">
                            {linhas.length > 1 && (
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => removeLinha(index)}
                                className="text-xs h-8"
                              >
                                <X size={14} />
                              </Button>
                            )}
                          </div>
                        </div>
                      </Card>
                    ))}
                  </div>
                </div>

                <div className="space-y-2">
                  <Label className="text-sm">Observacoes</Label>
                  <Textarea
                    placeholder="Notas adicionais"
                    value={formData.observacoes}
                    onChange={(e) => setFormData({ ...formData, observacoes: e.target.value })}
                    rows={2}
                    className="text-sm"
                  />
                </div>

                <div className="flex items-center justify-between p-3 sm:p-4 bg-muted rounded-lg">
                  <span className="font-semibold text-sm sm:text-base">Total da Fatura:</span>
                  <span className="text-xl sm:text-2xl font-bold text-primary">
                    €{linhas
                      .reduce(
                        (sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100),
                        0
                      )
                      .toFixed(2)}
                  </span>
                </div>
              </div>
              <DialogFooter className="flex-col sm:flex-row gap-2">
                <Button variant="outline" onClick={() => { setDialogOpen(false); resetForm(); }} className="w-full sm:w-auto">
                  Cancelar
                </Button>
                <Button onClick={handleCriarFaturaManual} className="w-full sm:w-auto">
                  {editingFaturaId ? 'Guardar Alteracoes' : 'Criar Fatura'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      <Card>
        {viewMode === 'card' ? (
          <div className="grid gap-2.5 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 p-4">
            {filteredFaturas.length === 0 ? (
              <div className="col-span-full text-center text-muted-foreground py-8">Nenhuma fatura encontrada</div>
            ) : (
              filteredFaturas
                .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime())
                .map((fatura) => {
                  const userName = getUserName(fatura.user_id);
                  const paidAmount = getInvoicePaidAmount(fatura);
                  const outstandingAmount = getInvoiceOutstandingAmount(fatura);
                  const competenceLabel = getInvoiceCompetenceLabel(fatura);
                  return (
                    <Card key={fatura.id} className="p-3 cursor-pointer transition-all hover:shadow-lg hover:border-primary/50">
                      <div className="flex items-start gap-2">
                        <Checkbox checked={selectedFaturas.has(fatura.id)} onCheckedChange={() => handleToggleFaturaSelection(fatura.id)} />
                        <div className="flex-1 min-w-0">
                          <h3 className="font-semibold text-[12px] truncate">{userName}</h3>
                          <p className="text-[10px] text-muted-foreground">{getInvoiceTypeLabel(fatura.tipo)}</p>
                          {competenceLabel && (
                            <p className="text-[10px] text-muted-foreground">Competencia: {competenceLabel}</p>
                          )}
                          <div className="text-sm font-semibold text-primary mt-1">€{toNumber(fatura.valor_total).toFixed(2)}</div>
                          <div className="mt-2 grid grid-cols-2 gap-2 text-[10px] text-muted-foreground">
                            <div>
                              <div>Total</div>
                              <div className="font-medium text-foreground">€{toNumber(fatura.valor_total).toFixed(2)}</div>
                            </div>
                            <div>
                              <div>Pago</div>
                              <div className="font-medium text-foreground">€{paidAmount.toFixed(2)}</div>
                            </div>
                            <div>
                              <div>Em aberto</div>
                              <div className="font-medium text-foreground">€{outstandingAmount.toFixed(2)}</div>
                            </div>
                            <div>
                              <div>Estado</div>
                              <div className="mt-1">{getEstadoBadge(fatura.estado_pagamento)}</div>
                            </div>
                          </div>
                          <div className="flex gap-1 mt-1">
                            {getEstadoBadge(fatura.estado_pagamento)}
                          </div>
                          <div className="text-[10px] text-muted-foreground mt-1">
                            Vence: {format(new Date(fatura.data_vencimento), 'dd/MM/yyyy')}
                          </div>
                        </div>
                        <div className="flex flex-col gap-1">
                          {!['pago', 'cancelado'].includes(fatura.estado_pagamento) && (
                            <Button
                              size="sm"
                              variant="ghost"
                              className="h-7 w-7 p-0"
                              onClick={() => handleAbrirDialogoRecibo(fatura.id)}
                              title="Registar pagamento"
                            >
                              <Check size={14} />
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 w-7 p-0"
                            onClick={() => handleEditarFatura(fatura.id)}
                            title="Editar"
                          >
                            <PencilSimple size={14} />
                          </Button>
                          <button
                            type="button"
                            className="inline-flex h-7 w-7 items-center justify-center rounded-md hover:bg-accent"
                            onClick={() => handleDeleteSingleFatura(fatura.id)}
                            title="Apagar"
                          >
                            <Trash size={14} />
                          </button>
                        </div>
                      </div>
                    </Card>
                  );
                })
            )}
          </div>
        ) : (
          <div className="p-0 overflow-hidden">
            <div className="w-full overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-12">
                      <Checkbox
                        checked={selectedFaturas.size === filteredFaturas.length && filteredFaturas.length > 0}
                        onCheckedChange={handleToggleAllFaturas}
                      />
                    </TableHead>
                    <TableHead className="hidden sm:table-cell flex-1 min-w-[150px]">Utilizador</TableHead>
                    <TableHead className="hidden md:table-cell w-24">Tipo</TableHead>
                    <TableHead className="hidden lg:table-cell w-28">Data Emissao</TableHead>
                    <TableHead className="flex-1 min-w-[120px]">Vencimento</TableHead>
                    <TableHead className="hidden sm:table-cell w-24 text-right">Valor</TableHead>
                    <TableHead className="hidden lg:table-cell w-24 text-right">Pago</TableHead>
                    <TableHead className="hidden lg:table-cell w-28 text-right">Em Aberto</TableHead>
                    <TableHead className="hidden md:table-cell w-20">Estado</TableHead>
                    <TableHead className="w-48 text-right">Acoes</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredFaturas.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={10} className="text-center text-muted-foreground py-8">
                        Nenhuma fatura encontrada
                      </TableCell>
                    </TableRow>
                  ) : (
                    filteredFaturas
                      .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime())
                      .map((fatura) => {
                        const paidAmount = getInvoicePaidAmount(fatura);
                        const outstandingAmount = getInvoiceOutstandingAmount(fatura);
                        const competenceLabel = getInvoiceCompetenceLabel(fatura);

                        return (
                        <TableRow key={fatura.id}>
                          <TableCell>
                            <Checkbox
                              checked={selectedFaturas.has(fatura.id)}
                              onCheckedChange={() => handleToggleFaturaSelection(fatura.id)}
                            />
                          </TableCell>
                          <TableCell className="hidden sm:table-cell font-medium text-xs max-w-[150px] truncate">{getUserName(fatura.user_id)}</TableCell>
                          <TableCell className="hidden md:table-cell text-xs">
                            <div className="space-y-1">
                              <Badge variant="outline">{getInvoiceTypeLabel(fatura.tipo)}</Badge>
                              {competenceLabel && (
                                <div className="text-[10px] text-muted-foreground">Comp. {competenceLabel}</div>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="hidden lg:table-cell text-xs">
                            <div>{format(new Date(fatura.data_emissao), 'dd/MM/yyyy')}</div>
                            {competenceLabel && (
                              <div className="text-[10px] text-muted-foreground">{competenceLabel}</div>
                            )}
                          </TableCell>
                          <TableCell className="text-xs">{format(new Date(fatura.data_vencimento), 'dd/MM/yyyy')}</TableCell>
                          <TableCell className="hidden sm:table-cell font-semibold text-xs text-right">€{toNumber(fatura.valor_total).toFixed(2)}</TableCell>
                          <TableCell className="hidden lg:table-cell text-xs text-right">€{paidAmount.toFixed(2)}</TableCell>
                          <TableCell className="hidden lg:table-cell text-xs text-right">€{outstandingAmount.toFixed(2)}</TableCell>
                          <TableCell className="hidden md:table-cell text-xs">{getEstadoBadge(fatura.estado_pagamento)}</TableCell>
                          <TableCell>
                            <div className="flex items-center justify-end gap-1">
                              <Button size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={() => handleEditarFatura(fatura.id)} title="Editar">
                                <PencilSimple size={14} />
                              </Button>
                              {!['pago', 'cancelado'].includes(fatura.estado_pagamento) && (
                                <Button size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={() => handleAbrirDialogoRecibo(fatura.id)} title="Registar pagamento">
                                  <Check size={14} />
                                </Button>
                              )}
                              <button
                                type="button"
                                className="inline-flex h-7 w-7 items-center justify-center rounded-md hover:bg-accent"
                                onClick={() => handleDeleteSingleFatura(fatura.id)}
                                title="Apagar"
                              >
                                <Trash size={14} />
                              </button>
                            </div>
                          </TableCell>
                        </TableRow>
                      )})
                  )}
                </TableBody>
              </Table>
            </div>
          </div>
        )}
      </Card>

      <Dialog open={dialogReciboOpen} onOpenChange={setDialogReciboOpen}>
        <DialogContent className="w-[95vw] sm:w-full max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="text-base sm:text-lg">
              {selectedFaturaId ? 'Registar Pagamento' : `Registar Pagamento de ${paymentInvoices.length} Fatura(s)`}
            </DialogTitle>
            <DialogDescription>
              Registe o pagamento desta fatura. Pode associar uma linha do extrato bancario e repartir o valor por uma ou varias faturas. O numero do recibo/documento fiscal sera preenchido apenas na Emissao Fiscal, apos emissao na Wintouch.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label className="text-sm">Linha do Extrato Bancario</Label>
                <Select
                  value={selectedBankStatementId}
                  onValueChange={(value) => {
                    setSelectedBankStatementId(value);

                    if (value === 'none') {
                      setPaymentDate(format(new Date(), 'yyyy-MM-dd'));
                      return;
                    }

                    const statement = availableBankStatements.find((item) => item.id === value);
                    if (!statement) {
                      return;
                    }

                    setPaymentDate(statement.data_movimento);
                    setPaymentMethod(defaultBankPaymentMethod);
                    setPaymentReference(statement.referencia || '');
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Sem associar linha bancaria" />
                  </SelectTrigger>
                  <SelectContent className="max-h-72 overflow-y-auto">
                    <SelectItem value="none">Sem associar linha bancaria</SelectItem>
                    {availableBankStatements.map((statement) => {
                      const remaining = statement.valor_por_conciliar !== null && statement.valor_por_conciliar !== undefined
                        ? Math.abs(toNumber(statement.valor_por_conciliar, 0))
                        : Math.abs(toNumber(statement.valor, 0));

                      return (
                        <SelectItem key={statement.id} value={statement.id}>
                          {format(new Date(statement.data_movimento), 'dd/MM/yyyy')} · {statement.referencia || statement.descricao} · €{remaining.toFixed(2)}
                        </SelectItem>
                      );
                    })}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label className="text-sm">Valor a pagar/alocar</Label>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  value={selectedBankStatement ? totalAvailableAmount.toFixed(2) : paymentAmount}
                  onChange={(event) => setPaymentAmount(event.target.value)}
                  disabled={Boolean(selectedBankStatement)}
                />
                <p className="text-xs text-muted-foreground">
                  {selectedBankStatement
                    ? 'Quando existe linha bancaria, o valor vem do montante por conciliar dessa linha.'
                    : paymentRequiresBankStatement
                      ? 'Este metodo exige linha bancaria. Selecione uma linha do extrato para definir o valor.'
                      : 'Use este valor para pagamentos manuais totais ou parciais.'}
                </p>
              </div>

              <div className="space-y-2">
                <Label className="text-sm">Data do pagamento</Label>
                <Input
                  type="date"
                  value={paymentDate}
                  onChange={(event) => setPaymentDate(event.target.value)}
                  disabled={Boolean(selectedBankStatement)}
                />
              </div>

              <div className="space-y-2">
                <Label className="text-sm">Metodo</Label>
                <Select value={paymentMethod} onValueChange={(value) => {
                  const nextMethod = (paymentMethods || []).find((method) => method.codigo === value) || null;

                  setPaymentMethod(value);

                  if (!nextMethod?.requer_linha_bancaria && selectedBankStatementId !== 'none') {
                    setSelectedBankStatementId('none');
                    setPaymentDate(format(new Date(), 'yyyy-MM-dd'));
                  }
                }}>
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar metodo de pagamento" />
                  </SelectTrigger>
                  <SelectContent>
                    {(paymentMethods || []).map((method) => (
                      <SelectItem key={method.id} value={method.codigo}>
                        {method.nome}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  {selectedPaymentMethod
                    ? selectedPaymentMethod.requer_linha_bancaria
                      ? 'Este metodo so pode ser usado com uma linha de extrato bancario selecionada.'
                      : 'Este metodo permite liquidacao manual sem linha bancaria.'
                    : 'Selecione um metodo ativo definido em Configuracoes > Financeiro.'}
                </p>
              </div>

              <div className="space-y-2 md:col-span-2">
                <Label className="text-sm">Referencia</Label>
                <Input value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} placeholder="Referencia do pagamento ou da transferencia" />
              </div>

              <div className="space-y-2 md:col-span-2">
                <Label className="text-sm">Notas</Label>
                <Textarea
                  rows={3}
                  value={paymentNotes}
                  onChange={(event) => setPaymentNotes(event.target.value)}
                  placeholder="Observacoes internas do pagamento"
                />
              </div>
            </div>

            <Card className="p-4">
              <div className="space-y-3">
                <div>
                  <h3 className="text-sm font-semibold">Faturas a liquidar</h3>
                  <p className="text-xs text-muted-foreground">Pode repartir o valor por uma ou varias faturas e ajustar os montantes para pagamento parcial.</p>
                </div>

                <div className="space-y-3">
                  {paymentInvoices.map((invoice) => {
                    const outstanding = getInvoiceOutstandingAmount(invoice);

                    return (
                      <div key={invoice.id} className="grid gap-3 rounded-lg border p-3 md:grid-cols-[minmax(0,1fr)_140px] md:items-end">
                        <div className="min-w-0">
                          <div className="text-sm font-medium text-foreground">{getUserName(invoice.user_id)} · {getInvoiceTypeLabel(invoice.tipo)}</div>
                          <div className="mt-1 text-xs text-muted-foreground">
                            Em aberto: €{outstanding.toFixed(2)} · Vencimento: {format(new Date(invoice.data_vencimento), 'dd/MM/yyyy')}
                          </div>
                        </div>
                        <div className="space-y-1">
                          <Label className="text-xs">Valor alocado</Label>
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            max={outstanding.toFixed(2)}
                            value={paymentAllocations[invoice.id] ?? ''}
                            onChange={(event) => updatePaymentAllocation(invoice.id, event.target.value)}
                          />
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </Card>

            <Card className="p-4">
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total das faturas</div>
                  <div className="mt-1 text-lg font-semibold">€{totalOpenAmount.toFixed(2)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total a pagar/alocar</div>
                  <div className="mt-1 text-lg font-semibold">€{totalAvailableAmount.toFixed(2)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Total alocado</div>
                  <div className="mt-1 text-lg font-semibold">€{totalAllocatedAmount.toFixed(2)}</div>
                </div>
                <div>
                  <div className="text-xs uppercase tracking-wide text-muted-foreground">Diferenca</div>
                  <div className={`mt-1 text-lg font-semibold ${paymentDifference < -0.009 ? 'text-rose-600' : paymentDifference > 0.009 ? 'text-amber-600' : 'text-emerald-600'}`}>
                    €{Math.abs(paymentDifference).toFixed(2)}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {paymentDifference < -0.009
                      ? 'Falta pagar'
                      : paymentDifference > 0.009
                        ? 'Excedente'
                        : 'Sem diferenca'}
                  </div>
                </div>
              </div>

              {selectedBankStatement ? (
                <div className="mt-3 rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground">
                  Linha bancaria selecionada: {selectedBankStatement.descricao} · {selectedBankStatement.referencia || 'sem referencia'} · saldo por conciliar €{totalAvailableAmount.toFixed(2)}
                </div>
              ) : null}

              {paymentRequiresBankStatement && !selectedBankStatement ? (
                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  Este metodo de pagamento exige selecao de uma linha de extrato bancario.
                </div>
              ) : null}

              {paymentValidationMessage && !(paymentRequiresBankStatement && !selectedBankStatement) ? (
                <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                  {paymentValidationMessage}
                </div>
              ) : null}

              {paymentDifference > 0.009 ? (
                <div className="mt-4 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3">
                  <Checkbox
                    id="guardar-credito"
                    checked={paymentCreateCredit}
                    onCheckedChange={(checked) => setPaymentCreateCredit(checked === true)}
                  />
                  <div>
                    <Label htmlFor="guardar-credito" className="text-sm font-medium">
                      Guardar excedente como credito em conta corrente
                    </Label>
                    <p className="mt-1 text-xs text-muted-foreground">
                      O valor excedente fica disponivel para abater em futuras faturas.
                    </p>
                  </div>
                </div>
              ) : null}
            </Card>
          </div>
          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button
              variant="outline"
              onClick={resetPaymentDialog}
              className="w-full sm:w-auto"
            >
              Cancelar
            </Button>
            <Button onClick={handleConfirmarLiquidacao} className="w-full sm:w-auto" disabled={!canConfirmPayment}>
              <Check className="mr-2" size={16} />
              Confirmar Pagamento
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialogDeleteOpen} onOpenChange={setDialogDeleteOpen}>
        <DialogContent className="w-[95vw] sm:w-full max-w-md">
          <DialogHeader>
            <DialogTitle className="text-base sm:text-lg">Confirmar Eliminacao</DialogTitle>
            <DialogDescription>
              Esta acao e irreversivel. As faturas serao permanentemente removidas.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Tem a certeza que deseja apagar {selectedFaturas.size} fatura(s)? Esta acao nao pode ser revertida.
            </p>
          </div>
          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button variant="outline" onClick={() => setDialogDeleteOpen(false)} className="w-full sm:w-auto">
              Cancelar
            </Button>
            <Button variant="destructive" onClick={handleConfirmarDelete} className="w-full sm:w-auto">
              <Trash className="mr-2" size={16} />
              Confirmar Eliminacao
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
