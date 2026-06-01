import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react';
import { router } from '@inertiajs/react';
import { ExtratoBancario, LancamentoFinanceiro, Fatura, CentroCusto, User, Movimento, ConciliacaoMapa, BankReconciliationSuggestion } from './types';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { MovementDocumentStatusBadge } from '@/Components/Financeiro/MovementStatusBadges';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogTrigger, DialogFooter } from '@/Components/ui/dialog';
import { Badge } from '@/Components/ui/badge';
import { Checkbox } from '@/Components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { Plus, ArrowsLeftRight, Check, Bank, PencilSimple, X, FileArrowUp, Gear, Trash } from '@phosphor-icons/react';
import { Eye, Sparkles } from 'lucide-react';
import { format } from 'date-fns';
import { toast } from 'sonner';
import { BankStatementReconciliationDialog } from './BankStatementReconciliationDialog';

interface BancoTabProps {
  extratos: ExtratoBancario[];
  setExtratos: React.Dispatch<React.SetStateAction<ExtratoBancario[]>>;
  lancamentos: LancamentoFinanceiro[];
  setLancamentos: React.Dispatch<React.SetStateAction<LancamentoFinanceiro[]>>;
  faturas: Fatura[];
  setFaturas: React.Dispatch<React.SetStateAction<Fatura[]>>;
  movimentos: Movimento[];
  setMovimentos: React.Dispatch<React.SetStateAction<Movimento[]>>;
  setConciliacoes: React.Dispatch<React.SetStateAction<ConciliacaoMapa[]>>;
  centrosCusto: CentroCusto[];
  users: User[];
}

// Fiscal document request queue will be hooked here after reconciliation is confirmed.
// The inverse payment-allocation UI from a bank statement should use
// financeiro.bank-statements.unreconciled + financeiro.invoices.open +
// financeiro.payments.allocate, which are now available on the backend.

