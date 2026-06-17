export interface User {
  id: string;
  numero_socio: string;
  nome_completo: string;
  data_inscricao?: string | null;
  nif?: string | null;
  morada?: string | null;
  tipo_mensalidade?: string | null;
  centro_custo?: string[] | null;
  centro_custo_pesos?: Array<{ id: string; peso: number }>;
  tipo_membro?: string[] | null;
  escalao?: string[] | null;
}

export interface CentroCusto {
  id: string;
  nome: string;
  tipo: 'equipa' | 'departamento' | 'pessoa' | 'projeto';
  descricao?: string | null;
  orcamento?: number | null;
  ativo: boolean;
}

export interface Fatura {
  id: string;
  user_id: string;
  data_fatura: string;
  mes?: string | null;
  data_emissao: string;
  data_vencimento: string;
  valor_total: number;
  valor_pago?: number | null;
  valor_em_aberto?: number | null;
  oculta?: boolean;
  estado_pagamento: 'pendente' | 'pago' | 'vencido' | 'parcial' | 'cancelado';
  data_pagamento?: string | null;
  numero_recibo?: string | null;
  referencia_pagamento?: string | null;
  metodo_pagamento?: string | null;
  centro_custo_id?: string | null;
  tipo: string;
  origem_tipo?: 'evento' | 'stock' | 'patrocinio' | 'manual' | null;
  origem_id?: string | null;
  observacoes?: string | null;
  pagamento_observacoes?: string | null;
  has_fiscal_document_request?: boolean;
  has_registered_fiscal_document?: boolean;
  created_at?: string | null;
}

export interface InvoiceType {
  id: string;
  codigo: string;
  nome: string;
  descricao?: string | null;
  ativo: boolean;
}

export interface PaymentMethod {
  id: string;
  codigo: string;
  nome: string;
  descricao?: string | null;
  requer_linha_bancaria: boolean;
  ativo: boolean;
  ordem: number;
}

export interface FaturaItem {
  id: string;
  fatura_id: string;
  descricao: string;
  valor_unitario: number;
  quantidade: number;
  imposto_percentual: number;
  total_linha: number;
  produto_id?: string | null;
  centro_custo_id?: string | null;
  created_at?: string | null;
}

export interface LancamentoFinanceiro {
  id: string;
  data: string;
  tipo: 'receita' | 'despesa';
  categoria?: string | null;
  descricao: string;
  documento_ref?: string | null;
  valor: number;
  centro_custo_id?: string | null;
  user_id?: string | null;
  fatura_id?: string | null;
  origem_tipo?: 'evento' | 'stock' | 'patrocinio' | 'manual' | null;
  origem_id?: string | null;
  metodo_pagamento?: string | null;
  comprovativo?: string | null;
  created_at?: string | null;
}

export interface ExtratoBancario {
  id: string;
  conta?: string | null;
  data_movimento: string;
  descricao: string;
  valor: number;
  saldo?: number | null;
  referencia?: string | null;
  ficheiro_id?: string | null;
  centro_custo_id?: string | null;
  conciliado: boolean;
  valor_conciliado?: number | null;
  valor_por_conciliar?: number | null;
  conciliacao_status?: 'unreconciled' | 'partial' | 'reconciled' | null;
  lancamento_id?: string | null;
  movement_id?: string | null;
  movement_estado_documental?: 'sem_documentos' | 'falta_fatura' | 'falta_recibo' | 'falta_comprovativo_pagamento' | 'pendente_validacao' | 'completo' | 'inconsistente' | null;
  created_at?: string | null;
}

export interface ConciliacaoMapa {
  id: string;
  extrato_id: string;
  lancamento_id: string;
  fatura_id?: string | null;
  movimento_id?: string | null;
  payment_id?: string | null;
  payment_allocation_id?: string | null;
  bank_reconciliation_suggestion_id?: string | null;
  estado_fatura_anterior?: string | null;
  estado_movimento_anterior?: string | null;
  valor_conciliado?: number | null;
  status?: string | null;
  regra_usada?: string | null;
  score?: number | null;
  metadata?: Record<string, unknown> | null;
}

export interface BankReconciliationSuggestionAllocation {
  invoice_id: string;
  amount: number;
  reason?: string | null;
}

