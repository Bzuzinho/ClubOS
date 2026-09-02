import { router } from '@inertiajs/react';

export interface StoreHeroItem {
    id: string;
    titulo_curto?: string | null;
    titulo_principal: string;
    descricao?: string | null;
    texto_botao?: string | null;
    tipo_destino?: 'produto' | 'categoria' | 'url' | 'nenhum' | null;
    produto?: {
        id: string;
        slug: string;
        nome: string;
    } | null;
    categoria?: {
        id: string;
        nome: string;
    } | null;
    url_destino?: string | null;
    imagem_desktop_path?: string | null;
    imagem_tablet_path?: string | null;
    imagem_mobile_path?: string | null;
    cor_fundo?: string | null;
}

export interface StoreCategory {
    id: string;
    codigo?: string | null;
    nome: string;
    contexto?: string | null;
}

export interface StoreVariant {
    id: string;
    nome?: string | null;
    tamanho?: string | null;
    cor?: string | null;
    sku?: string | null;
    preco_extra: number;
    stock_atual: number;
    etiqueta?: string | null;
    ativo?: boolean;
}

export interface StoreProduct {
    id: string;
    categoria_id?: string | null;
    codigo?: string | null;
    nome: string;
    slug: string;
    descricao?: string | null;
    preco: number;
    imagem_principal_path?: string | null;
    ativo: boolean;
    destaque: boolean;
    gere_stock: boolean;
    stock_atual: number;
    stock_minimo?: number | null;
    ordem?: number | null;
    tem_stock_baixo?: boolean;
    categoria?: {
        id: string;
        nome: string;
    } | null;
    variantes: StoreVariant[];
}

export interface StoreProfileOption {
    id: string;
    nome_completo: string;
    is_self: boolean;
}

export interface StoreCartItem {
    id: string;
    quantidade: number;
    preco_unitario: number;
    total_linha: number;
    produto?: {
        id: string;
        nome: string;
        slug: string;
        imagem_principal_path?: string | null;
        stock_atual: number;
    } | null;
    variante?: {
        id: string;
        etiqueta?: string | null;
    } | null;
}

export interface StoreCart {
    id: string | null;
    estado: string;
    observacoes?: string | null;
    items: StoreCartItem[];
    subtotal: number;
    total: number;
    count: number;
}

export interface StoreOrderItem {
    id: string;
    descricao: string;
    quantidade: number;
    preco_unitario: number;
    total_linha: number;
    produto?: {
        id: string;
        slug: string;
        nome: string;
    } | null;
    variante?: {
        id: string;
        etiqueta?: string | null;
    } | null;
}

export interface StoreOrder {
    id: string;
    numero: string;
    estado: 'pendente' | 'aprovado' | 'preparado' | 'entregue' | 'cancelado';
    subtotal: number;
    total: number;
    observacoes?: string | null;
    created_at?: string | null;
    invoice?: {
        id: string;
        estado_pagamento: StorePaymentStatus;
        valor_pago: number;
        valor_em_aberto: number;
    } | null;
    financeiro: {
        estado_pagamento: StorePaymentStatus | 'sem_fatura' | 'nao_aplicavel';
        pagamento_confirmado: boolean;
        valor_pago: number;
        valor_em_aberto: number;
        estado_fiscal: StoreFiscalStatus;
        pedido_fiscal_id?: string | null;
        provider_fiscal?: string | null;
        modo_fiscal: string;
        numero_documento_fiscal?: string | null;
        emitido_em?: string | null;
    };
    user?: {
        id: string;
        nome_completo: string;
    } | null;
    target_user?: {
        id: string;
        nome_completo: string;
    } | null;
    items: StoreOrderItem[];
}

export type StorePaymentStatus = 'pendente' | 'parcial' | 'pago' | 'vencido' | 'cancelado';