export function BancoTab({
  extratos,
  setExtratos,
  lancamentos,
  setLancamentos,
  faturas,
  setFaturas,
  movimentos,
  setMovimentos,
  setConciliacoes,
  centrosCusto,
  users,
}: BancoTabProps) {
  const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    if (token?.content) {
      return token.content;
    }

    const cookieToken = document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    if (!cookieToken) {
      return '';
    }

    const encodedToken = cookieToken.slice('XSRF-TOKEN='.length);

    try {
      return decodeURIComponent(encodedToken);
    } catch {
      return encodedToken;
    }
  };
  const buildJsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': getCsrfToken(),
  });
  const buildRouteUrl = (name: string, params?: string | number | Record<string, unknown>, query?: Record<string, string>) => {
    const routePath = route(name, params);
    const baseUrl = routePath.startsWith('http')
      ? routePath
      : `${window.location.origin}${routePath.startsWith('/') ? routePath : `/${routePath}`}`;

    if (!query || Object.keys(query).length === 0) {
      return baseUrl;
    }

    const url = new URL(baseUrl);
    Object.entries(query).forEach(([key, value]) => {
      if (value !== '') {
        url.searchParams.set(key, value);
      }
    });

    return url.toString();
  };
  const toNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const parsed = Number(value);
      return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
  };
  const refreshFinanceiroData = () => {
    router.reload({
      only: ['extratos', 'movimentosFinanceiros', 'mensalidadesFaturas', 'faturas', 'lancamentos', 'fiscalRequests', 'dashboardData'],
      preserveScroll: true,
    });
  };
  const getStatementStatus = (extrato: ExtratoBancario): 'unreconciled' | 'partial' | 'reconciled' => {
    if (extrato.conciliacao_status === 'partial' || extrato.conciliacao_status === 'reconciled' || extrato.conciliacao_status === 'unreconciled') {
      return extrato.conciliacao_status;
    }

    return extrato.conciliado ? 'reconciled' : 'unreconciled';
  };
  const getStatementReconciledAmount = (extrato: ExtratoBancario) => {
    if (extrato.valor_conciliado !== null && extrato.valor_conciliado !== undefined) {
      return Math.abs(toNumber(extrato.valor_conciliado, 0));
    }

    return getStatementStatus(extrato) === 'reconciled'
      ? Math.abs(toNumber(extrato.valor, 0))
      : 0;
  };
  const getStatementRemainingAmount = (extrato: ExtratoBancario) => {
    if (extrato.valor_por_conciliar !== null && extrato.valor_por_conciliar !== undefined) {
      return Math.abs(toNumber(extrato.valor_por_conciliar, 0));
    }

    return getStatementStatus(extrato) === 'reconciled'
      ? 0
      : Math.abs(toNumber(extrato.valor, 0));
  };
  const isStatementFullyReconciled = (extrato: ExtratoBancario) => {
    return getStatementStatus(extrato) === 'reconciled'
      || getStatementRemainingAmount(extrato) <= 0.009
      || extrato.conciliado === true;
  };
  const hasStatementReconciliation = (extrato: ExtratoBancario) => {
    return isStatementFullyReconciled(extrato)
      || getStatementStatus(extrato) === 'partial'
      || getStatementReconciledAmount(extrato) > 0.009;
  };
  const formatSuggestionInvoicePeriod = (invoice?: Fatura | null) => {
    if (!invoice) return null;

    if (invoice.mes) {
      const monthDate = new Date(`${invoice.mes}-01T00:00:00`);
      if (!Number.isNaN(monthDate.getTime())) {
        return `mes ${new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(monthDate)}`;
      }
    }

    if (invoice.data_emissao) {
      const emissionDate = new Date(invoice.data_emissao);
      if (!Number.isNaN(emissionDate.getTime())) {
        return `emitida em ${format(emissionDate, 'dd/MM/yyyy')}`;
      }
    }

    return null;
  };
  const parsePtNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value !== 'string') return fallback;
    const cleaned = value.replace(/\s/g, '').replace(/[^\d.,-]/g, '');
    if (!cleaned) return fallback;
    const hasComma = cleaned.includes(',');
    const hasDot = cleaned.includes('.');

    let normalized = cleaned;
    if (hasComma && hasDot) {
      normalized = cleaned.replace(/\./g, '').replace(',', '.');
    } else if (hasComma) {
      normalized = cleaned.replace(/,/g, '.');
    }

    const parsed = parseFloat(normalized);
    return Number.isNaN(parsed) ? fallback : parsed;
  };
  const getMovementDateKey = (value: string | Date | null | undefined): string => {
    if (!value) return '';
    if (typeof value === 'string') {
      const trimmed = value.trim();
      const ptDateMatch = trimmed.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2}|\d{4})$/);
      if (ptDateMatch) {
        const day = parseInt(ptDateMatch[1], 10);
        const month = parseInt(ptDateMatch[2], 10);
        let year = parseInt(ptDateMatch[3], 10);

        if (year < 100) {
          year += 2000;
        }

        if (day >= 1 && day <= 31 && month >= 1 && month <= 12) {
          return format(new Date(year, month - 1, day), 'yyyy-MM-dd');
        }
      }

      if (/^\d{4}-\d{2}-\d{2}/.test(trimmed)) {
        return trimmed.slice(0, 10);
      }
      const parsed = new Date(trimmed);
      if (!Number.isNaN(parsed.getTime())) {
        return format(parsed, 'yyyy-MM-dd');
      }
      return trimmed;
    }
    return format(value, 'yyyy-MM-dd');
  };
  const [dialogOpen, setDialogOpen] = useState(false);
  const [bankStatementFormError, setBankStatementFormError] = useState<string | null>(null);
  const [dialogCatalogOpen, setDialogCatalogOpen] = useState(false);
  const [dialogEditOpen, setDialogEditOpen] = useState(false);
  const [dialogImportOpen, setDialogImportOpen] = useState(false);
  const [dialogMappingOpen, setDialogMappingOpen] = useState(false);
  const [suggestionsDialogOpen, setSuggestionsDialogOpen] = useState(false);
  const [reconciliationDialogOpen, setReconciliationDialogOpen] = useState(false);
  const [selectedExtrato, setSelectedExtrato] = useState<ExtratoBancario | null>(null);
  const [selectedSuggestionExtrato, setSelectedSuggestionExtrato] = useState<ExtratoBancario | null>(null);
  const [selectedReconciliationExtrato, setSelectedReconciliationExtrato] = useState<ExtratoBancario | null>(null);
  const [editingExtrato, setEditingExtrato] = useState<ExtratoBancario | null>(null);
  const [userPickerOpen, setUserPickerOpen] = useState(false);
  const [userSearchTerm, setUserSearchTerm] = useState('');
  const [extratoSearchTerm, setExtratoSearchTerm] = useState('');
  const [conciliadoFilter, setConciliadoFilter] = useState<string>('all');
  const [conciliacaoItens, setConciliacaoItens] = useState<Array<{ tipo: 'fatura' | 'movimento' | 'financial_entry'; id: string; valor: number }>>([]);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importRawRows, setImportRawRows] = useState<any[][]>([]);
  const [importPreview, setImportPreview] = useState<any[]>([]);
  const [importCentroCusto, setImportCentroCusto] = useState<string>('');
  const [availableColumns, setAvailableColumns] = useState<string[]>([]);
  const [headerRowIndex, setHeaderRowIndex] = useState<number>(0);
  const [dataStartRowIndex, setDataStartRowIndex] = useState<number>(1);
  const [columnMapping, setColumnMapping] = useState<{
    data: string;
    descricao: string;
    valor: string;
    saldo: string;
    conta: string;
    referencia: string;
  }>({
    data: '',
    descricao: '',
    valor: '',
    saldo: '',
    conta: '',
    referencia: '',
  });
  const [reconciliationSuggestions, setReconciliationSuggestions] = useState<BankReconciliationSuggestion[]>([]);
  const [suggestionCache, setSuggestionCache] = useState<Record<string, BankReconciliationSuggestion[]>>({});
  const [suggestionCounts, setSuggestionCounts] = useState<Record<string, number>>({});
  const [suggestionBestScores, setSuggestionBestScores] = useState<Record<string, number>>({});
  const [suggestionsLoading, setSuggestionsLoading] = useState(false);
  const [suggestionActionId, setSuggestionActionId] = useState<string | null>(null);
  const [bulkSuggestionSummary, setBulkSuggestionSummary] = useState<{
    analyzed_count: number;
    suggestions_created: number;
    high_confidence_count: number;
    unmatched_count: number;
    errors: number;
  } | null>(null);
  const [bulkGeneratingSuggestions, setBulkGeneratingSuggestions] = useState(false);

  const getColumnLetter = (index: number) => {
    let letter = '';
    let temp = index + 1;
    while (temp > 0) {
      const rem = (temp - 1) % 26;
      letter = String.fromCharCode(65 + rem) + letter;
      temp = Math.floor((temp - 1) / 26);
    }
    return letter;
  };

  const buildHeaderColumns = (row: any[]) => {
    if (!Array.isArray(row)) return [] as string[];
    return row.map((cell, idx) => {
      const value = cell?.toString?.().trim();
      const label = value ? value : `Coluna ${idx + 1}`;
      return `${getColumnLetter(idx)} - ${label}`;
    });
  };

  const parseSheetRows = async (file: File) => {
    const XLSX = await import('xlsx');
    const buffer = await file.arrayBuffer();
    const previewText = new TextDecoder('latin1', { fatal: false }).decode(buffer.slice(0, 4096));
    const isHtml = previewText.toLowerCase().includes('<html') || previewText.toLowerCase().includes('<table');
    const workbook = isHtml
      ? XLSX.read(new TextDecoder('latin1').decode(buffer), {
          type: 'string',
          cellDates: true,
          raw: true,
        })
      : XLSX.read(buffer, { type: 'array', cellDates: true, raw: true });
    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
    return XLSX.utils.sheet_to_json(firstSheet, { header: 1, raw: true, defval: '' }) as any[];
  };

  const [formData, setFormData] = useState({
    data_movimento: format(new Date(), 'yyyy-MM-dd'),
    descricao: '',
    valor: 0,
    saldo: 0,
    referencia: '',
    centro_custo_id: '',
  });

  const [catalogData, setCatalogData] = useState({
    tipo: 'receita' as 'receita' | 'despesa',
    centro_custo_id: '',
    fatura_id: '',
    user_id: '',
    movimento_id: '',
    financial_entry_id: '',
  });

  const normalizeDateInputValue = (value: string | Date | null | undefined): string => {
    const normalized = getMovementDateKey(value);
    return normalized || format(new Date(), 'yyyy-MM-dd');
  };

  const valorExtrato = selectedExtrato ? Math.abs(toNumber(selectedExtrato.valor)) : 0;
  const totalConciliacao = conciliacaoItens.reduce((sum, item) => sum + toNumber(item.valor), 0);
  const restanteConciliacao = Math.max(0, valorExtrato - totalConciliacao);
  const selectedCatalogUser = (users || []).find((user) => user.id === catalogData.user_id) || null;
  const filteredCatalogUsers = useMemo(() => {
    const term = userSearchTerm.trim().toLowerCase();
    if (!term) {
      return users || [];
    }

    return (users || []).filter((user) => {
      const nome = user.nome_completo?.toLowerCase() || '';
      const numeroSocio = user.numero_socio?.toLowerCase() || '';
      return nome.includes(term) || numeroSocio.includes(term);
    });
  }, [userSearchTerm, users]);

  const toggleConciliacaoItem = (tipo: 'fatura' | 'movimento' | 'financial_entry', id: string, defaultValor: number) => {
    setConciliacaoItens((current) => {
      const exists = current.find((item) => item.tipo === tipo && item.id === id);
      if (exists) {
        return current.filter((item) => !(item.tipo === tipo && item.id === id));
      }
      return [...current, { tipo, id, valor: defaultValor }];
    });
  };

  const updateConciliacaoValor = (tipo: 'fatura' | 'movimento' | 'financial_entry', id: string, valor: number) => {
    setConciliacaoItens((current) =>
      current.map((item) =>
        item.tipo === tipo && item.id === id ? { ...item, valor: valor } : item
      )
    );
  };

  const filteredExtratos = useMemo(() => {
    const normalizedSearch = extratoSearchTerm.trim().toLowerCase();

    return (extratos || []).filter((extrato) => {
      const status = getStatementStatus(extrato);

      const filterMatch = (() => {
        if (conciliadoFilter === 'all') return true;
        if (conciliadoFilter === 'conciliado') return status === 'reconciled';
        if (conciliadoFilter === 'nao-conciliado') return status === 'unreconciled';
        if (conciliadoFilter === 'parcial') return status === 'partial';
        return true;
      })();

      if (!filterMatch) {
        return false;
      }

      if (!normalizedSearch) {
        return true;
      }

      const haystack = [
        extrato.descricao,
        extrato.referencia,
        extrato.conta,
        getCentroCustoName(extrato.centro_custo_id),
        getMovementDateKey(extrato.data_movimento),
      ]
        .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
        .join(' ')
        .toLowerCase();

      return haystack.includes(normalizedSearch);
    });
  }, [extratos, conciliadoFilter, extratoSearchTerm]);

  const extratosTabela = useMemo(() => {
    const sortedAsc = [...filteredExtratos].sort((a, b) => {
      const keyA = getMovementDateKey(a.data_movimento);
      const keyB = getMovementDateKey(b.data_movimento);
      const dateDiff = keyA.localeCompare(keyB);
      if (dateDiff !== 0) return -dateDiff;

      const createdDiff = (b.created_at || '').localeCompare(a.created_at || '');
      if (createdDiff !== 0) return createdDiff;

      return b.id.localeCompare(a.id);
    });

    return sortedAsc;
  }, [filteredExtratos]);

  const sugestoesAutomaticas = useMemo(() => {
    if (!selectedExtrato) return [];

    // Backend now supports persistent bank reconciliation aliases; connect this tab to the aliases endpoints when the dedicated UI is introduced.

    const descricaoLower = selectedExtrato.descricao.toLowerCase();
    const sugestoes: Array<{ tipo: 'fatura' | 'user'; data: any; score: number }> = [];

    (faturas || []).forEach((fatura) => {
      if (fatura.estado_pagamento !== 'vencido') return;
      const user = (users || []).find((u) => u.id === fatura.user_id);
      if (!user) return;

      const nomeLower = user.nome_completo.toLowerCase();
      let score = 0;

      if (descricaoLower.includes(nomeLower)) score += 50;
      if (descricaoLower.includes(user.numero_socio.toLowerCase())) score += 40;
      if (Math.abs(toNumber(fatura.valor_total) - Math.abs(toNumber(selectedExtrato.valor))) < 0.01) score += 30;

      if (score > 0) {
        sugestoes.push({ tipo: 'fatura', data: { fatura, user }, score });
      }
    });

    (users || []).forEach((user) => {
      const nomeLower = user.nome_completo.toLowerCase();
      let score = 0;

      if (descricaoLower.includes(nomeLower)) score += 40;
      if (descricaoLower.includes(user.numero_socio.toLowerCase())) score += 35;

      if (score > 0) {
        sugestoes.push({ tipo: 'user', data: user, score });
      }
    });

    return sugestoes.sort((a, b) => b.score - a.score).slice(0, 5);
  }, [selectedExtrato, faturas, users]);

  const loadSuggestionsForExtrato = async (extrato: ExtratoBancario) => {
    const cachedSuggestions = suggestionCache[extrato.id];

    if (cachedSuggestions) {
      setReconciliationSuggestions(cachedSuggestions);
      return;
    }

    setSuggestionsLoading(true);

    try {
      const response = await fetch(buildRouteUrl('financeiro.bank-reconciliation-suggestions.index', undefined, {
        bank_statement_id: extrato.id,
        status: 'suggested',
        per_page: '25',
      }), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao carregar sugestoes existentes');
      }

      const payload = await response.json();
      const suggestions = Array.isArray(payload?.data) ? payload.data : [];
      applySuggestionsToState(extrato.id, suggestions);
      setReconciliationSuggestions(suggestions);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao carregar sugestoes existentes';
      toast.error(message);
    } finally {
      setSuggestionsLoading(false);
    }
  };

  const loadSuggestionCounts = async () => {
    setSuggestionCounts((current) => {
      const next = { ...current };
      (extratos || []).forEach((extrato) => {
        if (getStatementStatus(extrato) === 'reconciled') {
          next[extrato.id] = 0;
        }
      });
      return next;
    });

    setSuggestionBestScores((current) => {
      const next = { ...current };
      (extratos || []).forEach((extrato) => {
        if (getStatementStatus(extrato) === 'reconciled') {
          next[extrato.id] = 0;
        }
      });
      return next;
    });
  };

  const applySuggestionsToState = (extratoId: string, suggestions: BankReconciliationSuggestion[]) => {
    const bestScore = suggestions.reduce((highest: number, suggestion: BankReconciliationSuggestion) => {
      const score = Number(suggestion.score || 0);
      return score > highest ? score : highest;
    }, 0);

    setSuggestionCache((current) => ({ ...current, [extratoId]: suggestions }));
    setSuggestionCounts((current) => ({ ...current, [extratoId]: suggestions.length }));
    setSuggestionBestScores((current) => ({ ...current, [extratoId]: bestScore }));

    return bestScore;
  };

  const bulkSuggestionsAbortControllerRef = useRef<AbortController | null>(null);

  const requestSuggestionsForExtrato = async (
    extrato: ExtratoBancario,
    options?: { signal?: AbortSignal; forceRegeneration?: boolean },
  ) => {
    const response = await fetch(buildRouteUrl('financeiro.bank-statements.generate-suggestions', extrato.id), {
      method: 'POST',
      headers: buildJsonHeaders(),
      body: JSON.stringify({
        force_regeneration: options?.forceRegeneration === true,
      }),
    });

    if (!response.ok) {
      throw new Error('Erro ao gerar sugestoes');
    }

    const payload = await response.json();
    const suggestions = Array.isArray(payload?.suggestions) ? payload.suggestions : [];
    const bestScore = applySuggestionsToState(extrato.id, suggestions);

    return { suggestions, bestScore };
  };

  const handleCancelBulkSuggestionGeneration = () => {
    bulkSuggestionsAbortControllerRef.current?.abort();
  };

  const handleGenerateSuggestionsBatch = async () => {
    const statementsToAnalyze = extratosTabela.filter(
      (extrato) => getStatementStatus(extrato) !== 'reconciled'
    );

    if (statementsToAnalyze.length === 0) {
      const emptySummary = {
        analyzed_count: 0,
        suggestions_created: 0,
        high_confidence_count: 0,
        unmatched_count: 0,
        errors: 0,
      };

      setBulkSuggestionSummary(emptySummary);
      toast.info('Nao existem linhas bancarias por conciliar para analisar.');
      return;
    }

    setBulkGeneratingSuggestions(true);
    const abortController = new AbortController();
    bulkSuggestionsAbortControllerRef.current = abortController;
    const summary = {
      analyzed_count: 0,
      suggestions_created: 0,
      high_confidence_count: 0,
      unmatched_count: 0,
      errors: 0,
    };
    setBulkSuggestionSummary(summary);

    try {
      let cancelled = false;

      for (const extrato of statementsToAnalyze) {
        if (abortController.signal.aborted) {
          cancelled = true;
          break;
        }

        try {
          const { suggestions } = await requestSuggestionsForExtrato(extrato, { signal: abortController.signal });
          summary.analyzed_count += 1;
          summary.suggestions_created += suggestions.length;
          summary.high_confidence_count += suggestions.filter((suggestion: BankReconciliationSuggestion) =>
            ['very_high', 'high'].includes(String(suggestion.confidence_label || ''))
          ).length;

          if (suggestions.length === 0) {
            summary.unmatched_count += 1;
          }
        } catch (error) {
          if (error instanceof DOMException && error.name === 'AbortError') {
            cancelled = true;
            break;
          }

          summary.analyzed_count += 1;
          summary.errors += 1;
        }

        setBulkSuggestionSummary({ ...summary });
      }

      if (cancelled) {
        toast.info(`Geracao de sugestoes cancelada apos ${summary.analyzed_count} linha(s) analisadas.`);
      } else {
        toast.success(`Sugestoes geradas para ${summary.analyzed_count} linha(s) bancarias.`);
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao gerar sugestoes de conciliacao';
      toast.error(message);
    } finally {
      bulkSuggestionsAbortControllerRef.current = null;
      setBulkGeneratingSuggestions(false);
    }
  };

  const handleGenerateSuggestions = async (extrato: ExtratoBancario, openDialog = false) => {
    setSuggestionActionId(extrato.id);

    try {
      const { suggestions } = await requestSuggestionsForExtrato(extrato);

      if (openDialog) {
        setSelectedSuggestionExtrato(extrato);
        setReconciliationSuggestions(suggestions);
        setSuggestionsDialogOpen(true);
      }

      toast.success(suggestions.length > 0 ? `${suggestions.length} sugestao(oes) gerada(s)` : 'Nao foram encontradas sugestoes');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao gerar sugestoes';
      toast.error(message);
    } finally {
      setSuggestionActionId(null);
    }
  };

  const handleOpenSuggestions = async (extrato: ExtratoBancario) => {
    setSelectedSuggestionExtrato(extrato);
    setSuggestionsDialogOpen(true);
    await loadSuggestionsForExtrato(extrato);
  };

  const handleConfirmSuggestion = async (suggestion: BankReconciliationSuggestion) => {
    setSuggestionActionId(suggestion.id);

    try {
      const response = await fetch(buildRouteUrl('financeiro.bank-reconciliation-suggestions.confirm', suggestion.id), {
        method: 'POST',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
          create_credit: suggestion.unallocated_amount > 0.009,
        }),
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.message || 'Erro ao confirmar sugestao');
      }

      const payload = await response.json();
      if (selectedSuggestionExtrato) {
        setSuggestionCache((current) => ({ ...current, [selectedSuggestionExtrato.id]: [] }));
        setSuggestionCounts((counts) => ({ ...counts, [selectedSuggestionExtrato.id]: 0 }));
        setSuggestionBestScores((scores) => ({ ...scores, [selectedSuggestionExtrato.id]: 0 }));
      }
      if (selectedSuggestionExtrato) {
        setSuggestionCache((current) => {
          const existing = current[selectedSuggestionExtrato.id] ?? [];
          const updated = existing.filter((item) => item.id !== suggestion.id);
          setReconciliationSuggestions(updated);
          setSuggestionCounts((counts) => ({ ...counts, [selectedSuggestionExtrato.id]: updated.length }));
          setSuggestionBestScores((scores) => ({
            ...scores,
            [selectedSuggestionExtrato.id]: updated.reduce((highest, item) => Math.max(highest, Number(item.score || 0)), 0),
          }));
          return { ...current, [selectedSuggestionExtrato.id]: updated };
        });
      }

      if ((payload?.summary?.new_fiscal_requests || 0) > 0) {
        toast.success('Pagamento conciliado. Pedido fiscal criado para as faturas liquidadas.');
      } else {
        toast.success('Sugestao confirmada com sucesso.');
      }
      refreshFinanceiroData();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao confirmar sugestao';
      toast.error(message);
    } finally {
      setSuggestionActionId(null);
    }
  };

  const handleRejectSuggestion = async (suggestion: BankReconciliationSuggestion) => {
    setSuggestionActionId(suggestion.id);

    try {
      const response = await fetch(buildRouteUrl('financeiro.bank-reconciliation-suggestions.reject', suggestion.id), {
        method: 'POST',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({ reason: 'Rejeitada manualmente na tab Banco' }),
      });

      if (!response.ok) {
        throw new Error('Erro ao rejeitar sugestao');
      }

      if (selectedSuggestionExtrato) {
        setSuggestionCache((current) => {
          const existing = current[selectedSuggestionExtrato.id] ?? [];
          const updated = existing.filter((item) => item.id !== suggestion.id);
          setReconciliationSuggestions(updated);
          setSuggestionCounts((counts) => ({ ...counts, [selectedSuggestionExtrato.id]: updated.length }));
          setSuggestionBestScores((scores) => ({
            ...scores,
            [selectedSuggestionExtrato.id]: updated.reduce((highest, item) => Math.max(highest, Number(item.score || 0)), 0),
          }));
          return { ...current, [selectedSuggestionExtrato.id]: updated };
        });
      }
      toast.success('Sugestao rejeitada.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao rejeitar sugestao';
      toast.error(message);
    } finally {
      setSuggestionActionId(null);
    }
  };

  const openReconciliationDialog = (extrato: ExtratoBancario) => {
    setSelectedReconciliationExtrato(extrato);
    setReconciliationDialogOpen(true);
  };

  const getReconciliationBadge = (extrato: ExtratoBancario) => {
    const statementStatus = getStatementStatus(extrato);

    if (statementStatus === 'reconciled') {
      return <Badge className="text-[10px] md:text-xs whitespace-nowrap bg-green-100 text-green-800">Conciliado</Badge>;
    }

    if (statementStatus === 'partial') {
      return <Badge className="text-[10px] md:text-xs whitespace-nowrap bg-amber-100 text-amber-800">Parcial</Badge>;
    }

    if ((suggestionCounts[extrato.id] || 0) > 0) {
      return <Badge className="text-[10px] md:text-xs whitespace-nowrap bg-blue-100 text-blue-800">Sugestoes encontradas</Badge>;
    }

    return <Badge className="text-[10px] md:text-xs whitespace-nowrap bg-slate-100 text-slate-800">Sem sugestoes</Badge>;
  };

  const getAssociatedMovementId = (extrato: ExtratoBancario): string | null => {
    if (extrato.movement_id) {
      return extrato.movement_id;
    }

    if (extrato.lancamento_id) {
      const lancamentoAssociado = (lancamentos || []).find((lancamento) => lancamento.id === extrato.lancamento_id);
      if (lancamentoAssociado?.origem_tipo === 'movement' && lancamentoAssociado.origem_id) {
        return lancamentoAssociado.origem_id;
      }
    }

    const movementFromOrigin = (movimentos || []).find((movimento) => movimento.origem_tipo === 'bank_statement' && movimento.origem_id === extrato.id);
    return movementFromOrigin?.id || null;
  };

  const getAssociatedMovement = (extrato: ExtratoBancario) => {
    const movementId = getAssociatedMovementId(extrato);
    if (!movementId) {
      return null;
    }

    return (movimentos || []).find((movimento) => movimento.id === movementId) || null;
  };

  const openMovementDetail = (movementId: string) => {
    router.visit(route('financeiro.movimentos.show', movementId));
  };

  const getBestScoreLabel = (extratoId: string) => {
    const score = suggestionBestScores[extratoId] || 0;

    if (score <= 0) return 'Sem score';
    if (score >= 90) return `Muito alta (${score})`;
    if (score >= 75) return `Alta (${score})`;
    if (score >= 55) return `Media (${score})`;

    return `Baixa (${score})`;
  };

  const handleAddExtrato = async () => {
    setBankStatementFormError(null);

    if (!formData.descricao || formData.valor === 0) {
      const message = 'Preencha todos os campos obrigatorios';
      toast.error(message);
      setBankStatementFormError(message);
      return;
    }

    if (!formData.centro_custo_id) {
      const message = 'Selecione um centro de custo';
      toast.error(message);
      setBankStatementFormError(message);
      return;
    }

    const csrfToken = getCsrfToken();

    if (!csrfToken) {
      const message = 'Não foi possível guardar o movimento bancário.';
      toast.error(message);
      setBankStatementFormError(message);
      return;
    }

    try {
      const response = await fetch(route('financeiro.extratos.store'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          conta: '',
          data_movimento: formData.data_movimento,
          descricao: formData.descricao,
          valor: formData.valor,
          referencia: formData.referencia || undefined,
          centro_custo_id: formData.centro_custo_id,
        }),
      });

      if (response.status === 419) {
        const message = 'A sessão expirou. Atualize a página e tente novamente.';
        toast.error(message);
        setBankStatementFormError(message);
        return;
      }

      const data = await response.json().catch(() => null);

      if (response.status === 422) {
        const firstValidationError = Object.values(data?.errors || {})
          .flat()
          .find((value) => typeof value === 'string');

        const validationMessage =
          data?.errors?.extrato?.[0] ||
          firstValidationError ||
          data?.message ||
          'Não foi possível guardar o movimento bancário.';
        toast.error(validationMessage);
        setBankStatementFormError(validationMessage);
        return;
      }

      if (!response.ok) {
        const message = data?.message || 'Não foi possível guardar o movimento bancário.';
        toast.error(message);
        setBankStatementFormError(message);
        return;
      }

      setBankStatementFormError(null);
      setExtratos(data?.extratos || []);
      toast.success('Movimento bancario adicionado');
      setDialogOpen(false);
      resetForm();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível guardar o movimento bancário.';
      toast.error(message);
      setBankStatementFormError(message);
    }
  };

  const handleCatalogar = async () => {
    // Este é o fluxo legado de conciliação que foi descontinuado.
    // O endpoint retorna 410 Gone. Os utilizadores devem usar o fluxo canónico
    // através do componente BankReconciliationDialog.
    toast.error('Fluxo legado de conciliação descontinuado. Use o novo fluxo de alocações de pagamentos.');
    return;
  }

  /**
   * DEPRECATED - Mantido apenas para referência histórica.
   * Este era o fluxo legado de conciliação manual.
   * Substituído pelo fluxo canónico de alocações de pagamentos.
   *
   * @deprecated
   */
  const handleCatalogarLegacy = async () => {
    if (!selectedExtrato) return;

    if (!catalogData.centro_custo_id && conciliacaoItens.length === 0) {
      toast.error('Selecione um centro de custo');
      return;
    }

    if (conciliacaoItens.length > 0) {
      if (totalConciliacao <= 0) {
        toast.error('Selecione itens para conciliar');
        return;
      }
      if (totalConciliacao > valorExtrato) {
        toast.error('O total da conciliacao ultrapassa o valor do extrato');
        return;
      }
      if (totalConciliacao !== valorExtrato) {
        toast.warning('Conciliacao parcial: o extrato ficara pendente para o valor restante.');
      }
    }

    try {
      const response = await fetch(route('financeiro.extratos.conciliar', selectedExtrato.id), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          tipo: catalogData.tipo,
          centro_custo_id: catalogData.centro_custo_id,
          user_id: catalogData.user_id || undefined,
          fatura_id: conciliacaoItens.length === 0 ? catalogData.fatura_id || undefined : undefined,
          movimento_id: conciliacaoItens.length === 0 ? catalogData.movimento_id || undefined : undefined,
          financial_entry_id: conciliacaoItens.length === 0 ? catalogData.financial_entry_id || undefined : undefined,
          itens: conciliacaoItens.length > 0
            ? conciliacaoItens.map((item) => ({
                tipo: item.tipo,
                id: item.id,
                valor: item.valor,
              }))
            : undefined,
        }),
      });

      if (!response.ok) {
        throw new Error('Erro ao catalogar movimento');
      }

      await response.json();

      toast.success('Movimento catalogado e conciliado');
      setDialogCatalogOpen(false);
      setSelectedExtrato(null);
      setConciliacaoItens([]);
      resetCatalogData();
      refreshFinanceiroData();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao catalogar movimento';
      toast.error(message);
    }
  };

  const handleCriarDespesaDoPagamento = async (extrato: ExtratoBancario) => {
    if (!extrato.centro_custo_id) {
      toast.error('A linha bancaria precisa de centro de custo antes de criar a despesa.');
      return;
    }

    try {
      const response = await fetch(route('financeiro.extratos.criar-despesa', extrato.id), {
        method: 'POST',
        headers: buildJsonHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
          centro_custo_id: extrato.centro_custo_id,
          categoria: 'pagamento_bancario',
          tipo: 'servico',
          notes: extrato.descricao,
        }),
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.message || 'Erro ao criar despesa a partir do pagamento');
      }

      toast.success('Despesa criada a partir do pagamento bancario.');
      refreshFinanceiroData();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao criar despesa a partir do pagamento';
      toast.error(message);
    }
  };

  const resetForm = () => {
    setFormData({
      data_movimento: format(new Date(), 'yyyy-MM-dd'),
      descricao: '',
      valor: 0,
      saldo: 0,
      referencia: '',
      centro_custo_id: '',
    });
  };

  const resetCatalogData = () => {
    setCatalogData({
      tipo: 'receita',
      centro_custo_id: '',
      fatura_id: '',
      user_id: '',
      movimento_id: '',
      financial_entry_id: '',
    });
    setUserSearchTerm('');
    setUserPickerOpen(false);
  };

  const handleEditExtrato = async () => {
    if (!editingExtrato) return;

    if (!formData.descricao || formData.valor === 0) {
      toast.error('Preencha todos os campos obrigatorios');
      return;
    }

    if (!formData.centro_custo_id) {
      toast.error('Selecione um centro de custo');
      return;
    }

    try {
      const response = await fetch(route('financeiro.extratos.update', editingExtrato.id), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          data_movimento: formData.data_movimento,
          descricao: formData.descricao,
          valor: formData.valor,
          referencia: formData.referencia || undefined,
          centro_custo_id: formData.centro_custo_id,
        }),
      });

      if (!response.ok) {
        throw new Error('Erro ao atualizar movimento bancario');
      }

      const data = await response.json();
      setExtratos(data.extratos || []);

      toast.success('Movimento atualizado com sucesso');
      setDialogEditOpen(false);
      setEditingExtrato(null);
      resetForm();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao atualizar movimento bancario';
      toast.error(message);
    }
  };

  const openEditDialog = (extrato: ExtratoBancario) => {
    setEditingExtrato(extrato);
    setFormData({
      data_movimento: normalizeDateInputValue(extrato.data_movimento),
      descricao: extrato.descricao,
      valor: toNumber(extrato.valor),
      saldo: toNumber(extrato.saldo),
      referencia: extrato.referencia || '',
      centro_custo_id: extrato.centro_custo_id || '',
    });
    setDialogEditOpen(true);
  };

  const handleDesconciliar = async (extrato: ExtratoBancario) => {
    const confirmed = window.confirm(
      'Esta operacao vai desfazer a conciliacao bancaria, reabrir mensalidades/movimentos associados e remover pedidos fiscais ainda nao emitidos. Pretende continuar?'
    );

    if (!confirmed) {
      return;
    }

    try {
      const response = await fetch(route('financeiro.extratos.desconciliar', extrato.id), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        const message = payload?.errors?.extrato?.[0]
          || payload?.errors?.estado_pagamento?.[0]
          || payload?.message
          || 'Erro ao desconciliar movimento';

        throw new Error(message);
      }

      await response.json();

      toast.success('Movimento desconciliado com sucesso');
      refreshFinanceiroData();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao desconciliar movimento';
      toast.error(message);
    }
  };

  const handleDeleteExtrato = async (extrato: ExtratoBancario) => {
    if (getStatementStatus(extrato) !== 'unreconciled') {
      toast.error('Nao e possivel apagar um movimento conciliado. Desconcilie primeiro.');
      return;
    }

    try {
      const response = await fetch(route('financeiro.extratos.destroy', extrato.id), {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Erro ao apagar movimento');
      }

      const data = await response.json();
      setExtratos(data.extratos || []);
      toast.success('Movimento apagado com sucesso');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro ao apagar movimento';
      toast.error(message);
    }
  };

  function getCentroCustoName(id?: string) {
    if (!id) return '-';
    const cc = (centrosCusto || []).find((c) => c.id === id);
    return cc ? cc.nome : '-';
  }

  const totalConciliado = useMemo(() => {
    return (extratos || []).reduce((sum, extrato) => sum + getStatementReconciledAmount(extrato), 0);
  }, [extratos]);

  const totalNaoConciliado = useMemo(() => {
    return (extratos || []).reduce((sum, extrato) => sum + getStatementRemainingAmount(extrato), 0);
  }, [extratos]);

  const saldoConta = useMemo(() => {
    return (extratos || []).reduce((sum, e) => sum + toNumber(e.valor), 0);
  }, [extratos]);

  const handleFileSelect = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    const validTypes = [
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.oasis.opendocument.spreadsheet',
      'text/csv',
    ];

    if (!validTypes.includes(file.type) && !file.name.match(/\.(xls|xlsx|ods|csv)$/i)) {
      toast.error('Formato de ficheiro nao suportado. Use XLS, XLSX, ODS ou CSV');
      return;
    }

    setImportFile(file);

    (async () => {
      try {
        const jsonData = await parseSheetRows(file);
        setImportRawRows(jsonData as any[][]);
        setHeaderRowIndex(0);
        setDataStartRowIndex(1);
        const preview = jsonData.slice(0, 10);
        setImportPreview(preview);

        if (jsonData.length > 0) {
          const headers = buildHeaderColumns(jsonData[0] || []);
          setAvailableColumns(headers);
        }
      } catch (error) {
        toast.error('Erro ao ler o ficheiro');
      }
    })();
  };

  const handleHeaderRowChange = (value: string) => {
    const parsed = Number(value);
    const nextIndex = Number.isNaN(parsed) ? 0 : parsed;
    setHeaderRowIndex(nextIndex);
    const headers = buildHeaderColumns(importRawRows[nextIndex] || []);
    setAvailableColumns(headers);
    setDataStartRowIndex(Math.max(nextIndex + 1, dataStartRowIndex));
    setColumnMapping({
      data: '',
      descricao: '',
      valor: '',
      saldo: '',
      conta: '',
      referencia: '',
    });
  };

  const handleDataStartRowChange = (value: string) => {
    const parsed = Number(value);
    const nextIndex = Number.isNaN(parsed) ? headerRowIndex + 1 : parsed;
    setDataStartRowIndex(Math.max(nextIndex, headerRowIndex + 1));
  };

  const handleImport = () => {
    if (!importFile) {
      toast.error('Selecione um ficheiro para importar');
      return;
    }

    if (!importCentroCusto) {
      toast.error('Selecione um centro de custo');
      return;
    }

    (async () => {
      try {
        const rows: any[] = importRawRows.length > 0 ? importRawRows : await parseSheetRows(importFile);
        const headers = buildHeaderColumns(rows[headerRowIndex] || []);
        const dataRows = rows.slice(dataStartRowIndex);
        const jsonData: any[] = dataRows
          .map((row: any[]) => {
            const obj: Record<string, any> = {};
            headers.forEach((header, idx) => {
              if (!header) return;
              obj[header] = row?.[idx];
            });
            return obj;
          })
          .filter((row) => Object.keys(row).length > 0);

        if (jsonData.length === 0) {
          toast.error('O ficheiro esta vazio');
          return;
        }

        const extratosImportados: Array<ExtratoBancario & { _sourceIndex: number }> = [];
        let importedCount = 0;
        let errorCount = 0;

        // Função auxiliar para extrair valor de coluna com fallback automático
        const getColumnValue = (
          row: Record<string, any>,
          field: 'data' | 'descricao' | 'valor' | 'saldo' | 'conta' | 'referencia'
        ) => {
          const mappedColumn = columnMapping?.[field];
          if (mappedColumn && mappedColumn !== '__auto__' && mappedColumn !== '' && row[mappedColumn]) {
            return row[mappedColumn];
          }

          const defaultColumns: Record<string, string[]> = {
            data: ['Data', 'data', 'DATE', 'Data Movimento', 'Data Mov', 'Data do Movimento', 'Data Valor'],
            descricao: [
              'Descricao',
              'descrição',
              'Descricao do Movimento',
              'Descrição do Movimento',
              'DESCRIPTION',
              'Historico',
            ],
            valor: ['Valor', 'valor', 'VALUE', 'Montante', 'montante', 'Debito/Credito', 'Débito/Crédito'],
            saldo: ['Saldo', 'saldo', 'BALANCE'],
            conta: ['Conta', 'conta', 'NIB', 'nib'],
            referencia: ['Referencia', 'referencia', 'REFERENCE', 'Ref'],
          };

          const possibleColumns = defaultColumns[field] || [];
          const normalizedCandidates = possibleColumns.map((col) => col.toLowerCase());
          for (const key of Object.keys(row)) {
            const normalizedKey = key.toLowerCase();
            const stripped = key.includes(' - ')
              ? key.split(' - ').slice(1).join(' - ').toLowerCase()
              : normalizedKey;
            if (normalizedCandidates.includes(normalizedKey) || normalizedCandidates.includes(stripped)) {
              return row[key];
            }
          }
          return null;
        };

        // Função melhorada para parse de datas do Excel
        const parseExcelDate = (value: any): string => {
          if (!value) return format(new Date(), 'yyyy-MM-dd');
          
          try {
            // Valor numérico do Excel (serial date)
            if (typeof value === 'number') {
              // Excel serial date: dias desde 1900-01-01 (com bug do ano 1900)
              const excelEpoch = new Date(1899, 11, 30); // 30 de dezembro de 1899
              const date = new Date(excelEpoch.getTime() + value * 86400000);
              return format(date, 'yyyy-MM-dd');
            }
            
            // Objeto Date do JavaScript
            if (value instanceof Date) {
              return format(value, 'yyyy-MM-dd');
            }
            
            // String de data
            if (typeof value === 'string') {
              const trimmed = value.trim();

              // Formatos comuns PT: dd/mm/yyyy, dd-mm-yyyy
              const ptFormats = [
                /^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/,  // dd/mm/yyyy
                /^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/,   // dd/mm/yy
              ];
              
              for (const regex of ptFormats) {
                const match = trimmed.match(regex);
                if (match) {
                  const day = parseInt(match[1], 10);
                  const month = parseInt(match[2], 10) - 1; // JS months são 0-indexed
                  let year = parseInt(match[3], 10);
                  if (year < 100) year += 2000; // Converte 25 → 2025

                  const parsed = new Date(year, month, day);
                  if (!isNaN(parsed.getTime()) && parsed.getDate() === day && parsed.getMonth() === month) {
                    return format(parsed, 'yyyy-MM-dd');
                  }
                }
              }

              // Formato ISO ou similar
              const normalized = getMovementDateKey(trimmed);
              if (normalized) {
                return normalized;
              }
            }
          } catch (e) {
          }

          return format(new Date(), 'yyyy-MM-dd');
        };

        // Processar todas as linhas
        jsonData.forEach((row, index) => {
          try {
            const data_movimento = getColumnValue(row, 'data');
            const descricao = getColumnValue(row, 'descricao');
            const valor = getColumnValue(row, 'valor');
            const conta = getColumnValue(row, 'conta');
            const referencia = getColumnValue(row, 'referencia');

            if (!descricao) {
              errorCount++;
              return;
            }

            const parsedValor = parsePtNumber(valor);
            const parsedData = parseExcelDate(data_movimento);

            const novoExtrato: ExtratoBancario & { _sourceIndex: number } = {
              id: crypto.randomUUID(),
              conta: conta?.toString() || '',
              data_movimento: parsedData,
              descricao: descricao.toString(),
              valor: parsedValor,
              saldo: 0, // Será calculado após ordenação
              referencia: referencia?.toString(),
              ficheiro_id: importFile?.name,
              centro_custo_id: importCentroCusto,
              conciliado: false,
              created_at: new Date().toISOString(),
              _sourceIndex: index,
            };

            extratosImportados.push(novoExtrato);
            importedCount++;
          } catch (error) {
            errorCount++;
          }
        });

        // Ordenar por data e manter ordem original para a mesma data
        extratosImportados.sort((a, b) => {
          const keyA = getMovementDateKey(a.data_movimento);
          const keyB = getMovementDateKey(b.data_movimento);
          if (keyA !== keyB) {
            return keyA.localeCompare(keyB);
          }
          return a._sourceIndex - b._sourceIndex;
        });

        // Se os saldos da mesma data não ficarem crescentes, inverter o bloco da data
        const computeRunningBalances = (items: Array<ExtratoBancario & { _sourceIndex: number }>) => {
          let running = 0;
          return items.map((item) => {
            running += toNumber(item.valor);
            return running;
          });
        };

        const provisionalSaldos = computeRunningBalances(extratosImportados);
        let blocoInicio = 0;
        while (blocoInicio < extratosImportados.length) {
          let blocoFim = blocoInicio;
          while (blocoFim + 1 < extratosImportados.length) {
            const keyAtual = getMovementDateKey(extratosImportados[blocoFim].data_movimento);
            const keySeguinte = getMovementDateKey(extratosImportados[blocoFim + 1].data_movimento);
            if (keyAtual !== keySeguinte) break;
            blocoFim++;
          }

          const saldosBloco = provisionalSaldos.slice(blocoInicio, blocoFim + 1);
          const crescente = saldosBloco.every((saldo, idx) => idx === 0 || saldo >= saldosBloco[idx - 1]);

          if (!crescente && blocoFim > blocoInicio) {
            const blocoInvertido = extratosImportados.slice(blocoInicio, blocoFim + 1).reverse();
            extratosImportados.splice(blocoInicio, blocoInvertido.length, ...blocoInvertido);
          }

          blocoInicio = blocoFim + 1;
        }

        // Calcular saldos acumulados
        let saldoAcumulado = 0;
        extratosImportados.forEach((extrato, index) => {
          saldoAcumulado += toNumber(extrato.valor);
          extrato.saldo = saldoAcumulado;
        });

        const novosExtratos: ExtratoBancario[] = extratosImportados.map(({ _sourceIndex, ...extrato }) => extrato);

        if (novosExtratos.length > 0) {
          const response = await fetch(route('financeiro.extratos.bulk'), {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              extratos: novosExtratos.map((extrato) => ({
                conta: extrato.conta,
                data_movimento: extrato.data_movimento,
                descricao: extrato.descricao,
                valor: extrato.valor,
                saldo: extrato.saldo,
                referencia: extrato.referencia,
                ficheiro_id: extrato.ficheiro_id,
                centro_custo_id: extrato.centro_custo_id,
              })),
            }),
          });

          if (!response.ok) {
            throw new Error('Erro ao guardar extratos');
          }

          const data = await response.json();
          setExtratos(data.extratos || []);
          toast.success(
            `${importedCount} movimentos importados com sucesso${
              errorCount > 0 ? ` (${errorCount} erros)` : ''
            }`
          );
          setDialogImportOpen(false);
          resetImportData();
        } else {
          toast.error('Nenhum movimento valido encontrado no ficheiro');
        }
      } catch (error) {
        toast.error('Erro ao processar o ficheiro');
      }
    })();
  };

  const resetImportData = () => {
    setImportFile(null);
    setImportRawRows([]);
    setImportPreview([]);
    setImportCentroCusto('');
    setAvailableColumns([]);
    setHeaderRowIndex(0);
    setDataStartRowIndex(1);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="flex w-full flex-col gap-2 md:w-auto md:flex-row md:items-center">
          <Input
            value={extratoSearchTerm}
            onChange={(event) => setExtratoSearchTerm(event.target.value)}
            placeholder="Pesquisar descricao, referencia, conta ou centro de custo"
            className="w-full md:w-[320px]"
          />
          <Select value={conciliadoFilter} onValueChange={setConciliadoFilter}>
            <SelectTrigger className="w-full md:w-[200px]">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todos</SelectItem>
              <SelectItem value="conciliado">Conciliados</SelectItem>
              <SelectItem value="nao-conciliado">Nao Conciliados</SelectItem>
              <SelectItem value="parcial">Parciais</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="flex w-full flex-col gap-2 sm:flex-row md:w-auto">
          <Dialog open={dialogImportOpen} onOpenChange={setDialogImportOpen}>
            <DialogTrigger asChild>
              <Button variant="outline" onClick={resetImportData} className="w-full sm:w-auto">
                <FileArrowUp className="mr-2" />
                Importar Extrato XLS
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>Importar Extrato Bancario</DialogTitle>
                <DialogDescription>
                  Importe movimentos bancarios a partir de um ficheiro Excel, ODS ou CSV
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>Ficheiro do Extrato (XLS, XLSX, CSV) *</Label>
                  <div className="flex gap-2">
                    <Input type="file" accept=".xls,.xlsx,.ods,.csv" onChange={handleFileSelect} className="flex-1" />
                  </div>
                  {importFile && (
                    <p className="text-sm text-muted-foreground">Ficheiro selecionado: {importFile.name}</p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label>Centro de Custo *</Label>
                  <Select value={importCentroCusto} onValueChange={setImportCentroCusto}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecionar centro de custo" />
                    </SelectTrigger>
                    <SelectContent>
                      {(centrosCusto || [])
                        .filter((cc) => cc.ativo)
                        .map((cc) => (
                          <SelectItem key={cc.id} value={cc.id}>
                            {cc.nome} ({cc.tipo})
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>

                {importPreview.length > 0 && (
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <Label>Pre-visualizacao (primeiras 10 linhas)</Label>
                      <Button variant="outline" size="sm" onClick={() => setDialogMappingOpen(true)}>
                        <Gear className="mr-2" size={14} />
                        Configurar Mapeamento
                      </Button>
                    </div>
                    <div className="space-y-2">
                      <Label>Linha de cabecalhos</Label>
                      <Select value={String(headerRowIndex)} onValueChange={handleHeaderRowChange}>
                        <SelectTrigger className="w-[220px]">
                          <SelectValue placeholder="Selecionar linha de cabecalhos" />
                        </SelectTrigger>
                        <SelectContent>
                          {(importPreview || []).map((_, idx) => (
                            <SelectItem key={idx} value={String(idx)}>
                              Linha {idx + 1}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>Linha inicial de dados</Label>
                      <Select value={String(dataStartRowIndex)} onValueChange={handleDataStartRowChange}>
                        <SelectTrigger className="w-[220px]">
                          <SelectValue placeholder="Selecionar linha inicial" />
                        </SelectTrigger>
                        <SelectContent>
                          {(importPreview || []).map((_, idx) => (
                            <SelectItem key={idx} value={String(idx)}>
                              Linha {idx + 1}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <Card className="p-4 overflow-auto max-h-[240px]">
                      <Table>
                        <TableBody>
                          {importPreview.map((row: any[], idx) => (
                            <TableRow key={idx}>
                              <TableCell className="text-xs text-muted-foreground w-16">{idx + 1}</TableCell>
                              {Array.isArray(row) &&
                                row.map((cell, cellIdx) => (
                                  <TableCell key={cellIdx} className="text-xs">
                                    {cell?.toString() || '-'}
                                  </TableCell>
                                ))}
                            </TableRow>
                          ))}
                        </TableBody>
                      </Table>
                    </Card>
                    <p className="text-xs text-muted-foreground">
                      Nota: O sistema ira procurar automaticamente as colunas ou usar o mapeamento configurado
                    </p>
                  </div>
                )}
              </div>
              <DialogFooter>
                <Button
                  variant="outline"
                  onClick={() => {
                    setDialogImportOpen(false);
                    resetImportData();
                  }}
                >
                  Cancelar
                </Button>
                <Button onClick={handleImport}>
                  <FileArrowUp className="mr-2" />
                  Importar
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog
            open={dialogOpen}
            onOpenChange={(open) => {
              setDialogOpen(open);
              if (open) {
                setBankStatementFormError(null);
              }
            }}
          >
            <DialogTrigger asChild>
              <Button
                onClick={() => {
                  resetForm();
                  setBankStatementFormError(null);
                }}
                className="w-full sm:w-auto"
              >
                <Plus className="mr-2" />
                Adicionar Movimento
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Adicionar Movimento Bancario</DialogTitle>
                <DialogDescription>Registe manualmente um movimento bancario</DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Data Movimento *</Label>
                    <Input
                      type="date"
                      value={formData.data_movimento}
                      onChange={(e) => setFormData({ ...formData, data_movimento: e.target.value })}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Valor * (+ receita / - despesa)</Label>
                    <Input
                      type="number"
                      step="0.01"
                      value={formData.valor}
                      onChange={(e) => setFormData({ ...formData, valor: parseFloat(e.target.value) || 0 })}
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label>Descricao *</Label>
                  <Textarea
                    placeholder="Descricao do movimento"
                    value={formData.descricao}
                    onChange={(e) => setFormData({ ...formData, descricao: e.target.value })}
                    rows={2}
                  />
                </div>

                <div className="space-y-2">
                  <Label>Centro de Custo *</Label>
                  <Select
                    value={formData.centro_custo_id}
                    onValueChange={(v) => setFormData({ ...formData, centro_custo_id: v })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Selecionar centro de custo" />
                    </SelectTrigger>
                    <SelectContent>
                      {(centrosCusto || [])
                        .filter((cc) => cc.ativo)
                        .map((cc) => (
                          <SelectItem key={cc.id} value={cc.id}>
                            {cc.nome} ({cc.tipo})
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Saldo Resultante</Label>
                    <Input
                      type="number"
                      step="0.01"
                      value={formData.saldo}
                      readOnly
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Referencia</Label>
                    <Input
                      placeholder="Ref. do movimento"
                      value={formData.referencia}
                      onChange={(e) => setFormData({ ...formData, referencia: e.target.value })}
                    />
                  </div>
                </div>
              </div>
              {bankStatementFormError && (
                <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {bankStatementFormError}
                </div>
              )}
              <DialogFooter>
                <Button variant="outline" onClick={() => setDialogOpen(false)}>
                  Cancelar
                </Button>
                <Button onClick={handleAddExtrato}>Adicionar</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      <div className="grid gap-3 md:gap-4 grid-cols-2 md:grid-cols-4">
        <Card className="p-3 md:p-4">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-[10px] md:text-xs text-muted-foreground font-medium">Total Movimentos</p>
              <p className="text-xl md:text-2xl font-bold mt-1">{(extratos || []).length}</p>
            </div>
            <div className="p-1.5 md:p-2 rounded-lg bg-blue-50">
              <Bank className="text-blue-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-3 md:p-4">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-[10px] md:text-xs text-muted-foreground font-medium">Conciliados</p>
              <p className="text-xl md:text-2xl font-bold text-green-600 mt-1">€{toNumber(totalConciliado).toFixed(2)}</p>
            </div>
            <div className="p-1.5 md:p-2 rounded-lg bg-green-50">
              <Check className="text-green-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-3 md:p-4">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-[10px] md:text-xs text-muted-foreground font-medium">Por Conciliar</p>
              <p className="text-xl md:text-2xl font-bold text-orange-600 mt-1">€{toNumber(totalNaoConciliado).toFixed(2)}</p>
            </div>
            <div className="p-1.5 md:p-2 rounded-lg bg-orange-50">
              <ArrowsLeftRight className="text-orange-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-3 md:p-4">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-[10px] md:text-xs text-muted-foreground font-medium">Saldo da Conta</p>
              <p className={`text-xl md:text-2xl font-bold mt-1 ${saldoConta >= 0 ? 'text-blue-600' : 'text-red-600'}`}>
                €{toNumber(saldoConta).toFixed(2)}
              </p>
            </div>
            <div className={`p-1.5 md:p-2 rounded-lg ${saldoConta >= 0 ? 'bg-blue-50' : 'bg-red-50'}`}>
              <Bank className={saldoConta >= 0 ? 'text-blue-600' : 'text-red-600'} size={16} weight="bold" />
            </div>
          </div>
        </Card>
      </div>

      <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 md:flex-row md:items-center md:justify-between">
        <div>
          <p className="text-sm font-medium">Sugestoes de conciliacao</p>
          <p className="text-xs text-muted-foreground">
            Analisa linhas nao conciliadas e cria propostas assistidas com score de confianca.
          </p>
          {bulkSuggestionSummary && (
            <p className="mt-1 text-xs text-muted-foreground">
              {bulkSuggestionSummary.analyzed_count} analisadas, {bulkSuggestionSummary.suggestions_created} sugestoes, {bulkSuggestionSummary.high_confidence_count} de alta confianca, {bulkSuggestionSummary.unmatched_count} sem correspondencia, {bulkSuggestionSummary.errors} erros.
            </p>
          )}
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Button type="button" onClick={() => void handleGenerateSuggestionsBatch()} disabled={bulkGeneratingSuggestions}>
            <Gear size={16} className="mr-2" />
            {bulkGeneratingSuggestions ? 'A gerar sugestoes...' : 'Gerar sugestoes de conciliacao'}
          </Button>
          {bulkGeneratingSuggestions && (
            <Button type="button" variant="outline" onClick={handleCancelBulkSuggestionGeneration}>
              <X size={16} className="mr-2" />
              Cancelar
            </Button>
          )}
        </div>
      </div>

      <Card className="overflow-hidden">
        <div className="space-y-3 p-3 md:hidden">
          {extratosTabela.length === 0 ? (
            <div className="py-8 text-center text-sm text-muted-foreground">Nenhum movimento encontrado</div>
          ) : (
            extratosTabela.map((extrato) => (
              <Card key={extrato.id} className="p-3">
                {(() => {
                  const statementStatus = getStatementStatus(extrato);
                  const reconciledAmount = getStatementReconciledAmount(extrato);
                  const remainingAmount = getStatementRemainingAmount(extrato);
                  const fullyReconciled = isStatementFullyReconciled(extrato);
                  const canUnreconcile = hasStatementReconciliation(extrato);
                  const associatedMovement = getAssociatedMovement(extrato);
                  const associatedMovementId = getAssociatedMovementId(extrato);
                  const movementDocumentalState = extrato.movement_estado_documental || associatedMovement?.estado_documental;

                  return (
                <div className="space-y-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="text-sm font-semibold">
                        {format(new Date(extrato.data_movimento), 'dd/MM/yyyy')}
                      </div>
                      <div className="mt-1 break-words text-xs text-muted-foreground">{extrato.descricao}</div>
                      {movementDocumentalState ? (
                        <div className="mt-2 flex flex-wrap gap-1">
                          <MovementDocumentStatusBadge status={movementDocumentalState} className="text-[10px] md:text-xs whitespace-nowrap" />
                        </div>
                      ) : null}
                    </div>
                    {getReconciliationBadge(extrato)}
                  </div>

                  <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <span className="text-muted-foreground">Valor</span>
                    <span className={`text-right font-semibold ${toNumber(extrato.valor) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                      {toNumber(extrato.valor) >= 0 ? '+' : ''}€{toNumber(extrato.valor).toFixed(2)}
                    </span>
                    <span className="text-muted-foreground">Saldo</span>
                    <span className="text-right">€{toNumber(extrato.saldo).toFixed(2)}</span>
                    <span className="text-muted-foreground">Centro Custo</span>
                    <span className="text-right break-words">{getCentroCustoName(extrato.centro_custo_id)}</span>
                    <span className="text-muted-foreground">Conciliado</span>
                    <span className="text-right">€{reconciledAmount.toFixed(2)}</span>
                    <span className="text-muted-foreground">Por conciliar</span>
                    <span className="text-right">€{remainingAmount.toFixed(2)}</span>
                    <span className="text-muted-foreground">Melhor score</span>
                    <span className="text-right">{getBestScoreLabel(extrato.id)}</span>
                  </div>

                  <div className="flex flex-wrap gap-2 pt-1">
                    {associatedMovementId ? (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => openMovementDetail(associatedMovementId)}
                        className="h-8 px-2"
                      >
                        Abrir movimento
                      </Button>
                    ) : null}
                    {!fullyReconciled ? (
                      <>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => void handleGenerateSuggestions(extrato)}
                          className="h-8 px-2"
                          disabled={suggestionActionId === extrato.id}
                        >
                          Gerar sugestoes
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => void handleOpenSuggestions(extrato)}
                          className="h-8 px-2"
                        >
                          Ver sugestoes
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => openReconciliationDialog(extrato)}
                          className="h-8 px-2"
                          title="Abrir conciliacao"
                        >
                          Conciliar
                        </Button>
                        {!associatedMovementId ? (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => void handleCriarDespesaDoPagamento(extrato)}
                            className="h-8 px-2"
                          >
                            Criar despesa
                          </Button>
                        ) : null}
                      </>
                    ) : null}
                    {canUnreconcile ? (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => void handleDesconciliar(extrato)}
                        className="h-8 px-2"
                      >
                        Desconciliar
                      </Button>
                    ) : null}
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => openEditDialog(extrato)}
                      className="h-8 w-8 p-0"
                      title="Editar"
                      aria-label="Editar"
                    >
                      <PencilSimple size={14} />
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => handleDeleteExtrato(extrato)}
                      className="h-8 w-8 p-0 text-destructive hover:text-destructive"
                      disabled={statementStatus !== 'unreconciled'}
                      title="Apagar"
                      aria-label="Apagar"
                    >
                      <Trash size={14} />
                    </Button>
                  </div>
                </div>
                  );
                })()}
              </Card>
            ))
          )}
        </div>

        <div className="hidden max-h-[400px] overflow-auto md:block">
            <table className="w-full caption-bottom text-sm">
              <TableHeader>
                <TableRow>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Data</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Descricao</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Valor</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Saldo</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Conciliado</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Por Conciliar</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Centro Custo</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Estado</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm whitespace-nowrap">Melhor score</TableHead>
                  <TableHead className="sticky top-0 bg-card z-20 text-xs md:text-sm text-right whitespace-nowrap">Acoes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {extratosTabela.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={10} className="text-center text-muted-foreground py-8">
                      Nenhum movimento encontrado
                    </TableCell>
                  </TableRow>
                ) : (
                  extratosTabela.map((extrato) => (
                    (() => {
                      const statementStatus = getStatementStatus(extrato);
                      const reconciledAmount = getStatementReconciledAmount(extrato);
                      const remainingAmount = getStatementRemainingAmount(extrato);
                      const fullyReconciled = isStatementFullyReconciled(extrato);
                      const canUnreconcile = hasStatementReconciliation(extrato);
                      const associatedMovement = getAssociatedMovement(extrato);
                      const associatedMovementId = getAssociatedMovementId(extrato);
                      const movementDocumentalState = extrato.movement_estado_documental || associatedMovement?.estado_documental;

                      return (
                      <TableRow key={extrato.id}>
                        <TableCell className="text-xs md:text-sm whitespace-nowrap">
                          {format(new Date(extrato.data_movimento), 'dd/MM/yyyy')}
                        </TableCell>
                        <TableCell className="text-xs md:text-sm max-w-[120px] md:max-w-xs truncate">{extrato.descricao}</TableCell>
                        <TableCell className={`text-xs md:text-sm font-semibold whitespace-nowrap ${toNumber(extrato.valor) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                          {toNumber(extrato.valor) >= 0 ? '+' : ''}€{toNumber(extrato.valor).toFixed(2)}
                        </TableCell>
                        <TableCell className="text-xs md:text-sm whitespace-nowrap">
                          €{toNumber(extrato.saldo).toFixed(2)}
                        </TableCell>
                        <TableCell className="text-xs md:text-sm whitespace-nowrap">
                          €{reconciledAmount.toFixed(2)}
                        </TableCell>
                        <TableCell className="text-xs md:text-sm whitespace-nowrap">
                          €{remainingAmount.toFixed(2)}
                        </TableCell>
                        <TableCell className="text-xs md:text-sm max-w-[100px] md:max-w-none truncate">
                          {getCentroCustoName(extrato.centro_custo_id)}
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-col gap-1">
                            {getReconciliationBadge(extrato)}
                            {movementDocumentalState ? <MovementDocumentStatusBadge status={movementDocumentalState} className="text-[10px] md:text-xs whitespace-nowrap" /> : null}
                          </div>
                        </TableCell>
                        <TableCell className="text-xs md:text-sm whitespace-nowrap">
                          {getBestScoreLabel(extrato.id)}
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex flex-wrap gap-1 md:gap-2 justify-end whitespace-nowrap">
                            {associatedMovementId ? (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => openMovementDetail(associatedMovementId)}
                                className="text-[10px] md:text-xs h-7 md:h-8 px-2 md:px-3"
                                title="Abrir ficha do movimento"
                              >
                                Abrir movimento
                              </Button>
                            ) : null}
                            {!fullyReconciled ? (
                              <>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => void handleGenerateSuggestions(extrato)}
                                  className="h-8 w-8 p-0"
                                  disabled={suggestionActionId === extrato.id}
                                  title="Gerar sugestoes de conciliacao"
                                  aria-label="Gerar sugestoes de conciliacao"
                                >
                                  <Sparkles size={14} />
                                </Button>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => void handleOpenSuggestions(extrato)}
                                  className="h-8 w-8 p-0"
                                  title="Ver sugestoes de conciliacao"
                                  aria-label="Ver sugestoes de conciliacao"
                                >
                                  <Eye size={14} />
                                </Button>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => openReconciliationDialog(extrato)}
                                  className="text-[10px] md:text-xs h-7 md:h-8 px-2 md:px-3"
                                  title="Abrir conciliacao"
                                >
                                  Conciliar
                                </Button>
                                {!associatedMovementId ? (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void handleCriarDespesaDoPagamento(extrato)}
                                    className="text-[10px] md:text-xs h-7 md:h-8 px-2 md:px-3"
                                    title="Criar despesa a partir deste pagamento"
                                  >
                                    Criar despesa
                                  </Button>
                                ) : null}
                              </>
                            ) : null}
                            {canUnreconcile ? (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => void handleDesconciliar(extrato)}
                                className="text-[10px] md:text-xs h-7 md:h-8 px-2 md:px-3"
                                title="Desconciliar extrato"
                              >
                                Desconciliar
                              </Button>
                            ) : null}
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => openEditDialog(extrato)}
                              className="h-7 w-7 md:h-8 md:w-8 p-0"
                            >
                              <PencilSimple size={14} />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleDeleteExtrato(extrato)}
                              className="h-7 w-7 md:h-8 md:w-8 p-0 text-destructive hover:text-destructive"
                              disabled={statementStatus !== 'unreconciled'}
                            >
                              <Trash size={14} />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                      );
                    })()
                  ))
                )}
              </TableBody>
            </table>
        </div>
      </Card>

      <Dialog
        open={dialogCatalogOpen}
        onOpenChange={(open) => {
          setDialogCatalogOpen(open);
          if (!open) {
            setSelectedExtrato(null);
            setConciliacaoItens([]);
            resetCatalogData();
          }
        }}
      >
        <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Catalogar Movimento para Conciliacao</DialogTitle>
            <DialogDescription>Associe este movimento bancario a uma fatura ou utilizador do clube</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {selectedExtrato && (
              <Card className="p-4 bg-muted/50">
                <div className="space-y-1">
                  <div className="flex justify-between">
                    <span className="text-sm text-muted-foreground">Data:</span>
                    <span className="font-medium">{format(new Date(selectedExtrato.data_movimento), 'dd/MM/yyyy')}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted-foreground">Descricao:</span>
                    <span className="font-medium max-w-xs truncate">{selectedExtrato.descricao}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted-foreground">Valor:</span>
                    <span className={`font-bold ${selectedExtrato.valor >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                      {toNumber(selectedExtrato.valor) >= 0 ? '+' : ''}€{toNumber(selectedExtrato.valor).toFixed(2)}
                    </span>
                  </div>
                </div>
              </Card>
            )}

            {sugestoesAutomaticas.length > 0 && (
              <div className="space-y-2">
                <Label className="text-sm font-semibold">Sugestoes Automaticas</Label>
                <div className="space-y-2 max-h-[220px] overflow-y-auto">
                  {sugestoesAutomaticas.map((sugestao, idx) => (
                    <Card
                      key={idx}
                      className="p-3 cursor-pointer hover:bg-muted/50"
                      onClick={() => {
                        if (sugestao.tipo === 'fatura') {
                          const valorBase = toNumber(sugestao.data.fatura.valor_total);
                          const defaultValor = Math.min(valorBase, restanteConciliacao > 0 ? restanteConciliacao : valorBase);
                          toggleConciliacaoItem('fatura', sugestao.data.fatura.id, defaultValor);
                          setCatalogData({
                            ...catalogData,
                            fatura_id: sugestao.data.fatura.id,
                            user_id: sugestao.data.user.id,
                            centro_custo_id: sugestao.data.fatura.centro_custo_id || '',
                              financial_entry_id: '',
                          });
                        } else {
                          setCatalogData({
                            ...catalogData,
                            user_id: sugestao.data.id,
                            centro_custo_id: sugestao.data.centro_custo?.[0] || '',
                              financial_entry_id: '',
                          });
                        }
                      }}
                    >
                      <div className="flex items-center justify-between">
                        <div>
                          {sugestao.tipo === 'fatura' ? (
                            <>
                              <p className="font-medium text-sm">{sugestao.data.user.nome_completo}</p>
                              <p className="text-xs text-muted-foreground">
                                Fatura: €{toNumber(sugestao.data.fatura.valor_total).toFixed(2)} - {sugestao.data.fatura.tipo} - {format(new Date(sugestao.data.fatura.data_emissao), 'dd/MM/yyyy')}
                              </p>
                            </>
                          ) : (
                            <>
                              <p className="font-medium text-sm">{sugestao.data.nome_completo}</p>
                              <p className="text-xs text-muted-foreground">{sugestao.data.numero_socio}</p>
                            </>
                          )}
                        </div>
                        <Badge variant="outline">{sugestao.score}% match</Badge>
                      </div>
                    </Card>
                  ))}
                </div>
              </div>
            )}

            <div className="space-y-2">
              <Label>Tipo de Movimento *</Label>
              <Select value={catalogData.tipo} onValueChange={(v) => setCatalogData({ ...catalogData, tipo: v as 'receita' | 'despesa' })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="receita">Receita</SelectItem>
                  <SelectItem value="despesa">Despesa</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Centro de Custo *</Label>
              <Select value={catalogData.centro_custo_id} onValueChange={(v) => setCatalogData({ ...catalogData, centro_custo_id: v })}>
                <SelectTrigger>
                  <SelectValue placeholder="Selecionar centro de custo" />
                </SelectTrigger>
                <SelectContent>
                  {(centrosCusto || [])
                    .filter((cc) => cc.ativo)
                    .map((cc) => (
                      <SelectItem key={cc.id} value={cc.id}>
                        {cc.nome} ({cc.tipo})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Utilizador (opcional)</Label>
              <Popover open={userPickerOpen} onOpenChange={setUserPickerOpen}>
                <PopoverTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={userPickerOpen}
                    className="w-full justify-between font-normal"
                  >
                    <span className="truncate">
                      {selectedCatalogUser
                        ? `${selectedCatalogUser.nome_completo} - ${selectedCatalogUser.numero_socio}`
                        : 'Nenhum'}
                    </span>
                    <span className="ml-2 shrink-0 text-xs text-muted-foreground">Pesquisar</span>
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                  <div className="border-b p-2">
                    <Input
                      value={userSearchTerm}
                      onChange={(e) => setUserSearchTerm(e.target.value)}
                      placeholder="Pesquisar por nome ou numero de socio"
                      className="h-9"
                    />
                  </div>
                  <div className="max-h-60 overflow-y-auto p-1">
                    <button
                      type="button"
                      className={`flex w-full items-center justify-between rounded-sm px-2 py-2 text-left text-sm hover:bg-muted ${!catalogData.user_id ? 'bg-muted' : ''}`}
                      onClick={() => {
                        setCatalogData((current) => ({ ...current, user_id: '' }));
                        setUserPickerOpen(false);
                      }}
                    >
                      <span>Nenhum</span>
                      {!catalogData.user_id ? <Check size={14} /> : null}
                    </button>

                    {filteredCatalogUsers.length === 0 ? (
                      <div className="px-2 py-3 text-sm text-muted-foreground">
                        Nenhum utilizador encontrado.
                      </div>
                    ) : (
                      filteredCatalogUsers.map((user) => {
                        const isSelected = catalogData.user_id === user.id;

                        return (
                          <button
                            key={user.id}
                            type="button"
                            className={`flex w-full items-center justify-between rounded-sm px-2 py-2 text-left text-sm hover:bg-muted ${isSelected ? 'bg-muted' : ''}`}
                            onClick={() => {
                              setCatalogData((current) => ({ ...current, user_id: user.id }));
                              setUserPickerOpen(false);
                            }}
                          >
                            <span className="min-w-0 truncate pr-2">
                              {user.nome_completo} - {user.numero_socio}
                            </span>
                            {isSelected ? <Check size={14} /> : null}
                          </button>
                        );
                      })
                    )}
                  </div>
                </PopoverContent>
              </Popover>
            </div>

            <div className="space-y-2">
              <Label>Faturas a Conciliar</Label>
              <Card className="p-2 max-h-[220px] overflow-y-auto">
                <Table>
                  <TableBody>
                    {(faturas || [])
                      .filter((f) => f.estado_pagamento !== 'cancelado')
                      .map((fatura) => {
                        const user = (users || []).find((u) => u.id === fatura.user_id);
                        const checked = conciliacaoItens.some((i) => i.tipo === 'fatura' && i.id === fatura.id);
                        const valorBase = toNumber(fatura.valor_total);
                        const defaultValor = Math.min(valorBase, restanteConciliacao > 0 ? restanteConciliacao : valorBase);
                        return (
                          <TableRow key={fatura.id}>
                            <TableCell className="w-10">
                              <Checkbox
                                checked={checked}
                                onCheckedChange={() => {
                                  toggleConciliacaoItem('fatura', fatura.id, defaultValor);
                                  setCatalogData((current) => ({
                                    ...current,
                                    user_id: fatura.user_id || current.user_id,
                                    centro_custo_id: fatura.centro_custo_id || current.centro_custo_id,
                                  }));
                                }}
                              />
                            </TableCell>
                            <TableCell className="text-xs">
                              {user?.nome_completo || 'Utilizador'} - €{valorBase.toFixed(2)} ({format(new Date(fatura.data_emissao), 'dd/MM/yyyy')})
                            </TableCell>
                            <TableCell className="w-28">
                              {checked ? (
                                <Input
                                  type="number"
                                  step="0.01"
                                  min="0"
                                  value={conciliacaoItens.find((i) => i.tipo === 'fatura' && i.id === fatura.id)?.valor ?? 0}
                                  onChange={(e) => updateConciliacaoValor('fatura', fatura.id, parseFloat(e.target.value) || 0)}
                                />
                              ) : null}
                            </TableCell>
                          </TableRow>
                        );
                      })}
                  </TableBody>
                </Table>
              </Card>
            </div>

            <div className="space-y-2">
              <Label>Movimentos a Conciliar</Label>
              <Card className="p-2 max-h-[220px] overflow-y-auto">
                <Table>
                  <TableBody>
                    {(movimentos || [])
                      .filter((m) => m.estado_pagamento !== 'cancelado')
                      .map((movimento) => {
                        const nomeDisplay = movimento.user_id
                          ? (users || []).find((u) => u.id === movimento.user_id)?.nome_completo
                          : movimento.nome_manual;
                        const checked = conciliacaoItens.some((i) => i.tipo === 'movimento' && i.id === movimento.id);
                        const valorBase = Math.abs(toNumber(movimento.valor_total));
                        const defaultValor = Math.min(valorBase, restanteConciliacao > 0 ? restanteConciliacao : valorBase);
                        return (
                          <TableRow key={movimento.id}>
                            <TableCell className="w-10">
                              <Checkbox
                                checked={checked}
                                onCheckedChange={() => toggleConciliacaoItem('movimento', movimento.id, defaultValor)}
                              />
                            </TableCell>
                            <TableCell className="text-xs">
                              {nomeDisplay || 'Cliente'} - {movimento.tipo} - €{valorBase.toFixed(2)}
                            </TableCell>
                            <TableCell className="w-28">
                              {checked ? (
                                <Input
                                  type="number"
                                  step="0.01"
                                  min="0"
                                  value={conciliacaoItens.find((i) => i.tipo === 'movimento' && i.id === movimento.id)?.valor ?? 0}
                                  onChange={(e) => updateConciliacaoValor('movimento', movimento.id, parseFloat(e.target.value) || 0)}
                                />
                              ) : null}
                            </TableCell>
                          </TableRow>
                        );
                      })}
                  </TableBody>
                </Table>
              </Card>
            </div>

            <div className="space-y-2">
              <Label>Entradas Financeiras a Conciliar</Label>
              <Card className="p-2 max-h-[220px] overflow-y-auto">
                <Table>
                  <TableBody>
                    {(lancamentos || [])
                      .filter((lancamento) => !lancamento.fatura_id)
                      .filter((lancamento) => {
                        const originType = String(lancamento.origem_tipo || '');
                        if (originType === 'payment_allocation' || originType === 'account_credit') {
                          return false;
                        }

                        return !(originType === 'movement' && Boolean(lancamento.origem_id));
                      })
                      .map((lancamento) => {
                        const nomeDisplay = lancamento.user_id
                          ? (users || []).find((u) => u.id === lancamento.user_id)?.nome_completo
                          : lancamento.descricao;
                        const lancamentoWithAmounts = lancamento as LancamentoFinanceiro & { valor_em_aberto?: number | null };
                        const valorBase = Math.abs(toNumber(lancamentoWithAmounts.valor_em_aberto, Math.abs(toNumber(lancamento.valor))));
                        const checked = conciliacaoItens.some((item) => item.tipo === 'financial_entry' && item.id === lancamento.id);
                        const defaultValor = Math.min(valorBase, restanteConciliacao > 0 ? restanteConciliacao : valorBase);

                        return (
                          <TableRow key={lancamento.id}>
                            <TableCell className="w-10">
                              <Checkbox
                                checked={checked}
                                onCheckedChange={() => {
                                  toggleConciliacaoItem('financial_entry', lancamento.id, defaultValor);
                                  setCatalogData((current) => ({
                                    ...current,
                                    user_id: lancamento.user_id || current.user_id,
                                    centro_custo_id: lancamento.centro_custo_id || current.centro_custo_id,
                                    financial_entry_id: lancamento.id,
                                  }));
                                }}
                              />
                            </TableCell>
                            <TableCell className="text-xs">
                              {nomeDisplay || 'Entrada financeira'} - {lancamento.categoria || lancamento.tipo} - €{valorBase.toFixed(2)}
                            </TableCell>
                            <TableCell className="w-28">
                              {checked ? (
                                <Input
                                  type="number"
                                  step="0.01"
                                  min="0"
                                  value={conciliacaoItens.find((item) => item.tipo === 'financial_entry' && item.id === lancamento.id)?.valor ?? 0}
                                  onChange={(e) => updateConciliacaoValor('financial_entry', lancamento.id, parseFloat(e.target.value) || 0)}
                                />
                              ) : null}
                            </TableCell>
                          </TableRow>
                        );
                      })}
                  </TableBody>
                </Table>
              </Card>
            </div>

            <div className="flex items-center justify-between rounded-lg border p-3 text-sm">
              <span>Total selecionado:</span>
              <span className="font-semibold">€{toNumber(totalConciliacao).toFixed(2)}</span>
            </div>
            <div className="flex items-center justify-between rounded-lg border p-3 text-sm">
              <span>Restante:</span>
              <span className={`font-semibold ${restanteConciliacao > 0 ? 'text-orange-600' : 'text-green-600'}`}>
                €{toNumber(restanteConciliacao).toFixed(2)}
              </span>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setDialogCatalogOpen(false);
                setSelectedExtrato(null);
                setConciliacaoItens([]);
                resetCatalogData();
              }}
            >
              Cancelar
            </Button>
            <Button onClick={handleCatalogar}>
              <Check className="mr-2" />
              Catalogar e Conciliar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={suggestionsDialogOpen}
        onOpenChange={(open) => {
          setSuggestionsDialogOpen(open);
          if (!open) {
            setSelectedSuggestionExtrato(null);
            setReconciliationSuggestions([]);
          }
        }}
      >
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Sugestoes de Conciliacao Assistida</DialogTitle>
            <DialogDescription>Analise o score, a explicacao e confirme ou rejeite as sugestoes para esta linha bancaria.</DialogDescription>
          </DialogHeader>

          {selectedSuggestionExtrato ? (
            <div className="space-y-4">
              <Card className="p-4 bg-muted/40">
                <div className="grid gap-2 md:grid-cols-4 text-sm">
                  <div>
                    <div className="text-muted-foreground text-xs uppercase">Data</div>
                    <div>{format(new Date(selectedSuggestionExtrato.data_movimento), 'dd/MM/yyyy')}</div>
                  </div>
                  <div className="md:col-span-2">
                    <div className="text-muted-foreground text-xs uppercase">Descricao</div>
                    <div>{selectedSuggestionExtrato.descricao}</div>
                  </div>
                  <div>
                    <div className="text-muted-foreground text-xs uppercase">Valor</div>
                    <div className="font-semibold">€{Math.abs(toNumber(selectedSuggestionExtrato.valor)).toFixed(2)}</div>
                  </div>
                  <div>
                    <div className="text-muted-foreground text-xs uppercase">Referencia</div>
                    <div>{selectedSuggestionExtrato.referencia || '-'}</div>
                  </div>
                  <div>
                    <div className="text-muted-foreground text-xs uppercase">Conta</div>
                    <div>{selectedSuggestionExtrato.conta || '-'}</div>
                  </div>
                  <div>
                    <div className="text-muted-foreground text-xs uppercase">Estado</div>
                    <div>{getReconciliationBadge(selectedSuggestionExtrato)}</div>
                  </div>
                </div>
              </Card>

              <div className="flex justify-end">
                <Button variant="outline" onClick={() => void handleGenerateSuggestions(selectedSuggestionExtrato, true)} disabled={suggestionActionId === selectedSuggestionExtrato.id}>
                  Gerar novamente
                </Button>
              </div>

              {suggestionsLoading ? (
                <div className="py-8 text-center text-sm text-muted-foreground">A carregar sugestoes...</div>
              ) : reconciliationSuggestions.length === 0 ? (
                <div className="py-8 text-center text-sm text-muted-foreground">Sem sugestoes para esta linha bancaria.</div>
              ) : (
                <div className="space-y-3">
                  {reconciliationSuggestions
                    .slice()
                    .sort((left, right) => right.score - left.score)
                    .map((suggestion) => (
                      <Card key={suggestion.id} className="p-4 space-y-3">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                          <div>
                            <div className="font-semibold">
                              {suggestion.user?.nome_completo || suggestion.family?.nome || 'Contexto nao identificado'}
                            </div>
                            <div className="text-xs text-muted-foreground mt-1">
                              Score {suggestion.score} · Confianca {suggestion.confidence_label || 'low'}
                            </div>
                          </div>
                          <div className="flex gap-2">
                            <Badge className="bg-blue-100 text-blue-800">{suggestion.score}</Badge>
                            <Badge variant="outline">{suggestion.confidence_label || 'low'}</Badge>
                          </div>
                        </div>

                        <div className="space-y-2 text-sm">
                          {(suggestion.suggested_allocations || []).map((allocation) => {
                            const invoice = (faturas || []).find((item) => item.id === allocation.invoice_id);
                            const invoiceUser = invoice ? (users || []).find((item) => item.id === invoice.user_id) : null;
                            const invoicePeriod = formatSuggestionInvoicePeriod(invoice);

                            return (
                              <div key={`${suggestion.id}-${allocation.invoice_id}`} className="rounded-md border p-3">
                                <div className="font-medium">{invoiceUser?.nome_completo || suggestion.user?.nome_completo || 'Fatura'}</div>
                                <div className="text-xs text-muted-foreground">
                                  {invoice?.tipo || 'fatura'}
                                  {invoicePeriod ? ` · ${invoicePeriod}` : ''}
                                  {' · '}alocar €{toNumber(allocation.amount).toFixed(2)}
                                  {' · '}{allocation.reason || 'sem justificacao adicional'}
                                </div>
                              </div>
                            );
                          })}
                        </div>

                        <div className="grid gap-2 text-xs text-muted-foreground md:grid-cols-3">
                          <div>Total alocado: €{toNumber(suggestion.total_allocated_amount).toFixed(2)}</div>
                          <div>Excedente/credito: €{toNumber(suggestion.unallocated_amount).toFixed(2)}</div>
                          <div>Regras: {(suggestion.matched_rules || []).join(', ') || '-'}</div>
                        </div>

                        <div className="text-sm">{suggestion.explanation || 'Sem explicacao adicional.'}</div>

                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                          <Button variant="outline" onClick={() => void handleRejectSuggestion(suggestion)} disabled={suggestionActionId === suggestion.id}>
                            Rejeitar
                          </Button>
                          <Button onClick={() => void handleConfirmSuggestion(suggestion)} disabled={suggestionActionId === suggestion.id}>
                            Confirmar sugestao
                          </Button>
                        </div>
                      </Card>
                    ))}
                </div>
              )}
            </div>
          ) : null}
        </DialogContent>
      </Dialog>

      <BankStatementReconciliationDialog
        open={reconciliationDialogOpen}
        statement={selectedReconciliationExtrato}
        centrosCusto={centrosCusto}
        buildRouteUrl={buildRouteUrl}
        buildJsonHeaders={buildJsonHeaders}
        onOpenChange={(open) => {
          setReconciliationDialogOpen(open);
          if (!open) {
            setSelectedReconciliationExtrato(null);
          }
        }}
        onCompleted={(statementId) => {
          setSuggestionCache((current) => ({ ...current, [statementId]: [] }));
          setSuggestionCounts((current) => ({ ...current, [statementId]: 0 }));
          setSuggestionBestScores((current) => ({ ...current, [statementId]: 0 }));
          refreshFinanceiroData();
        }}
      />

      <Dialog open={dialogEditOpen} onOpenChange={setDialogEditOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Editar Movimento Bancario</DialogTitle>
            <DialogDescription>Altere os dados do movimento bancario</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Data Movimento *</Label>
                <Input type="date" value={formData.data_movimento} onChange={(e) => setFormData({ ...formData, data_movimento: e.target.value })} />
              </div>

              <div className="space-y-2">
                <Label>Valor * (+ receita / - despesa)</Label>
                <Input type="number" step="0.01" value={formData.valor} onChange={(e) => setFormData({ ...formData, valor: parseFloat(e.target.value) || 0 })} />
              </div>
            </div>

            <div className="space-y-2">
              <Label>Descricao *</Label>
              <Textarea placeholder="Descricao do movimento" value={formData.descricao} onChange={(e) => setFormData({ ...formData, descricao: e.target.value })} rows={2} />
            </div>

            <div className="space-y-2">
              <Label>Centro de Custo *</Label>
              <Select value={formData.centro_custo_id} onValueChange={(v) => setFormData({ ...formData, centro_custo_id: v })}>
                <SelectTrigger>
                  <SelectValue placeholder="Selecionar centro de custo" />
                </SelectTrigger>
                <SelectContent>
                  {(centrosCusto || [])
                    .filter((cc) => cc.ativo)
                    .map((cc) => (
                      <SelectItem key={cc.id} value={cc.id}>
                        {cc.nome} ({cc.tipo})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Saldo Resultante</Label>
                <Input type="number" step="0.01" value={formData.saldo} readOnly />
              </div>

              <div className="space-y-2">
                <Label>Referencia</Label>
                <Input placeholder="Ref. do movimento" value={formData.referencia} onChange={(e) => setFormData({ ...formData, referencia: e.target.value })} />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setDialogEditOpen(false);
                setEditingExtrato(null);
                resetForm();
              }}
            >
              Cancelar
            </Button>
            <Button onClick={handleEditExtrato}>Guardar Alteracoes</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialogMappingOpen} onOpenChange={setDialogMappingOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Configurar Mapeamento de Colunas</DialogTitle>
            <DialogDescription>Defina como as colunas do ficheiro correspondem aos campos do sistema</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Configure como as colunas do seu ficheiro Excel correspondem aos campos do sistema. Deixe em branco para usar a detecao automatica.
            </p>

            <div className="space-y-4">
              <div className="space-y-2">
                <Label>Data *</Label>
                <Select
                  value={columnMapping?.data || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), data: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Data" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Descricao *</Label>
                <Select
                  value={columnMapping?.descricao || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), descricao: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Descricao" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Valor *</Label>
                <Select
                  value={columnMapping?.valor || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), valor: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Valor" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Saldo (opcional)</Label>
                <Select
                  value={columnMapping?.saldo || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), saldo: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Saldo" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Conta (opcional)</Label>
                <Select
                  value={columnMapping?.conta || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), conta: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Conta" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>Referencia (opcional)</Label>
                <Select
                  value={columnMapping?.referencia || '__auto__'}
                  onValueChange={(v) => setColumnMapping((current) => ({ ...(current || {}), referencia: v === '__auto__' ? '' : v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Selecionar coluna para Referencia" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__auto__">Auto-detetar</SelectItem>
                    {availableColumns.map((col) => (
                      <SelectItem key={col} value={col}>
                        {col}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <Card className="p-3 bg-blue-50">
              <p className="text-sm text-blue-900">
                <strong>Dica:</strong> Este mapeamento sera guardado e usado automaticamente nas proximas importacoes.
              </p>
            </Card>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogMappingOpen(false)}>
              Fechar
            </Button>
            <Button
              onClick={() => {
                toast.success('Mapeamento guardado com sucesso');
                setDialogMappingOpen(false);
              }}
            >
              <Check className="mr-2" />
              Guardar Mapeamento
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