export interface BankReconciliationSuggestion {
  id: string;
  bank_statement_id: string;
  user_id?: string | null;
  family_id?: string | null;
  status: 'suggested' | 'confirmed' | 'rejected' | 'expired';
  score: number;
  confidence_label?: 'very_high' | 'high' | 'medium' | 'low' | null;
  total_bank_amount: number;
  total_allocated_amount: number;
  unallocated_amount: number;
  suggested_allocations?: BankReconciliationSuggestionAllocation[] | null;
  assisted_allocation_context?: BankReconciliationAssistedAllocationContext | null;
  matched_rules?: string[] | null;
  explanation?: string | null;
  rejection_reason?: string | null;
  bank_statement?: ExtratoBancario | null;
  user?: Pick<User, 'id' | 'nome_completo' | 'numero_socio' | 'nif'> | null;
  family?: { id: string; nome: string } | null;
  created_at?: string | null;
  confirmed_at?: string | null;
  rejected_at?: string | null;
}

export interface BankReconciliationAssistedDefaultInvoiceAllocation {
  invoice_id: string;
  amount: number;
}

export interface BankReconciliationAssistedDefaultMovementAllocation {
  movement_id: string;
  amount: number;
  centro_custo_id?: string | null;
}

export interface BankReconciliationAssistedAllocationContext {
  reference_month?: string | null;
  matched_user_id?: string | null;
  matched_family_id?: string | null;
  available_amount: number;
  eligible_invoices: OpenInvoiceListItem[];
  eligible_movements: OpenMovementListItem[];
  can_create_credit: boolean;
  credit_target_type?: 'user' | 'family' | null;
  default_allocations?: {
    invoices?: BankReconciliationAssistedDefaultInvoiceAllocation[];
    movements?: BankReconciliationAssistedDefaultMovementAllocation[];
    credit_amount?: number;
  } | null;
}

export interface ReceiptImportItemCandidate {
  id: string;
  score: number;
  reason?: string | null;
  label: string;
}

export interface ReceiptImportItem {
  id: string;
  batch_id: string;
  user_id?: string | null;
  invoice_id?: string | null;
  bank_statement_id?: string | null;
  status: 'pending_review' | 'matched' | 'needs_user' | 'needs_invoice' | 'duplicate' | 'failed' | 'imported';
  display_status?: 'pending_review' | 'matched' | 'needs_user' | 'needs_invoice' | 'duplicate' | 'failed' | 'ready' | 'imported';
  confidence_score: number;
  file_name: string;
  storage_path: string;
  numero_recibo?: string | null;
  recibo_emitido_em?: string | null;
  valor?: number | null;
  extracted_name?: string | null;
  extracted_nif?: string | null;
  extracted_member_number?: string | null;
  extracted_email?: string | null;
  extracted_period_label?: string | null;
  match_candidates?: {
    users?: ReceiptImportItemCandidate[];
    invoices?: ReceiptImportItemCandidate[];
  } | null;
  failure_reason?: string | null;
  is_ready?: boolean;
  preview_url: string;
  user?: Pick<User, 'id' | 'nome_completo' | 'numero_socio' | 'nif'> | null;
  invoice?: Pick<Fatura, 'id' | 'tipo' | 'mes' | 'valor_total' | 'valor_em_aberto' | 'estado_pagamento' | 'numero_recibo'> | null;
  bank_statement?: ExtratoBancario | null;
}

export interface ReceiptImportBatch {
  id: string;
  source_type: string;
  source_name?: string | null;
  source_path?: string | null;
  status: 'pending_review' | 'processed' | 'committed' | 'failed';
  items_count: number;
  processed_count: number;
  imported_count: number;
  notes?: string | null;
  created_at?: string | null;
  committed_at?: string | null;
  creator?: { id: string; nome_completo: string } | null;
  committer?: { id: string; nome_completo: string } | null;
  items: ReceiptImportItem[];
}

export interface OpenInvoiceListItem {
  id: string;
  user_id: string;
  user_name?: string | null;
  family_id?: string | null;
  family_name?: string | null;
  valor_total: number;
  valor_pago: number;
  valor_em_aberto: number;
  estado_pagamento: Fatura['estado_pagamento'];
  data_fatura?: string | null;
  data_vencimento?: string | null;
  vencimento?: string | null;
  mes?: string | null;
  tipo: string;
  centro_custo_id?: string | null;
  centro_custo_name?: string | null;
}