export type StoreFiscalStatus =
    | 'aguarda_pagamento'
    | 'pedido_em_falta'
    | 'pendente_emissao'
    | 'em_emissao'
    | 'emitido'
    | 'dados_em_falta'
    | 'erro_provider'
    | 'cancelado'
    | 'nao_aplicavel';

function getCsrfToken(): string {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    return token?.content || '';
}

export function formatStoreCurrency(value: number): string {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 2,
    }).format(value || 0);
}

export function formatStoreDate(value?: string | null): string {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function storeOrderStatusLabel(status: StoreOrder['estado'] | string): string {
    switch (status) {
        case 'pendente':
            return 'Pendente';
        case 'aprovado':
            return 'Aprovado';
        case 'preparado':
            return 'Preparado';
        case 'entregue':
            return 'Entregue';
        case 'cancelado':
            return 'Cancelado';
        default:
            return status;
    }
}

export function storeOrderStatusClass(status: StoreOrder['estado'] | string): string {
    switch (status) {
        case 'pendente':
            return 'border-amber-200 bg-amber-50 text-amber-700';
        case 'aprovado':
            return 'border-sky-200 bg-sky-50 text-sky-700';
        case 'preparado':
            return 'border-indigo-200 bg-indigo-50 text-indigo-700';
        case 'entregue':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        case 'cancelado':
            return 'border-rose-200 bg-rose-50 text-rose-700';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-700';
    }
}

export function storePaymentStatusLabel(status: StoreOrder['financeiro']['estado_pagamento'] | string): string {
    switch (status) {
        case 'pendente':
            return 'Pagamento pendente';
        case 'parcial':
            return 'Pagamento parcial';
        case 'pago':
            return 'Pago';
        case 'vencido':
            return 'Pagamento vencido';
        case 'cancelado':
            return 'Pagamento cancelado';
        case 'sem_fatura':
            return 'Sem fatura';
        case 'nao_aplicavel':
            return 'Sem pagamento';
        default:
            return status;
    }
}

export function storePaymentStatusClass(status: StoreOrder['financeiro']['estado_pagamento'] | string): string {
    switch (status) {
        case 'pago':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        case 'parcial':
            return 'border-sky-200 bg-sky-50 text-sky-700';
        case 'vencido':
        case 'cancelado':
            return 'border-rose-200 bg-rose-50 text-rose-700';
        case 'pendente':
            return 'border-amber-200 bg-amber-50 text-amber-700';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-700';
    }
}

export function storeFiscalStatusLabel(status: StoreFiscalStatus | string): string {
    switch (status) {
        case 'aguarda_pagamento':
            return 'Aguarda pagamento';
        case 'pedido_em_falta':
            return 'Pedido fiscal em falta';
        case 'pendente_emissao':
            return 'Pendente de emissão no Wintouch';
        case 'em_emissao':
            return 'Em emissão no Wintouch';
        case 'emitido':
            return 'Documento fiscal emitido';
        case 'dados_em_falta':
            return 'Dados fiscais em falta';
        case 'erro_provider':
            return 'Erro fiscal';
        case 'cancelado':
            return 'Documento fiscal cancelado';
        case 'nao_aplicavel':
            return 'Não aplicável';
        default:
            return status;
    }
}

export async function storeRequest<T>(url: string, init?: RequestInit): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': getCsrfToken(),
            ...(init?.headers || {}),
        },
        ...init,
    });

    if (!response.ok) {
        let message = 'Pedido inválido.';

        try {
            const payload = await response.json();
            if (payload?.message) {
                message = payload.message;
            }
            if (payload?.errors) {
                const flattened = Object.values(payload.errors).flat().join(' ');
                if (flattened) {
                    message = flattened;
                }
            }
        } catch {
            const fallback = await response.text();
            if (fallback) {
                message = fallback;
            }
        }

        throw new Error(message);
    }

    return response.json() as Promise<T>;
}

export function visitStoreProduct(slug: string): void {
    router.visit(`/loja/produto/${slug}`);
}
