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

export interface OpenInvoiceListItem {
  id: string;
  user_id: string;
  user_name?: string | null;
  valor_total: number;
  valor_pago: number;
  valor_em_aberto: number;
  estado_pagamento: Fatura['estado_pagamento'];
  data_fatura?: string | null;
  vencimento?: string | null;
  mes?: string | null;
  tipo: string;
}

export interface Movimento {
  id: string;
  user_id?: string | null;
  nome_manual?: string | null;
  nif_manual?: string | null;
  morada_manual?: string | null;
  classificacao: 'receita' | 'despesa';
  data_emissao: string;
  data_vencimento: string;
  valor_total: number;
  estado_pagamento: 'pendente' | 'pago' | 'vencido' | 'parcial' | 'cancelado';
  numero_recibo?: string | null;
  referencia_pagamento?: string | null;
  metodo_pagamento?: string | null;
  comprovativo?: string | null;
  documento_original?: string | null;
  centro_custo_id?: string | null;
  tipo: 'inscricao' | 'material' | 'servico' | 'patrocinio' | 'outro';
  origem_tipo?: 'evento' | 'stock' | 'patrocinio' | 'manual' | null;
  origem_id?: string | null;
  observacoes?: string | null;
  created_at?: string | null;
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