export interface OpenMovementListItem {
  id: string;
  user_id?: string | null;
  user_name?: string | null;
  family_id?: string | null;
  family_name?: string | null;
  financial_entry_id?: string | null;
  descricao: string;
  tipo: Movimento['tipo'];
  classificacao: Movimento['classificacao'];
  valor_total: number;
  valor_pago: number;
  valor_em_aberto: number;
  estado?: Movimento['estado_pagamento'];
  estado_pagamento: Movimento['estado_pagamento'];
  data?: string | null;
  data_emissao?: string | null;
  data_vencimento?: string | null;
  centro_custo_id?: string | null;
  centro_custo_name?: string | null;
  default_centro_custo_id?: string | null;
  requires_centro_custo: boolean;
}

export interface Movimento {
  id: string;
  user_id?: string | null;
  supplier_id?: string | null;
  nome_manual?: string | null;
  nif_manual?: string | null;
  morada_manual?: string | null;
  classificacao: 'receita' | 'despesa';
  categoria?: string | null;
  data_emissao: string;
  data_vencimento: string;
  valor_total: number;
  estado_pagamento: 'pendente' | 'por_pagar' | 'pago' | 'vencido' | 'parcial' | 'pago_parcial' | 'cancelado';
  estado_conciliacao?: 'nao_conciliado' | 'sugerido' | 'conciliado' | 'divergente' | null;
  estado_documental?: 'sem_documentos' | 'falta_fatura' | 'falta_recibo' | 'falta_comprovativo_pagamento' | 'pendente_validacao' | 'completo' | 'inconsistente' | null;
  document_control_status?: 'not_required' | 'pending_documents' | 'pending_invoice' | 'pending_receipt' | 'pending_payment_proof' | 'complete' | 'inconsistent' | null;
  numero_recibo?: string | null;
  referencia_pagamento?: string | null;
  metodo_pagamento?: string | null;
  comprovativo?: string | null;
  documento_original?: string | null;
  centro_custo_id?: string | null;
  tipo: 'inscricao' | 'material' | 'servico' | 'patrocinio' | 'outro';
  origem_tipo?: 'evento' | 'stock' | 'patrocinio' | 'manual' | 'bank_statement' | null;
  origem_id?: string | null;
  observacoes?: string | null;
  created_at?: string | null;
}

export interface MovimentoFinanceiro extends Movimento {
  movimento_id?: string | null;
  financial_entry_id?: string | null;
  source_kind?: 'movement' | 'financial_entry';
  valor_pago?: number | null;
  valor_em_aberto?: number | null;
  read_only?: boolean;
  descricao_financeira?: string | null;
}

export interface Supplier {
  id: string;
  nome: string;
  nif?: string | null;
  morada?: string | null;
  email?: string | null;
  telefone?: string | null;
  categoria?: string | null;
  ativo?: boolean;
}

export interface MovimentoItem {
  id: string;
  movimento_id: string;
  descricao: string;
  valor_unitario: number;
  quantidade: number;
  imposto_percentual: number;
  total_linha: number;
  produto_id?: string | null;
  centro_custo_id?: string | null;
  fatura_id?: string | null;
  created_at?: string | null;
}

export interface Product {
  id: string;
  nome: string;
  preco: number;
  stock: number;
  stock_minimo?: number | null;
  ativo?: boolean;
}

export interface MonthlyFee {
  id: string;
  designacao: string;
  valor: number;
  age_group_id?: string | null;
}

export interface FinanceDashboardPoint {
  tipo?: string;
  label?: string;
  total?: number;
  mes?: string;
  receitas?: number;
  despesas?: number;
  centro_custo_id?: string;
  centro_custo_nome?: string;
}

export interface FinanceDashboardData {
  total_geral: number;
  receitas_mes: number;
  despesas_mes: number;
  mensalidades_vencidas: number;
  movimentos_pendentes: number;
  alerts?: {
    paid_without_invoice: number;
    paid_without_receipt: number;
    missing_payment_proof: number;
    overdue_unpaid: number;
    amount_mismatch: number;
    stock_without_document: number;
  };
  distribuicao_por_tipo: FinanceDashboardPoint[];
  evolucao_mensal_ultimos_6_meses: FinanceDashboardPoint[];
  receitas_despesas_por_centro_custo: FinanceDashboardPoint[];
}

export interface FinanceReportPeriodItem {
  period_key: string;
  period_label: string;
  receitas: number;
  despesas: number;
  saldo: number;
}

export interface FinanceReportCostCenterItem {
  id?: string | null;
  nome: string;
  tipo: string;
  receitas: number;
  despesas: number;
  saldo: number;
}

