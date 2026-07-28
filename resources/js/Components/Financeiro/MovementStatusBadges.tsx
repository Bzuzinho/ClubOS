import { Badge } from '@/Components/ui/badge';

type PaymentStatus = 'pendente' | 'por_pagar' | 'pago' | 'vencido' | 'parcial' | 'pago_parcial' | 'cancelado' | 'nao_aplicavel';
type DocumentStatus = 'sem_documentos' | 'falta_fatura' | 'falta_recibo' | 'falta_comprovativo_pagamento' | 'pendente_validacao' | 'completo' | 'inconsistente';
type ConciliationStatus = 'nao_conciliado' | 'sugerido' | 'conciliado' | 'divergente';

interface BaseBadgeProps {
  className?: string;
}

interface PaymentBadgeProps extends BaseBadgeProps {
  status?: PaymentStatus | null;
}

interface DocumentBadgeProps extends BaseBadgeProps {
  status?: DocumentStatus | null;
}

interface ConciliationBadgeProps extends BaseBadgeProps {
  status?: ConciliationStatus | null;
}

const neutralBadgeClassName = 'bg-slate-100 text-slate-800';

export function MovementPaymentStatusBadge({ status, className = '' }: PaymentBadgeProps) {
  if (!status) {
    return <Badge className={`${neutralBadgeClassName} ${className}`.trim()}>-</Badge>;
  }

  const variants: Record<PaymentStatus, string> = {
    pendente: 'bg-yellow-100 text-yellow-800',
    por_pagar: 'bg-yellow-100 text-yellow-800',
    pago: 'bg-green-100 text-green-800',
    vencido: 'bg-red-100 text-red-800',
    parcial: 'bg-blue-100 text-blue-800',
    pago_parcial: 'bg-blue-100 text-blue-800',
    cancelado: 'bg-slate-100 text-slate-800',
    nao_aplicavel: 'bg-slate-100 text-slate-700',
  };

  const labels: Record<PaymentStatus, string> = {
    pendente: 'Pendente',
    por_pagar: 'Por pagar',
    pago: 'Pago',
    vencido: 'Vencido',
    parcial: 'Parcial',
    pago_parcial: 'Pago parcial',
    cancelado: 'Cancelado',
    nao_aplicavel: 'Nao aplicavel',
  };

  return <Badge className={`${variants[status]} ${className}`.trim()}>{labels[status]}</Badge>;
}

export function MovementDocumentStatusBadge({ status, className = '' }: DocumentBadgeProps) {
  if (!status) {
    return <Badge className={`${neutralBadgeClassName} ${className}`.trim()}>-</Badge>;
  }

  const variants: Record<DocumentStatus, string> = {
    sem_documentos: 'bg-slate-100 text-slate-800',
    falta_fatura: 'bg-amber-100 text-amber-800',
    falta_recibo: 'bg-orange-100 text-orange-800',
    falta_comprovativo_pagamento: 'bg-yellow-100 text-yellow-800',
    pendente_validacao: 'bg-blue-100 text-blue-800',
    completo: 'bg-green-100 text-green-800',
    inconsistente: 'bg-red-100 text-red-800',
  };

  const labels: Record<DocumentStatus, string> = {
    sem_documentos: 'Sem documentos',
    falta_fatura: 'Falta fatura',
    falta_recibo: 'Falta recibo',
    falta_comprovativo_pagamento: 'Falta comprovativo',
    pendente_validacao: 'Pendente validacao',
    completo: 'Completo',
    inconsistente: 'Inconsistente',
  };

  return <Badge className={`${variants[status]} ${className}`.trim()}>{labels[status]}</Badge>;
}

export function MovementConciliationStatusBadge({ status, className = '' }: ConciliationBadgeProps) {
  if (!status) {
    return <Badge className={`${neutralBadgeClassName} ${className}`.trim()}>-</Badge>;
  }

  const variants: Record<ConciliationStatus, string> = {
    nao_conciliado: 'bg-slate-100 text-slate-800',
    sugerido: 'bg-blue-100 text-blue-800',
    conciliado: 'bg-green-100 text-green-800',
    divergente: 'bg-red-100 text-red-800',
  };

  const labels: Record<ConciliationStatus, string> = {
    nao_conciliado: 'Nao conciliado',
    sugerido: 'Sugerido',
    conciliado: 'Conciliado',
    divergente: 'Divergente',
  };

  return <Badge className={`${variants[status]} ${className}`.trim()}>{labels[status]}</Badge>;
}
