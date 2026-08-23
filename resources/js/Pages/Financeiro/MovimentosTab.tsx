import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Movimento, MovimentoFinanceiro, MovimentoItem, User, Supplier, CentroCusto, Product, LancamentoFinanceiro, PaymentMethod, ExtratoBancario } from './types';
import { fetchFinanceiro } from './request';
import { useClubSettings } from '@/hooks/useClubSettings';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { MovementConciliationStatusBadge, MovementDocumentStatusBadge, MovementPaymentStatusBadge } from '@/Components/Financeiro/MovementStatusBadges';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogTrigger, DialogFooter } from '@/Components/ui/dialog';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, X, Check, Trash, PencilSimple, Files } from '@phosphor-icons/react';
import { format, addMonths, isBefore } from 'date-fns';
import { toast } from 'sonner';

interface MovimentosTabProps {
  movimentos: Movimento[];
  movimentosFinanceiros: MovimentoFinanceiro[];
  setMovimentos: React.Dispatch<React.SetStateAction<Movimento[]>>;
  movimentoItens: MovimentoItem[];
  setMovimentoItens: React.Dispatch<React.SetStateAction<MovimentoItem[]>>;
  lancamentos: LancamentoFinanceiro[];
  setLancamentos: React.Dispatch<React.SetStateAction<LancamentoFinanceiro[]>>;
  users: User[];
  suppliers: Supplier[];
  centrosCusto: CentroCusto[];
  products: Product[];
  setProducts: React.Dispatch<React.SetStateAction<Product[]>>;
}