export interface FinanceReportAgeGroupItem {
  age_group_id: string;
  age_group: string;
  numero_atletas: number;
  receitas: number;
  total_faturado: number;
  total_pago: number;
  total_pendente: number;
  despesas: number;
  peso_financeiro: number;
}

export interface FinanceReportAthleteItem {
  id: string;
  nome: string;
  numero_socio?: string | null;
  escalao?: string | null;
  valor_pago: number;
  valor_gasto: number;
  peso_financeiro: number;
}

export interface FinanceReportSection<TItem, TTotals = { receitas: number; despesas: number; saldo: number }> {
  available: boolean;
  empty_message: string;
  items: TItem[];
  totals: TTotals;
}

export interface FinanceReportResponse {
  summary: FinanceDashboardData;
  filters: {
    data_inicio?: string | null;
    data_fim?: string | null;
    centro_custo_id?: string | null;
    user_id?: string | null;
    tipo?: 'receita' | 'despesa' | null;
    origem_modulo?: string | null;
    origem_tipo?: string | null;
  };
  reports: {
    period: FinanceReportSection<FinanceReportPeriodItem>;
    cost_centers: FinanceReportSection<FinanceReportCostCenterItem>;
    age_groups: FinanceReportSection<FinanceReportAgeGroupItem, {
      numero_atletas: number;
      receitas: number;
      total_faturado: number;
      total_pago: number;
      total_pendente: number;
      despesas: number;
      peso_financeiro: number;
    }>;
    athletes: FinanceReportSection<FinanceReportAthleteItem, {
      valor_pago: number;
      valor_gasto: number;
      peso_financeiro: number;
    }>;
  };
}

export interface AgeGroup {
  id: string;
  nome: string;
}

export type FiscalDocumentRequestStatus =
  | 'pending'
  | 'in_progress'
  | 'issued'
  | 'error_data'
  | 'cancelled'
  | 'not_applicable'
  | 'api_error';

export type FiscalDocumentRequestDocumentType =
  | 'invoice'
  | 'receipt'
  | 'invoice_receipt'
  | 'credit_note'
  | 'other';

export type FiscalDocumentRequestPriority = 'low' | 'normal' | 'high' | 'urgent';

export interface FiscalDocumentRequestInvoice {
  id: string;
  user_id?: string | null;
  valor_total?: number | string | null;
  estado_pagamento?: string | null;
  numero_recibo?: string | null;
  referencia_pagamento?: string | null;
}

export interface FiscalDocumentRequestRelatedUser {
  id: string;
  name?: string | null;
  nome_completo?: string | null;
  email?: string | null;
  nif?: string | null;
  morada?: string | null;
  codigo_postal?: string | null;
  localidade?: string | null;
}

export interface FiscalDocumentRequestBankStatement {
  id: string;
  data_movimento?: string | null;
  descricao?: string | null;
  referencia?: string | null;
}

export interface FiscalDocumentRequestReconciliation {
  id: string;
  extrato_id?: string | null;
  lancamento_id?: string | null;
  fatura_id?: string | null;
  movimento_id?: string | null;
  valor_conciliado?: number | string | null;
}

export interface FiscalDocumentRequest {
  id: string;
  provider: string;
  document_type: FiscalDocumentRequestDocumentType;
  status: FiscalDocumentRequestStatus;
  priority: FiscalDocumentRequestPriority;
  amount?: number | string | null;
  paid_at?: string | null;
  due_at?: string | null;
  customer_name?: string | null;
  customer_tax_number?: string | null;
  customer_email?: string | null;
  customer_address?: string | null;
  description?: string | null;
  internal_reference?: string | null;
  external_document_number?: string | null;
  external_document_id?: string | null;
  external_document_url?: string | null;
  external_series?: string | null;
  issued_at?: string | null;
  handled_at?: string | null;
  last_error?: string | null;
  notes?: string | null;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
  invoice_id?: string | null;
  bank_statement_id?: string | null;
  mapa_conciliacao_id?: string | null;
  financial_entry_id?: string | null;
  invoice?: FiscalDocumentRequestInvoice | null;
  user?: FiscalDocumentRequestRelatedUser | null;
  bank_statement?: FiscalDocumentRequestBankStatement | null;
  bankStatement?: FiscalDocumentRequestBankStatement | null;
  mapa_conciliacao?: FiscalDocumentRequestReconciliation | null;
  mapaConciliacao?: FiscalDocumentRequestReconciliation | null;
}