export function MovimentosTab({
  movimentos,
  movimentosFinanceiros,
  setMovimentos,
  movimentoItens,
  setMovimentoItens,
  lancamentos,
  setLancamentos,
  users,
  suppliers,
  centrosCusto,
  products,
  setProducts,
}: MovimentosTabProps) {
  const { props } = usePage<{ paymentMethods?: PaymentMethod[]; extratos?: ExtratoBancario[] }>();
  const { defaultFinancialEntityName } = useClubSettings();
  const allMovimentos = movimentos || [];
  const displayedMovimentos = movimentosFinanceiros || [];
  const paymentMethods = props.paymentMethods || [];
  const extratos = props.extratos || [];
  const toNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const parsed = Number(value);
      return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
  };

  const toDateInputValue = (value?: string | null): string => {
    if (!value) return '';

    const yyyyMmDdMatch = value.match(/^(\d{4}-\d{2}-\d{2})/);
    if (yyyyMmDdMatch?.[1]) {
      return yyyyMmDdMatch[1];
    }

    const parsedDate = new Date(value);
    if (Number.isNaN(parsedDate.getTime())) {
      return '';
    }

    return format(parsedDate, 'yyyy-MM-dd');
  };
  const refreshMovimentos = () => {
    router.reload({
      only: ['movimentos', 'movimentosFinanceiros', 'movimentoItens', 'lancamentos', 'extratos', 'fiscalRequests'],
    });
  };
  const scrollableSelectContentClassName = 'max-h-72 overflow-y-auto';
  const buildMovimentoFormData = (payload: Record<string, unknown>, documentoOriginal?: File | null) => {
    const form = new FormData();

    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null) return;
      if (key === 'items') {
        form.append('items', JSON.stringify(value));
        return;
      }
      form.append(key, String(value));
    });

    if (documentoOriginal) {
      form.append('documento_original', documentoOriginal);
    }

    return form;
  };
  const sendMovimento = async (url: string, method: 'POST' | 'PUT', payload: Record<string, unknown>, documentoOriginal?: File | null) => {
    const body = documentoOriginal ? buildMovimentoFormData(payload, documentoOriginal) : payload;

    return fetchFinanceiro<{ movimento: Movimento; items: MovimentoItem[] }>(url, {
      method,
      body,
      fallbackMessage: 'Erro ao gravar movimento',
    });
  };

  const liquidarMovimento = async (
    movimentoId: string,
    metodoPagamentoLocal: string,
    bankStatementId?: string | null,
    comprovativo?: File | null,
  ) => {
    const body = comprovativo
      ? (() => {
      const form = new FormData();
      form.append('metodo_pagamento', metodoPagamentoLocal);
      if (bankStatementId) {
        form.append('bank_statement_id', bankStatementId);
      }
      form.append('comprovativo', comprovativo);
      return form;
    })()
      : {
        metodo_pagamento: metodoPagamentoLocal,
        ...(bankStatementId ? { bank_statement_id: bankStatementId } : {}),
      };

    return fetchFinanceiro<{ movimento: Movimento; lancamento?: LancamentoFinanceiro }>(route('financeiro.movimentos.liquidar', movimentoId), {
      method: 'POST',
      body,
      fallbackMessage: 'Erro ao liquidar movimento',
    });
  };

  const reopenMovimento = async (movimentoId: string, estadoPagamento: 'pendente' | 'vencido') => {
    return fetchFinanceiro<{ movimento: Movimento; lancamento?: LancamentoFinanceiro }>(route('financeiro.movimentos.reabrir', movimentoId), {
      method: 'POST',
      body: {
        estado_pagamento: estadoPagamento,
      },
      fallbackMessage: 'Erro ao reabrir movimento',
    });
  };

  const [estadoFilter, setEstadoFilter] = useState<string>('all');
  const [classificacaoFilter, setClassificacaoFilter] = useState<string>('all');
  const [documentalFilter, setDocumentalFilter] = useState<string>('all');
  const [conciliacaoFilter, setConciliacaoFilter] = useState<string>('all');
  const [dialogOpen, setDialogOpen] = useState(false);
  const [dialogReciboOpen, setDialogReciboOpen] = useState(false);
  const [dialogDeleteOpen, setDialogDeleteOpen] = useState(false);
  const [selectedMovimentoId, setSelectedMovimentoId] = useState<string | null>(null);
  const [selectedMovimentos, setSelectedMovimentos] = useState<Set<string>>(new Set());
  const [metodoPagamento, setMetodoPagamento] = useState<string>('transferencia');
  const [selectedBankStatementId, setSelectedBankStatementId] = useState<string>('none');
  const [comprovativoFile, setComprovativoFile] = useState<File | null>(null);
  const [reopeningMovimentoId, setReopeningMovimentoId] = useState<string | null>(null);
  const [editingMovimentoId, setEditingMovimentoId] = useState<string | null>(null);
  const [usarDadosUtilizador, setUsarDadosUtilizador] = useState(false);
  const [usarDadosFornecedor, setUsarDadosFornecedor] = useState(false);
  const [documentoOriginalFile, setDocumentoOriginalFile] = useState<File | null>(null);

  const [formData, setFormData] = useState({
    user_id: '',
    supplier_id: '',
    nome_manual: '',
    nif_manual: '',
    morada_manual: '',
    classificacao: 'receita' as 'receita' | 'despesa',
    categoria: '',
    estado_pagamento: 'por_pagar' as Movimento['estado_pagamento'],
    tipo: 'outro' as Movimento['tipo'],
    valor_total: 0,
    data_emissao: format(new Date(), 'yyyy-MM-dd'),
    data_vencimento: format(addMonths(new Date(), 0), 'yyyy-MM-dd'),
    centro_custo_id: '',
    origem_tipo: null as Movimento['origem_tipo'],
    origem_id: '',
    observacoes: '',
  });

  type LinhaMovimento = {
    descricao: string;
    valor_unitario: number;
    quantidade: number;
    imposto_percentual: number;
    produto_id?: string;
    fatura_id?: string;
    tipo_fatura?: 'mensalidade' | 'movimento';
    atleta_id?: string;
  };

  const stripAtletaMarker = (descricao: string) => descricao.replace(/^\[ATLETA:[^\]]+\]\s*/i, '');

  const extractAtletaId = (descricao: string): string | undefined => {
    const markerMatch = descricao.match(/^\[ATLETA:([^\]]+)\]/i);
    if (markerMatch?.[1]) {
      return markerMatch[1];
    }

    const cleanDescricao = stripAtletaMarker(descricao).toLowerCase();
    const matchedUser = (users || []).find((user) =>
      cleanDescricao.startsWith((user.nome_completo || '').toLowerCase())
    );

    return matchedUser?.id;
  };

  const normalizeDescricaoWithAtleta = (linha: LinhaMovimento) => {
    const cleanDescricao = stripAtletaMarker(linha.descricao || '').trim();

    if (!linha.atleta_id) {
      return cleanDescricao;
    }

    const atleta = (users || []).find((user) => user.id === linha.atleta_id);
    const nomeAtleta = atleta?.nome_completo || 'Atleta';

    if (cleanDescricao.length === 0) {
      return nomeAtleta;
    }

    if (cleanDescricao.toLowerCase().startsWith(nomeAtleta.toLowerCase())) {
      return cleanDescricao;
    }

    return `${nomeAtleta} — ${cleanDescricao}`;
  };

  const [linhas, setLinhas] = useState<LinhaMovimento[]>([
    { descricao: '', valor_unitario: 0, quantidade: 1, imposto_percentual: 0 },
  ]);

  const activePaymentMethods = useMemo(() => {
    return [...paymentMethods]
      .filter((method) => method.ativo)
      .sort((left, right) => left.ordem - right.ordem);
  }, [paymentMethods]);

  const defaultManualPaymentMethod = useMemo(() => {
    const manualMethod = activePaymentMethods.find((method) => !method.requer_linha_bancaria);

    return manualMethod?.codigo || activePaymentMethods[0]?.codigo || 'dinheiro';
  }, [activePaymentMethods]);

  const defaultBankPaymentMethod = useMemo(() => {
    const bankMethod = activePaymentMethods.find((method) => method.requer_linha_bancaria);

    return bankMethod?.codigo || activePaymentMethods[0]?.codigo || 'transferencia';
  }, [activePaymentMethods]);

  const defaultPaymentMethod = defaultManualPaymentMethod || defaultBankPaymentMethod || '';

  const availableBankStatements = useMemo(() => {
    return extratos.filter((statement) => {
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
    return activePaymentMethods.find((method) => method.codigo === metodoPagamento) || null;
  }, [activePaymentMethods, metodoPagamento]);

  const paymentRequiresBankStatement = Boolean(selectedPaymentMethod?.requer_linha_bancaria);
  const hasAvailableBankStatements = availableBankStatements.length > 0;
  const liquidacaoValidationMessage = useMemo(() => {
    if (!metodoPagamento) {
      return 'Selecione um metodo de pagamento ativo.';
    }

    if (!selectedPaymentMethod) {
      return 'Selecione um metodo de pagamento ativo.';
    }

    if (paymentRequiresBankStatement && !hasAvailableBankStatements) {
      return 'Nao existem linhas bancarias disponiveis para conciliar.';
    }

    if (paymentRequiresBankStatement && !selectedBankStatement) {
      return 'Este metodo de pagamento exige selecao de uma linha de extrato bancario.';
    }

    return null;
  }, [hasAvailableBankStatements, metodoPagamento, paymentRequiresBankStatement, selectedBankStatement, selectedPaymentMethod]);
  const canConfirmLiquidacao = !liquidacaoValidationMessage;

  useEffect(() => {
    if (selectedPaymentMethod || !defaultPaymentMethod) {
      return;
    }

    setMetodoPagamento(defaultPaymentMethod);
  }, [defaultPaymentMethod, selectedPaymentMethod]);

  useEffect(() => {
    if (!paymentRequiresBankStatement && selectedBankStatementId !== 'none') {
      setSelectedBankStatementId('none');
    }
  }, [paymentRequiresBankStatement, selectedBankStatementId]);

  const filteredMovimentos = useMemo(() => {
    return displayedMovimentos
      .filter((movimento) => {
        const estadoMatch =
          estadoFilter === 'all' ||
          (movimento.estado_pagamento_exibicao || movimento.estado_pagamento) === estadoFilter;

        const documentalMatch =
          documentalFilter === 'all' ||
          movimento.estado_documental === documentalFilter;

        const conciliacaoMatch =
          conciliacaoFilter === 'all' ||
          movimento.estado_conciliacao === conciliacaoFilter;

        const classificacaoMatch = classificacaoFilter === 'all' || movimento.classificacao === classificacaoFilter;

        return estadoMatch && classificacaoMatch && documentalMatch && conciliacaoMatch;
      });
  }, [displayedMovimentos, estadoFilter, classificacaoFilter, documentalFilter, conciliacaoFilter]);

  const sortedMovimentos = useMemo(() => {
    return [...filteredMovimentos].sort(
      (a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime()
    );
  }, [filteredMovimentos]);

  const getActionableMovimentoId = (movimento: MovimentoFinanceiro) => movimento.movimento_id || null;

  const editingMovimento = useMemo(() => {
    if (!editingMovimentoId) {
      return null;
    }

    return (movimentos || []).find((movimento) => movimento.id === editingMovimentoId) || null;
  }, [editingMovimentoId, movimentos]);

  const editingMovimentoCanReopen = Boolean(
    editingMovimento && ['pago', 'parcial', 'pago_parcial'].includes(editingMovimento.estado_pagamento)
  );

  const selectableMovimentoIds = useMemo(
    () => filteredMovimentos
      .map((movimento) => getActionableMovimentoId(movimento))
      .filter((id): id is string => Boolean(id)),
    [filteredMovimentos]
  );

  const getPaidAmount = (movimento: MovimentoFinanceiro) => {
    if (movimento.valor_pago === null || movimento.valor_pago === undefined) {
      return null;
    }

    return Math.max(toNumber(movimento.valor_pago, 0), 0);
  };

  const getOpenAmount = (movimento: MovimentoFinanceiro) => {
    if (movimento.valor_em_aberto === null || movimento.valor_em_aberto === undefined) {
      return null;
    }

    return Math.max(toNumber(movimento.valor_em_aberto, 0), 0);
  };

  const formatAmount = (value?: number | null) => (value === null || value === undefined ? '-' : `€${value.toFixed(2)}`);

  const handleAbrirDialogoRecibo = (movimentoId?: string, _reciboAtual?: string | null, metodoAtual?: string | null) => {
    if (movimentoId) {
      setSelectedMovimentoId(movimentoId);
      setSelectedMovimentos(new Set());
    } else {
      setSelectedMovimentoId(null);
    }
    const nextMethod = activePaymentMethods.find((method) => method.codigo === metodoAtual)?.codigo
      || defaultPaymentMethod
      || defaultManualPaymentMethod
      || defaultBankPaymentMethod;

    setSelectedBankStatementId('none');
    setMetodoPagamento(nextMethod);
    setComprovativoFile(null);
    setDialogReciboOpen(true);
  };

  const handleConfirmarLiquidacao = async () => {
    const movimentosParaLiquidar = selectedMovimentoId ? [selectedMovimentoId] : Array.from(selectedMovimentos);

    if (movimentosParaLiquidar.length === 0) return;

    if (liquidacaoValidationMessage) {
      toast.error(liquidacaoValidationMessage);
      return;
    }

    try {
      const updatedMovimentos: Movimento[] = [];
      const novosLancamentos: LancamentoFinanceiro[] = [];

      for (const movimentoId of movimentosParaLiquidar) {
        const result = await liquidarMovimento(
          movimentoId,
          metodoPagamento,
          paymentRequiresBankStatement ? selectedBankStatement?.id ?? null : null,
          comprovativoFile,
        );
        updatedMovimentos.push(result.movimento);
        if (result.lancamento) {
          novosLancamentos.push(result.lancamento);
        }
      }

      setMovimentos((current) =>
        (current || []).map((m) => updatedMovimentos.find((u) => u.id === m.id) || m)
      );

      if (novosLancamentos.length > 0) {
        setLancamentos((current) => [...(current || []), ...novosLancamentos]);
      }

      toast.success(`${movimentosParaLiquidar.length} movimento(s) liquidado(s) com sucesso`);
      refreshMovimentos();
      setDialogReciboOpen(false);
      setSelectedMovimentoId(null);
      setSelectedMovimentos(new Set());
      setSelectedBankStatementId('none');
      setComprovativoFile(null);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao liquidar movimento';
      toast.error(message);
    }
  };

  const handleReabrirMovimento = async (movimentoId: string, targetStatus: 'pendente' | 'vencido') => {
    setReopeningMovimentoId(movimentoId);

    try {
      const result = await reopenMovimento(movimentoId, targetStatus);

      setMovimentos((current) =>
        (current || []).map((movimento) => (movimento.id === movimentoId ? result.movimento : movimento))
      );

      toast.success('Movimento reaberto com sucesso.');
      refreshMovimentos();

      if (editingMovimentoId === movimentoId) {
        setDialogOpen(false);
        resetForm();
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao reabrir movimento';
      toast.error(message);
    } finally {
      setReopeningMovimentoId(null);
    }
  };

  const handleToggleMovimentoSelection = (movimentoId: string) => {
    setSelectedMovimentos((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(movimentoId)) {
        newSet.delete(movimentoId);
      } else {
        newSet.add(movimentoId);
      }
      return newSet;
    });
  };

  const handleToggleAllMovimentos = () => {
    if (selectedMovimentos.size === selectableMovimentoIds.length) {
      setSelectedMovimentos(new Set());
    } else {
      setSelectedMovimentos(new Set(selectableMovimentoIds));
    }
  };

  const handleAbrirDialogoDelete = () => {
    if (selectedMovimentos.size === 0) {
      toast.error('Selecione pelo menos um movimento para apagar');
      return;
    }
    setDialogDeleteOpen(true);
  };

  const handleConfirmarDelete = async () => {
    const movimentosParaApagar = Array.from(selectedMovimentos);
    try {
      for (const movimentoId of movimentosParaApagar) {
        await fetchFinanceiro<{ success: boolean }>(route('financeiro.movimentos.destroy', movimentoId), {
          method: 'DELETE',
          fallbackMessage: 'Erro ao apagar movimentos',
        });
      }

      setMovimentos((current) => (current || []).filter((m) => !movimentosParaApagar.includes(m.id)));
      setMovimentoItens((current) =>
        (current || []).filter((item) => !movimentosParaApagar.includes(item.movimento_id))
      );

      toast.success(`${movimentosParaApagar.length} movimento(s) apagado(s) com sucesso`);
      refreshMovimentos();
      setDialogDeleteOpen(false);
      setSelectedMovimentos(new Set());
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao apagar movimentos';
      toast.error(message);
    }
  };

  const handleDeleteSingleMovimento = async (movimentoId: string) => {
    try {
      await fetchFinanceiro<{ success: boolean }>(route('financeiro.movimentos.destroy', movimentoId), {
        method: 'DELETE',
        fallbackMessage: 'Erro ao apagar movimento',
      });
      setMovimentos((current) => (current || []).filter((m) => m.id !== movimentoId));
      setMovimentoItens((current) => (current || []).filter((item) => item.movimento_id !== movimentoId));
      toast.success('Movimento apagado com sucesso');
      refreshMovimentos();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao apagar movimento';
      toast.error(message);
    }
  };

  const handleCriarMovimento = async () => {
    if (usarDadosUtilizador && !formData.user_id) {
      toast.error('Selecione um utilizador');
      return;
    }

    if (usarDadosFornecedor && !formData.supplier_id) {
      toast.error('Selecione um fornecedor');
      return;
    }

    if (!formData.centro_custo_id) {
      toast.error('Selecione um centro de custo');
      return;
    }

    if (linhas.every((l) => !normalizeDescricaoWithAtleta(l) || l.valor_unitario <= 0)) {
      toast.error('Adicione pelo menos uma linha valida');
      return;
    }

    if (editingMovimentoId) {
      const linhasValidas = linhas.filter((l) => normalizeDescricaoWithAtleta(l) && l.valor_unitario > 0);
      const totalAbsoluto = linhasValidas.reduce(
        (sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100),
        0
      );
      const total = formData.classificacao === 'despesa' ? -Math.abs(totalAbsoluto) : Math.abs(totalAbsoluto);
      const payload = {
        user_id: usarDadosUtilizador ? formData.user_id : null,
        supplier_id: usarDadosFornecedor ? formData.supplier_id : null,
        nome_manual: usarDadosUtilizador ? undefined : formData.nome_manual,
        nif_manual: usarDadosUtilizador ? undefined : formData.nif_manual,
        morada_manual: usarDadosUtilizador ? undefined : formData.morada_manual,
        classificacao: formData.classificacao,
        categoria: formData.categoria || undefined,
        data_emissao: formData.data_emissao,
        data_vencimento: formData.data_vencimento,
        valor_total: total,
        estado_pagamento: formData.estado_pagamento,
        centro_custo_id: formData.centro_custo_id,
        tipo: formData.tipo,
        origem_tipo: formData.origem_tipo || null,
        origem_id: formData.origem_id || null,
        observacoes: formData.observacoes || undefined,
        items: linhasValidas.map((linha) => ({
          descricao: normalizeDescricaoWithAtleta(linha),
          quantidade: linha.quantidade,
          valor_unitario: linha.valor_unitario,
          imposto_percentual: linha.imposto_percentual,
          total_linha: linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100),
          produto_id: linha.produto_id || undefined,
          centro_custo_id: formData.centro_custo_id,
          fatura_id: linha.fatura_id || undefined,
        })),
      };

      try {
        const result = await sendMovimento(route('financeiro.movimentos.update', editingMovimentoId), 'PUT', payload, documentoOriginalFile);
        setMovimentos((current) =>
          (current || []).map((m) => (m.id === editingMovimentoId ? result.movimento : m))
        );
        setMovimentoItens((current) => {
          const filtered = (current || []).filter((item) => item.movimento_id !== editingMovimentoId);
          return [...filtered, ...result.items];
        });
        toast.success('Movimento atualizado com sucesso');
        refreshMovimentos();
        setEditingMovimentoId(null);
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao atualizar movimento';
        toast.error(message);
        return;
      }
    } else {
      const linhasValidas = linhas.filter((l) => normalizeDescricaoWithAtleta(l) && l.valor_unitario > 0);
      const totalAbsoluto = linhasValidas.reduce(
        (sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100),
        0
      );
      const total = formData.classificacao === 'despesa' ? -Math.abs(totalAbsoluto) : Math.abs(totalAbsoluto);
      const payload = {
        user_id: usarDadosUtilizador ? formData.user_id : null,
        supplier_id: usarDadosFornecedor ? formData.supplier_id : null,
        nome_manual: usarDadosUtilizador ? undefined : formData.nome_manual,
        nif_manual: usarDadosUtilizador ? undefined : formData.nif_manual,
        morada_manual: usarDadosUtilizador ? undefined : formData.morada_manual,
        classificacao: formData.classificacao,
        categoria: formData.categoria || undefined,
        data_emissao: formData.data_emissao,
        data_vencimento: formData.data_vencimento,
        valor_total: total,
        estado_pagamento: formData.classificacao === 'despesa' ? formData.estado_pagamento : 'pendente',
        centro_custo_id: formData.centro_custo_id,
        tipo: formData.tipo,
        origem_tipo: formData.origem_tipo || null,
        origem_id: formData.origem_id || null,
        observacoes: formData.observacoes || undefined,
        items: linhasValidas.map((linha) => ({
          descricao: normalizeDescricaoWithAtleta(linha),
          quantidade: linha.quantidade,
          valor_unitario: linha.valor_unitario,
          imposto_percentual: linha.imposto_percentual,
          total_linha: linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100),
          produto_id: linha.produto_id || undefined,
          centro_custo_id: formData.centro_custo_id,
          fatura_id: linha.fatura_id || undefined,
        })),
      };

      try {
        const result = await sendMovimento(route('financeiro.movimentos.store'), 'POST', payload, documentoOriginalFile);
        setMovimentos((current) => [...(current || []), result.movimento]);
        setMovimentoItens((current) => [...(current || []), ...result.items]);
        toast.success('Movimento criado com sucesso');
        refreshMovimentos();
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Erro ao criar movimento';
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
      supplier_id: '',
      nome_manual: '',
      nif_manual: '',
      morada_manual: '',
      classificacao: 'receita',
      categoria: '',
      estado_pagamento: 'por_pagar',
      tipo: 'outro',
      valor_total: 0,
      data_emissao: format(new Date(), 'yyyy-MM-dd'),
      data_vencimento: format(addMonths(new Date(), 0), 'yyyy-MM-dd'),
      centro_custo_id: '',
      origem_tipo: null,
      origem_id: '',
      observacoes: '',
    });
    setLinhas([{ descricao: '', valor_unitario: 0, quantidade: 1, imposto_percentual: 0 }]);
    setEditingMovimentoId(null);
    setUsarDadosUtilizador(false);
    setUsarDadosFornecedor(false);
    setDocumentoOriginalFile(null);
  };

  const handleEditarMovimento = (movimentoId: string) => {
    const movimento = (movimentos || []).find((m) => m.id === movimentoId);
    if (!movimento) return;

    const itens = (movimentoItens || []).filter((item) => item.movimento_id === movimentoId);

    const utilizaUtilizador = !!movimento.user_id;
    const utilizaFornecedor = !!movimento.supplier_id;
    setUsarDadosUtilizador(utilizaUtilizador);
    setUsarDadosFornecedor(utilizaFornecedor);

    setFormData({
      user_id: movimento.user_id || '',
      supplier_id: movimento.supplier_id || '',
      nome_manual: movimento.nome_manual || '',
      nif_manual: movimento.nif_manual || '',
      morada_manual: movimento.morada_manual || '',
      classificacao: movimento.classificacao,
      categoria: movimento.categoria || '',
      estado_pagamento: movimento.estado_pagamento,
      tipo: movimento.tipo,
      valor_total: movimento.valor_total,
      data_emissao: toDateInputValue(movimento.data_emissao),
      data_vencimento: toDateInputValue(movimento.data_vencimento),
      centro_custo_id: movimento.centro_custo_id || '',
      origem_tipo: movimento.origem_tipo || null,
      origem_id: movimento.origem_id || '',
      observacoes: movimento.observacoes || '',
    });

    if (itens.length > 0) {
      setLinhas(
        itens.map((item) => ({
          descricao: stripAtletaMarker(item.descricao),
          valor_unitario: item.valor_unitario,
          quantidade: item.quantidade,
          imposto_percentual: item.imposto_percentual,
          produto_id: item.produto_id || undefined,
          fatura_id: item.fatura_id || undefined,
          tipo_fatura: item.fatura_id && (allMovimentos || []).some((movimento) => movimento.id === item.fatura_id)
            ? 'movimento'
            : item.fatura_id
              ? 'mensalidade'
              : undefined,
          atleta_id: extractAtletaId(item.descricao),
        }))
      );
    }

    setEditingMovimentoId(movimentoId);
    setDialogOpen(true);
  };

  const handleUserChange = (userId: string) => {
    setFormData({ ...formData, user_id: userId });

    if (userId && usarDadosUtilizador) {
      const user = (users || []).find((u) => u.id === userId);
      if (user) {
        setFormData((prev) => ({
          ...prev,
          user_id: userId,
          nome_manual: user.nome_completo,
          nif_manual: user.nif || '',
          morada_manual: user.morada || '',
        }));
      }
    }
  };

  const handleSupplierChange = (supplierId: string) => {
    setFormData({ ...formData, supplier_id: supplierId });

    if (supplierId && usarDadosFornecedor) {
      const supplier = (suppliers || []).find((item) => item.id === supplierId);
      if (supplier) {
        setFormData((prev) => ({
          ...prev,
          supplier_id: supplierId,
          nome_manual: supplier.nome,
          nif_manual: supplier.nif || '',
          morada_manual: supplier.morada || '',
        }));
      }
    }
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

  const getNomeDisplay = (movimento: Movimento) => {
    if (movimento.user_name) {
      return movimento.user_name;
    }
    if (movimento.user_id) {
      const user = (users || []).find((u) => u.id === movimento.user_id);
      return user ? user.nome_completo : 'Utilizador desconhecido';
    }
    if (movimento.supplier_id) {
      const supplier = (suppliers || []).find((item) => item.id === movimento.supplier_id);
      return supplier ? supplier.nome : (movimento.nome_manual || 'Fornecedor desconhecido');
    }
    return movimento.nome_manual || defaultFinancialEntityName;
  };

  const getCentroCustoName = (id?: string) => {
    if (!id) return '-';
    const cc = (centrosCusto || []).find((c) => c.id === id);
    return cc ? cc.nome : '-';
  };

  const getFaturasAssociadas = (movimentoId?: string | null) => {
    if (!movimentoId) return null;

    const itens = (movimentoItens || []).filter((item) => item.movimento_id === movimentoId);
    const faturasIds = itens.map((item) => item.fatura_id).filter(Boolean);

    if (faturasIds.length === 0) return null;

    const faturasAssociadas = faturasIds
      .map((faturaId) => {
        const movimento = (allMovimentos || []).find((m) => m.id === faturaId);

        if (movimento) {
          const nomeDisplay = getNomeDisplay(movimento);
          return `${movimento.tipo} - ${nomeDisplay || 'Cliente'}`;
        }
        return 'Associacao externa preservada';
      })
      .filter(Boolean);

    return faturasAssociadas.length > 0 ? faturasAssociadas.join(', ') : null;
  };

  const getClassificacaoBadge = (classificacao: 'receita' | 'despesa') => {
    const variants = {
      receita: 'bg-green-100 text-green-800',
      despesa: 'bg-red-100 text-red-800',
    };
    return <Badge className={variants[classificacao]}>{classificacao.toUpperCase()}</Badge>;
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="grid w-full min-w-0 grid-cols-2 items-center gap-2 lg:grid-cols-4">
          <Select value={classificacaoFilter} onValueChange={setClassificacaoFilter}>
            <SelectTrigger className="w-full min-w-0 overflow-hidden px-2 sm:px-3 [&>span]:truncate">
              <SelectValue placeholder="Classificacao" />
            </SelectTrigger>
            <SelectContent className={scrollableSelectContentClassName}>
              <SelectItem value="all">Todas</SelectItem>
              <SelectItem value="receita">Receita</SelectItem>
              <SelectItem value="despesa">Despesa</SelectItem>
            </SelectContent>
          </Select>

          <Select value={estadoFilter} onValueChange={setEstadoFilter}>
            <SelectTrigger className="w-full min-w-0 overflow-hidden px-2 sm:px-3 [&>span]:truncate">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent className={scrollableSelectContentClassName}>
              <SelectItem value="all">Todos</SelectItem>
              <SelectItem value="pendente">Pendente</SelectItem>
              <SelectItem value="por_pagar">Por pagar</SelectItem>
              <SelectItem value="pago">Pago</SelectItem>
              <SelectItem value="vencido">Vencido</SelectItem>
              <SelectItem value="parcial">Parcial</SelectItem>
              <SelectItem value="pago_parcial">Pago parcial</SelectItem>
              <SelectItem value="cancelado">Cancelado</SelectItem>
              <SelectItem value="nao_aplicavel">Nao aplicavel</SelectItem>
            </SelectContent>
          </Select>

          <Select value={documentalFilter} onValueChange={setDocumentalFilter}>
            <SelectTrigger className="w-full min-w-0 overflow-hidden px-2 sm:px-3 [&>span]:truncate" title="Estado documental">
              <SelectValue placeholder="Documentos" />
            </SelectTrigger>
            <SelectContent className={scrollableSelectContentClassName}>
              <SelectItem value="all">Todos</SelectItem>
              <SelectItem value="falta_fatura">Falta fatura</SelectItem>
              <SelectItem value="falta_recibo">Falta recibo</SelectItem>
              <SelectItem value="falta_comprovativo_pagamento">Falta comprovativo</SelectItem>
              <SelectItem value="pendente_validacao">Pendente validacao</SelectItem>
              <SelectItem value="completo">Completo</SelectItem>
              <SelectItem value="inconsistente">Inconsistente</SelectItem>
            </SelectContent>
          </Select>

          <Select value={conciliacaoFilter} onValueChange={setConciliacaoFilter}>
            <SelectTrigger className="w-full min-w-0 overflow-hidden px-2 sm:px-3 [&>span]:truncate" title="Conciliação bancária">
              <SelectValue placeholder="Conciliação" />
            </SelectTrigger>
            <SelectContent className={scrollableSelectContentClassName}>
              <SelectItem value="all">Todas</SelectItem>
              <SelectItem value="nao_conciliado">Nao conciliado</SelectItem>
              <SelectItem value="sugerido">Sugerido</SelectItem>
              <SelectItem value="conciliado">Conciliado</SelectItem>
              <SelectItem value="divergente">Divergente</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="flex max-w-full flex-wrap gap-2 md:justify-end">
          {selectedMovimentos.size > 0 && (
            <>
              <Button variant="outline" onClick={() => handleAbrirDialogoRecibo()}>
                <Check className="mr-2" size={18} />
                Liquidar Selecionados ({selectedMovimentos.size})
              </Button>
              <Button variant="destructive" onClick={handleAbrirDialogoDelete}>
                <Trash className="mr-2" size={18} />
                Apagar Selecionados ({selectedMovimentos.size})
              </Button>
            </>
          )}

          <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            <DialogTrigger asChild>
              <Button onClick={resetForm}>
                <Plus className="mr-2" />
                Novo Movimento
              </Button>
            </DialogTrigger>
            <Button variant="outline" onClick={() => router.visit(route('logistica.index', { tab: 'fornecedores' }))}>
              Nova compra de material/stock
            </Button>
            <DialogContent className="w-[calc(100vw-1rem)] sm:w-[calc(100vw-2rem)] sm:max-w-6xl max-h-[90vh] overflow-y-auto overflow-x-hidden p-3 sm:p-6">
              <DialogHeader>
                <DialogTitle>{editingMovimentoId ? 'Editar Movimento' : 'Novo Movimento'}</DialogTitle>
                <DialogDescription>
                  {editingMovimentoId ? 'Altere os dados do movimento' : 'Registe um novo movimento manual'}
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-3 overflow-x-hidden">
                <div className="flex items-center space-x-2 p-2 bg-muted rounded-lg">
                  <Checkbox
                    id="usar-dados-utilizador"
                    checked={usarDadosUtilizador}
                    onCheckedChange={(checked) => {
                      setUsarDadosUtilizador(checked === true);
                      if (checked === true) {
                        setUsarDadosFornecedor(false);
                      }
                      if (!checked) {
                        setFormData((prev) => ({
                          ...prev,
                          user_id: '',
                          supplier_id: '',
                          nome_manual: '',
                          nif_manual: '',
                          morada_manual: '',
                        }));
                      } else {
                        setFormData((prev) => ({
                          ...prev,
                          supplier_id: '',
                          nome_manual: '',
                          nif_manual: '',
                          morada_manual: '',
                        }));
                      }
                    }}
                  />
                  <Label htmlFor="usar-dados-utilizador" className="cursor-pointer text-sm">
                    Usar dados de utilizador existente
                  </Label>
                </div>

                <div className="flex items-center space-x-2 p-2 bg-muted rounded-lg">
                  <Checkbox
                    id="usar-dados-fornecedor"
                    checked={usarDadosFornecedor}
                    onCheckedChange={(checked) => {
                      setUsarDadosFornecedor(checked === true);
                      if (checked === true) {
                        setUsarDadosUtilizador(false);
                      }
                      if (!checked) {
                        setFormData((prev) => ({
                          ...prev,
                          supplier_id: '',
                          nome_manual: '',
                          nif_manual: '',
                          morada_manual: '',
                        }));
                      } else {
                        setFormData((prev) => ({
                          ...prev,
                          user_id: '',
                          nome_manual: '',
                          nif_manual: '',
                          morada_manual: '',
                        }));
                      }
                    }}
                  />
                  <Label htmlFor="usar-dados-fornecedor" className="cursor-pointer text-sm">
                    Usar dados de fornecedor existente
                  </Label>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  {usarDadosUtilizador ? (
                    <div className="space-y-1 md:col-span-2 min-w-0">
                      <Label className="text-sm">Utilizador *</Label>
                      <Select value={formData.user_id} onValueChange={handleUserChange}>
                        <SelectTrigger className="h-8 text-sm">
                          <SelectValue placeholder="Selecionar utilizador" />
                        </SelectTrigger>
                        <SelectContent className={scrollableSelectContentClassName}>
                          {(users || []).map((user) => (
                            <SelectItem key={user.id} value={user.id}>
                              {user.nome_completo} - {user.numero_socio}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  ) : usarDadosFornecedor ? (
                    <div className="space-y-1 md:col-span-2 min-w-0">
                      <Label className="text-sm">Fornecedor *</Label>
                      <Select value={formData.supplier_id} onValueChange={handleSupplierChange}>
                        <SelectTrigger className="h-8 text-sm">
                          <SelectValue placeholder="Selecionar fornecedor" />
                        </SelectTrigger>
                        <SelectContent className={scrollableSelectContentClassName}>
                          {(suppliers || []).map((supplier) => (
                            <SelectItem key={supplier.id} value={supplier.id}>
                              {supplier.nome}{supplier.nif ? ` - ${supplier.nif}` : ''}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  ) : (
                    <>
                      <div className="space-y-1 md:col-span-2 min-w-0">
                        <Label className="text-sm">Entidade</Label>
                        <Input
                          className="h-8 text-sm"
                          value={formData.nome_manual}
                          onChange={(e) => setFormData({ ...formData, nome_manual: e.target.value })}
                          placeholder="Nome do cliente/fornecedor"
                        />
                      </div>
                      <div className="space-y-1 min-w-0">
                        <Label className="text-sm">NIF</Label>
                        <Input
                          className="h-8 text-sm"
                          value={formData.nif_manual}
                          onChange={(e) => setFormData({ ...formData, nif_manual: e.target.value })}
                          placeholder="Numero de contribuinte"
                        />
                      </div>
                      <div className="space-y-1 min-w-0">
                        <Label className="text-sm">Morada</Label>
                        <Input
                          className="h-8 text-sm"
                          value={formData.morada_manual}
                          onChange={(e) => setFormData({ ...formData, morada_manual: e.target.value })}
                          placeholder="Morada completa"
                        />
                      </div>
                    </>
                  )}

                  <div className="space-y-1 min-w-0">
                    <Label>Classificacao *</Label>
                    <Select
                      value={formData.classificacao}
                      onValueChange={(v) => setFormData({ ...formData, classificacao: v as 'receita' | 'despesa' })}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent className={scrollableSelectContentClassName}>
                        <SelectItem value="receita">Receita</SelectItem>
                        <SelectItem value="despesa">Despesa</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2 min-w-0">
                    <Label>Categoria</Label>
                    <Input
                      value={formData.categoria}
                      onChange={(e) => setFormData({ ...formData, categoria: e.target.value })}
                      placeholder="agua, luz, seguros, transportes..."
                    />
                  </div>

                  <div className="space-y-2 min-w-0">
                    <Label>Tipo *</Label>
                    <Select
                      value={formData.tipo}
                      onValueChange={(v) =>
                        setFormData((current) => {
                          const tipo = v as Movimento['tipo'];
                          let origem = current.origem_tipo;

                          if (!origem || origem === 'manual') {
                            if (tipo === 'inscricao') origem = 'evento';
                            else if (tipo === 'material') origem = 'stock';
                            else if (tipo === 'patrocinio') origem = 'patrocinio';
                            else if (tipo === 'servico') origem = 'manual';
                            else origem = null;
                          }

                          return { ...current, tipo, origem_tipo: origem };
                        })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent className={scrollableSelectContentClassName}>
                        <SelectItem value="inscricao">Inscricao</SelectItem>
                        <SelectItem value="material">Material</SelectItem>
                        <SelectItem value="servico">Servico</SelectItem>
                        <SelectItem value="patrocinio">Patrocinio</SelectItem>
                        <SelectItem value="outro">Outro</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {formData.classificacao === 'despesa' && (
                    <div className="space-y-2 min-w-0">
                      <Label>Estado pagamento</Label>
                      <Select
                        value={formData.estado_pagamento}
                        onValueChange={(v) => setFormData({ ...formData, estado_pagamento: v as Movimento['estado_pagamento'] })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="por_pagar">Por pagar</SelectItem>
                          <SelectItem value="pago">Pago</SelectItem>
                          <SelectItem value="pago_parcial">Pago parcial</SelectItem>
                          <SelectItem value="cancelado">Cancelado</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  )}

                  <div className="space-y-2 min-w-0">
                    <Label>Origem</Label>
                    <Select
                      value={formData.origem_tipo || 'none'}
                      onValueChange={(v) =>
                        setFormData((current) => ({
                          ...current,
                          origem_tipo: v === 'none' ? null : (v as Movimento['origem_tipo']),
                        }))
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Sem origem" />
                      </SelectTrigger>
                      <SelectContent className={scrollableSelectContentClassName}>
                        <SelectItem value="none">Sem origem</SelectItem>
                        <SelectItem value="evento">Evento</SelectItem>
                        <SelectItem value="stock">Stock</SelectItem>
                        <SelectItem value="patrocinio">Patrocinio</SelectItem>
                        <SelectItem value="manual">Manual</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {formData.origem_tipo && (
                    <div className="space-y-2 min-w-0">
                      <Label>Referencia</Label>
                      <Input
                        value={formData.origem_id}
                        onChange={(e) => setFormData({ ...formData, origem_id: e.target.value })}
                        placeholder="ID ou referencia externa"
                      />
                    </div>
                  )}

                  <div className="space-y-2 min-w-0">
                    <Label>Data Emissao</Label>
                    <Input type="date" value={formData.data_emissao} onChange={(e) => setFormData({ ...formData, data_emissao: e.target.value })} />
                  </div>

                  <div className="space-y-2 min-w-0">
                    <Label>Data Vencimento</Label>
                    <Input type="date" value={formData.data_vencimento} onChange={(e) => setFormData({ ...formData, data_vencimento: e.target.value })} />
                  </div>

                  <div className="space-y-2 md:col-span-2 min-w-0">
                    <Label>Centro de Custo</Label>
                    <Select
                      value={formData.centro_custo_id}
                      onValueChange={(v) => setFormData({ ...formData, centro_custo_id: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Opcional" />
                      </SelectTrigger>
                      <SelectContent className={scrollableSelectContentClassName}>
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

                  <div className="space-y-2 md:col-span-2 min-w-0">
                    <Label>Documento Original (opcional)</Label>
                    <Input
                      type="file"
                      onChange={(e) => setDocumentoOriginalFile(e.target.files?.[0] || null)}
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label className="text-sm font-semibold">Linhas do Movimento</Label>
                    <Button type="button" size="sm" variant="outline" onClick={addLinha}>
                      <Plus size={16} className="mr-1" />
                      Adicionar Linha
                    </Button>
                  </div>

                  <div className="hidden lg:grid lg:grid-cols-[minmax(200px,2.4fr)_minmax(180px,2fr)_minmax(85px,1fr)_minmax(70px,0.8fr)_minmax(75px,0.9fr)_minmax(90px,1fr)_48px] gap-2 px-2 py-1 bg-muted rounded border border-border">
                    <span className="text-xxs font-bold whitespace-nowrap">Descrição</span>
                    <span className="text-xxs font-bold whitespace-nowrap">Atleta</span>
                    <span className="text-xxs font-bold whitespace-nowrap">V.Unit</span>
                    <span className="text-xxs font-bold whitespace-nowrap">Qtd</span>
                    <span className="text-xxs font-bold whitespace-nowrap">IVA %</span>
                    <span className="text-xxs font-bold whitespace-nowrap">Total</span>
                    <span className="text-xxs font-bold whitespace-nowrap text-center">Ação</span>
                  </div>

                  <div className="space-y-2">
                    {linhas.map((linha, index) => (
                      <Card key={index} className="p-2">
                        <div className="space-y-1">
                          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[minmax(200px,2.4fr)_minmax(180px,2fr)_minmax(85px,1fr)_minmax(70px,0.8fr)_minmax(75px,0.9fr)_minmax(90px,1fr)_48px] gap-2">
                            <div className="sm:col-span-2 lg:col-span-1 space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">Descrição</Label>
                              <Input
                                placeholder="Item"
                                className="h-7 text-xs"
                                value={stripAtletaMarker(linha.descricao)}
                                onChange={(e) => updateLinha(index, 'descricao', e.target.value)}
                              />
                            </div>
                            <div className="sm:col-span-2 lg:col-span-1 space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">Atleta</Label>
                              <Select
                                value={linha.atleta_id || 'none'}
                                onValueChange={(v) => {
                                  if (v && v !== 'none') {
                                    updateLinha(index, 'atleta_id', v);
                                  } else {
                                    updateLinha(index, 'atleta_id', undefined);
                                  }
                                }}
                              >
                                <SelectTrigger className="h-7 text-xs">
                                  <SelectValue placeholder="Nenhum" />
                                </SelectTrigger>
                                <SelectContent className={scrollableSelectContentClassName}>
                                  <SelectItem value="none">Nenhum</SelectItem>
                                  {(users || []).map((user) => (
                                    <SelectItem key={user.id} value={user.id}>
                                      {user.nome_completo}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">V.Unit</Label>
                              <Input
                                type="number"
                                step="0.01"
                                min="0"
                                className="h-7 text-xs"
                                value={linha.valor_unitario}
                                onChange={(e) => updateLinha(index, 'valor_unitario', parseFloat(e.target.value) || 0)}
                              />
                            </div>
                            <div className="space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">Qtd</Label>
                              <Input
                                type="number"
                                min="1"
                                className="h-7 text-xs"
                                value={linha.quantidade}
                                onChange={(e) => updateLinha(index, 'quantidade', parseInt(e.target.value) || 1)}
                              />
                            </div>
                            <div className="space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">IVA %</Label>
                              <Input
                                type="number"
                                min="0"
                                className="h-7 text-xs"
                                value={linha.imposto_percentual}
                                onChange={(e) => updateLinha(index, 'imposto_percentual', parseFloat(e.target.value) || 0)}
                              />
                            </div>
                            <div className="space-y-1 min-w-0">
                              <Label className="text-xxs font-semibold">Total</Label>
                              <div className="text-xs font-medium h-7 flex items-center">
                                €{(linha.valor_unitario * linha.quantidade * (1 + linha.imposto_percentual / 100)).toFixed(2)}
                              </div>
                            </div>
                            <div className="sm:col-span-2 lg:col-span-1 flex items-end pb-1 justify-end lg:justify-center">
                              {linhas.length > 1 && (
                                <Button type="button" size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={() => removeLinha(index)}>
                                  <X size={14} />
                                </Button>
                              )}
                            </div>
                          </div>

                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-1">
                            <div className="space-y-1">
                              <Label className="text-xxs font-semibold">Movimento (opcional)</Label>
                              <Select
                                value={linha.fatura_id && linha.tipo_fatura === 'movimento' ? linha.fatura_id : 'none'}
                                onValueChange={(v) => {
                                  if (v && v !== 'none') {
                                    updateLinha(index, 'fatura_id', v);
                                    updateLinha(index, 'tipo_fatura', 'movimento');
                                    const movimento = (allMovimentos || []).find((m) => m.id === v);
                                    if (movimento) {
                                      const nomeDisplay = getNomeDisplay(movimento);
                                      updateLinha(
                                        index,
                                        'descricao',
                                        `${movimento.tipo} - ${nomeDisplay || 'Cliente'} - ${format(new Date(movimento.data_emissao), 'dd/MM/yyyy')}`
                                      );
                                      updateLinha(index, 'valor_unitario', Math.abs(movimento.valor_total));
                                    }
                                  } else {
                                    if (linha.tipo_fatura === 'movimento') {
                                      updateLinha(index, 'fatura_id', undefined);
                                      updateLinha(index, 'tipo_fatura', undefined);
                                    }
                                  }
                                }}
                              >
                                <SelectTrigger className="h-7 text-xs">
                                  <SelectValue placeholder="Nenhum" />
                                </SelectTrigger>
                                <SelectContent className={scrollableSelectContentClassName}>
                                  <SelectItem value="none">Nenhum</SelectItem>
                                  {(allMovimentos || [])
                                    .filter((m) => m.id !== editingMovimentoId && m.estado_pagamento !== 'cancelado')
                                    .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime())
                                    .map((movimento) => {
                                      const nomeDisplay = getNomeDisplay(movimento);
                                      return (
                                        <SelectItem key={movimento.id} value={movimento.id}>
                                          {nomeDisplay} - {movimento.tipo} - €{toNumber(movimento.valor_total).toFixed(2)} ({format(new Date(movimento.data_emissao), 'dd/MM/yyyy')})
                                        </SelectItem>
                                      );
                                    })}
                                </SelectContent>
                              </Select>
                            </div>

                            {linha.fatura_id && linha.tipo_fatura === 'mensalidade' && (
                              <div className="space-y-1">
                                <Label className="text-xxs font-semibold">Associacao legacy</Label>
                                <div className="flex h-7 items-center rounded-md border border-input bg-muted px-2 text-[11px] text-muted-foreground">
                                  Associacao a mensalidade preservada em modo administrativo; esta tab nao permite criar novas ligacoes a mensalidades.
                                </div>
                              </div>
                            )}
                          </div>
                        </div>
                      </Card>
                    ))}
                  </div>
                </div>

                <div className="space-y-1">
                  <Label className="text-sm">Observacoes</Label>
                  <Textarea
                    className="h-16 text-sm"
                    placeholder="Notas adicionais"
                    value={formData.observacoes}
                    onChange={(e) => setFormData({ ...formData, observacoes: e.target.value })}
                    rows={2}
                  />
                </div>

                <div className="flex items-center justify-between p-2 bg-muted rounded-lg">
                  <span className="text-sm font-semibold">Total do Movimento:</span>
                  <span className="text-xl font-bold text-primary">
                    {formData.classificacao === 'despesa' ? '-' : ''}€{linhas
                      .reduce((sum, l) => sum + l.valor_unitario * l.quantidade * (1 + l.imposto_percentual / 100), 0)
                      .toFixed(2)}
                  </span>
                </div>

                {editingMovimentoCanReopen ? (
                  <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-medium">Reabertura controlada do movimento</p>
                    <p className="mt-1">
                      Para voltar este movimento a pendente ou vencido, use a acao canonica abaixo. Se existir documento fiscal externo emitido, a reabertura sera bloqueada.
                    </p>
                  </div>
                ) : null}
              </div>
              <DialogFooter>
                {editingMovimentoCanReopen && editingMovimentoId ? (
                  <>
                    <Button
                      type="button"
                      variant="outline"
                      disabled={reopeningMovimentoId === editingMovimentoId}
                      onClick={() => handleReabrirMovimento(editingMovimentoId, 'pendente')}
                    >
                      Reabrir para Pendente
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      disabled={reopeningMovimentoId === editingMovimentoId}
                      onClick={() => handleReabrirMovimento(editingMovimentoId, 'vencido')}
                    >
                      Reabrir para Vencido
                    </Button>
                  </>
                ) : null}
                <Button variant="outline" onClick={() => { setDialogOpen(false); resetForm(); }}>
                  Cancelar
                </Button>
                <Button onClick={handleCriarMovimento}>{editingMovimentoId ? 'Guardar Alterações' : 'Criar Movimento'}</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      <Card className="w-full">
        <div className="space-y-3 p-3 md:hidden">
          {sortedMovimentos.length === 0 ? (
            <div className="py-8 text-center text-sm text-muted-foreground">Nenhum movimento encontrado</div>
          ) : (
            sortedMovimentos.map((movimento) => {
              const movimentoValor = toNumber(movimento.valor_total);
              const actionId = getActionableMovimentoId(movimento);
              const isSelected = actionId ? selectedMovimentos.has(actionId) : false;
              const paidAmount = getPaidAmount(movimento);
              const openAmount = getOpenAmount(movimento);

              return (
                <Card key={movimento.id} className="p-2.5">
                  <div className="space-y-2">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="truncate text-sm font-semibold">{getNomeDisplay(movimento)}</div>
                        <div className="mt-1 flex flex-wrap gap-1">
                          {getClassificacaoBadge(movimento.classificacao)}
                          <Badge variant="outline">{movimento.tipo}</Badge>
                          <MovementPaymentStatusBadge status={movimento.estado_pagamento_exibicao || movimento.estado_pagamento} />
                          <MovementConciliationStatusBadge status={movimento.estado_conciliacao} />
                          <MovementDocumentStatusBadge status={movimento.estado_documental} />
                        </div>
                      </div>
                      <Checkbox
                        checked={isSelected}
                        disabled={!actionId}
                        onCheckedChange={() => actionId && handleToggleMovimentoSelection(actionId)}
                        aria-label="Selecionar movimento"
                      />
                    </div>

                    <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                      <span className="text-muted-foreground">Emissao</span>
                      <span className="text-right">{format(new Date(movimento.data_emissao), 'dd/MM/yyyy')}</span>
                      <span className="text-muted-foreground">Vencimento</span>
                      <span className="text-right">{format(new Date(movimento.data_vencimento), 'dd/MM/yyyy')}</span>
                      <span className="text-muted-foreground">Centro Custo</span>
                      <span className="truncate text-right">{getCentroCustoName(movimento.centro_custo_id || undefined)}</span>
                      <span className="text-muted-foreground">Valor</span>
                      <span className={`text-right font-semibold ${movimentoValor < 0 ? 'text-red-600' : 'text-green-600'}`}>
                        €{movimentoValor.toFixed(2)}
                      </span>
                      <span className="text-muted-foreground">Pago</span>
                      <span className="text-right">{formatAmount(paidAmount)}</span>
                      <span className="text-muted-foreground">Em aberto</span>
                      <span className="text-right">{formatAmount(openAmount)}</span>
                    </div>

                    <div className="text-xs text-muted-foreground">
                      <span className="font-medium">Associacoes:</span> {getFaturasAssociadas(actionId) || '-'}
                    </div>

                    {movimento.read_only && (
                      <div className="text-xs text-muted-foreground">
                        Entrada canónica sem `Movement` associado. Disponivel apenas para consulta nesta fatia.
                      </div>
                    )}

                    <div className="flex flex-wrap gap-2 pt-1">
                      {actionId && (
                        <>
                          <Button size="sm" variant="outline" onClick={() => router.visit(route('financeiro.movimentos.show', actionId))}>
                            <Files size={16} className="mr-1" />
                            Detalhe
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => handleEditarMovimento(actionId)}>
                            <PencilSimple size={16} className="mr-1" />
                            Editar
                          </Button>
                          {(movimento.estado_pagamento === 'pendente' || movimento.estado_pagamento === 'por_pagar' || movimento.estado_pagamento === 'vencido') && (
                            <Button size="sm" variant="outline" onClick={() => handleAbrirDialogoRecibo(actionId)}>
                              <Check size={16} className="mr-1" />
                              Liquidar
                            </Button>
                          )}
                          {movimento.estado_pagamento === 'pago' && !movimento.numero_recibo && (
                            <Button size="sm" variant="outline" onClick={() => handleAbrirDialogoRecibo(actionId)}>
                              <Check size={16} className="mr-1" />
                              Recibo
                            </Button>
                          )}
                          {movimento.estado_pagamento === 'pago' && movimento.numero_recibo && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleAbrirDialogoRecibo(actionId, movimento.numero_recibo, movimento.metodo_pagamento)}
                            >
                              Editar Recibo
                            </Button>
                          )}
                          <Button size="sm" variant="ghost" onClick={() => handleDeleteSingleMovimento(actionId)}>
                            <Trash size={16} className="mr-1" />
                            Apagar
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                </Card>
              );
            })
          )}
        </div>

        <div className="hidden w-full md:block">
        <Table className="w-full table-fixed">
          <TableHeader>
            <TableRow>
              <TableHead className="w-10">
                <Checkbox
                  checked={selectedMovimentos.size === selectableMovimentoIds.length && selectableMovimentoIds.length > 0}
                  onCheckedChange={handleToggleAllMovimentos}
                />
              </TableHead>
              <TableHead className="w-[15%]">Nome</TableHead>
              <TableHead className="w-[10%]">Classificacao</TableHead>
              <TableHead className="w-[8%]">Tipo</TableHead>
              <TableHead className="w-[9%]">Data Emissao</TableHead>
              <TableHead className="w-[9%]">Vencimento</TableHead>
              <TableHead className="w-[8%]">Valor</TableHead>
              <TableHead className="w-[8%]">Pago</TableHead>
              <TableHead className="w-[8%]">Em Aberto</TableHead>
              <TableHead className="w-[8%]">Estado</TableHead>
              <TableHead className="w-[10%]">Documentos</TableHead>
              <TableHead className="w-[10%]">Centro Custo</TableHead>
              <TableHead className="w-[12%]">Associacoes</TableHead>
              <TableHead className="w-[11%] text-right">Acoes</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {sortedMovimentos.length === 0 ? (
              <TableRow>
                <TableCell colSpan={14} className="text-center text-muted-foreground py-8">
                  Nenhum movimento encontrado
                </TableCell>
              </TableRow>
            ) : (
              sortedMovimentos.map((movimento) => {
                  const movimentoValor = toNumber(movimento.valor_total);
                  const actionId = getActionableMovimentoId(movimento);
                  const paidAmount = getPaidAmount(movimento);
                  const openAmount = getOpenAmount(movimento);
                  return (
                    <TableRow key={movimento.id}>
                    <TableCell>
                      <Checkbox
                        checked={actionId ? selectedMovimentos.has(actionId) : false}
                        disabled={!actionId}
                        onCheckedChange={() => actionId && handleToggleMovimentoSelection(actionId)}
                      />
                    </TableCell>
                    <TableCell className="font-medium break-words">
                      <div>{getNomeDisplay(movimento)}</div>
                      {movimento.read_only && (
                        <div className="text-[11px] text-muted-foreground">Entrada canónica sem `Movement` associado</div>
                      )}
                    </TableCell>
                    <TableCell>{getClassificacaoBadge(movimento.classificacao)}</TableCell>
                    <TableCell>
                      <Badge variant="outline" className="max-w-full break-words text-center leading-tight">{movimento.tipo}</Badge>
                    </TableCell>
                    <TableCell>{format(new Date(movimento.data_emissao), 'dd/MM/yyyy')}</TableCell>
                    <TableCell>{format(new Date(movimento.data_vencimento), 'dd/MM/yyyy')}</TableCell>
                    <TableCell className="font-semibold">
                      <span className={movimentoValor < 0 ? 'text-red-600' : 'text-green-600'}>
                        €{movimentoValor.toFixed(2)}
                      </span>
                    </TableCell>
                    <TableCell>{formatAmount(paidAmount)}</TableCell>
                    <TableCell>{formatAmount(openAmount)}</TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        <MovementPaymentStatusBadge status={movimento.estado_pagamento_exibicao || movimento.estado_pagamento} />
                        <MovementConciliationStatusBadge status={movimento.estado_conciliacao} />
                      </div>
                    </TableCell>
                    <TableCell><MovementDocumentStatusBadge status={movimento.estado_documental} /></TableCell>
                    <TableCell className="text-sm break-words">{getCentroCustoName(movimento.centro_custo_id || undefined)}</TableCell>
                    <TableCell className="text-sm text-muted-foreground break-words leading-snug">
                      {getFaturasAssociadas(actionId) || '-'}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex flex-wrap items-center justify-end gap-2">
                        {actionId && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => router.visit(route('financeiro.movimentos.show', actionId))} title="Abrir ficha do movimento">
                              <Files size={16} />
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => handleEditarMovimento(actionId)} title="Editar movimento">
                              <PencilSimple size={16} />
                            </Button>
                            {(movimento.estado_pagamento === 'pendente' || movimento.estado_pagamento === 'por_pagar' || movimento.estado_pagamento === 'vencido') && (
                              <Button size="sm" variant="outline" onClick={() => handleAbrirDialogoRecibo(actionId)}>
                                <Check size={16} className="mr-1" />
                                Liquidar
                              </Button>
                            )}
                            {movimento.estado_pagamento === 'pago' && !movimento.numero_recibo && (
                              <Button size="sm" variant="outline" onClick={() => handleAbrirDialogoRecibo(actionId)}>
                                <Check size={16} className="mr-1" />
                                Recibo
                              </Button>
                            )}
                            {movimento.estado_pagamento === 'pago' && movimento.numero_recibo && (
                              <div className="flex flex-wrap items-center justify-end gap-2">
                                <div className="text-xs text-muted-foreground break-all">Recibo: {movimento.numero_recibo}</div>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => handleAbrirDialogoRecibo(actionId, movimento.numero_recibo, movimento.metodo_pagamento)}
                                >
                                  Editar Recibo
                                </Button>
                              </div>
                            )}
                            <Button size="sm" variant="ghost" onClick={() => handleDeleteSingleMovimento(actionId)}>
                              <Trash size={16} />
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                    </TableRow>
                  );
                })
            )}
          </TableBody>
        </Table>
        </div>
      </Card>

      <Dialog open={dialogReciboOpen} onOpenChange={setDialogReciboOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {selectedMovimentoId ? 'Liquidar Movimento' : `Liquidar ${selectedMovimentos.size} Movimento(s)`}
            </DialogTitle>
            <DialogDescription>
              {selectedMovimentoId
                ? 'Confirme o pagamento do movimento com um metodo de pagamento ativo.'
                : `Confirme o pagamento de ${selectedMovimentos.size} movimento(s) com o mesmo metodo de pagamento.`}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Metodo de Pagamento</Label>
              <Select
                value={metodoPagamento}
                onValueChange={(value) => {
                  const nextMethod = activePaymentMethods.find((method) => method.codigo === value) || null;

                  setMetodoPagamento(value);

                  if (!nextMethod?.requer_linha_bancaria) {
                    setSelectedBankStatementId('none');
                  }
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent className={scrollableSelectContentClassName}>
                  {activePaymentMethods.map((method) => (
                    <SelectItem key={method.id} value={method.codigo}>
                      {method.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {paymentRequiresBankStatement ? (
              <div className="space-y-2">
                <Label>Linha de Extrato Bancario</Label>
                {hasAvailableBankStatements ? (
                  <Select value={selectedBankStatementId} onValueChange={setSelectedBankStatementId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecione uma linha bancária" />
                    </SelectTrigger>
                    <SelectContent className={scrollableSelectContentClassName}>
                      {availableBankStatements.map((statement) => {
                        const remaining = statement.valor_por_conciliar !== null && statement.valor_por_conciliar !== undefined
                          ? Math.abs(toNumber(statement.valor_por_conciliar, 0))
                          : Math.abs(toNumber(statement.valor, 0));

                        return (
                          <SelectItem key={statement.id} value={statement.id}>
                            {format(new Date(statement.data_movimento), 'dd/MM/yyyy')} | {statement.descricao} | EUR {remaining.toFixed(2)}
                          </SelectItem>
                        );
                      })}
                    </SelectContent>
                  </Select>
                ) : (
                  <div className="rounded-md border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Nao existem linhas bancarias disponiveis para conciliar.
                  </div>
                )}
              </div>
            ) : null}
            <div className="space-y-2">
              <Label>Comprovativo (opcional)</Label>
              <Input type="file" onChange={(e) => setComprovativoFile(e.target.files?.[0] || null)} />
            </div>
            {liquidacaoValidationMessage ? (
              <p className="text-sm text-amber-700">{liquidacaoValidationMessage}</p>
            ) : null}
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setDialogReciboOpen(false);
                setSelectedMovimentoId(null);
                setSelectedBankStatementId('none');
                setComprovativoFile(null);
              }}
            >
              Cancelar
            </Button>
            <Button onClick={handleConfirmarLiquidacao} disabled={!canConfirmLiquidacao}>
              <Check className="mr-2" size={16} />
              Confirmar Liquidacao
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialogDeleteOpen} onOpenChange={setDialogDeleteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Confirmar Eliminacao</DialogTitle>
            <DialogDescription>
              Esta acao e irreversivel. Os movimentos serao permanentemente removidos.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Tem a certeza que deseja apagar {selectedMovimentos.size} movimento(s)? Esta acao nao pode ser revertida.
            </p>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogDeleteOpen(false)}>
              Cancelar
            </Button>
            <Button variant="destructive" onClick={handleConfirmarDelete}>
              <Trash className="mr-2" size={16} />
              Confirmar Eliminacao
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
