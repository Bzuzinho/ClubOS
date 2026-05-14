import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { MovementConciliationStatusBadge, MovementDocumentStatusBadge, MovementPaymentStatusBadge } from '@/Components/Financeiro/MovementStatusBadges';
import { moduleScrollableContentClass, moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { ArrowLeft, ArrowsClockwise, CheckCircle, Files, LinkBreak, WarningCircle } from '@phosphor-icons/react';
import { toast } from 'sonner';

type MovementStatus = 'pendente' | 'por_pagar' | 'pago' | 'vencido' | 'parcial' | 'pago_parcial' | 'cancelado';
type DocumentStatus = 'pending_validation' | 'valid' | 'rejected' | 'duplicate';
type DocumentType = 'invoice' | 'receipt' | 'invoice_receipt' | 'payment_proof' | 'bank_statement_line' | 'credit_note' | 'other';
type ReconciliationStatus = 'nao_conciliado' | 'sugerido' | 'conciliado' | 'divergente';

interface SupplierOption {
    id: string;
    nome: string;
}

interface MovementDocumentDetail {
    id: string;
    document_type: DocumentType;
    document_number: string | null;
    issue_date: string | null;
    due_date: string | null;
    amount: number | null;
    vat_amount: number | null;
    status: DocumentStatus;
    original_filename: string | null;
    stored_path: string | null;
    file_url: string | null;
    validated_at: string | null;
    validator: { id: string; name: string } | null;
    supplier: { id: string; nome: string } | null;
    notes: string | null;
    created_at: string | null;
}

interface MovementLineDetail {
    id: string;
    descricao: string;
    quantidade: number;
    valor_unitario: number;
    imposto_percentual: number;
    total_linha: number;
    centro_custo: { id: string; nome: string } | null;
}

interface ConciliationDetail {
    bank_statement: {
        id: string;
        data_movimento: string | null;
        descricao: string;
        valor: number;
        referencia: string | null;
        conciliado: boolean;
        conciliacao_status: string | null;
        valor_conciliado: number;
        valor_por_conciliar: number | null;
    } | null;
    reconciliation_map: {
        id: string;
        descricao?: string | null;
        created_at: string | null;
    } | null;
    estado_conciliacao: ReconciliationStatus;
}

interface HistoryEvent {
    type: string;
    label: string;
    at: string;
    details: string | null;
}

interface MovementDetail {
    id: string;
    descricao: string;
    nome_manual: string | null;
    supplier: { id: string; nome: string; nif?: string | null; morada?: string | null } | null;
    classificacao: 'receita' | 'despesa';
    categoria: string | null;
    tipo: string;
    centro_custo: { id: string; nome: string } | null;
    valor_total: number;
    data_emissao: string | null;
    data_vencimento: string | null;
    estado_pagamento: MovementStatus;
    estado_documental: string | null;
    estado_conciliacao: ReconciliationStatus;
    origem_tipo: string | null;
    origem_id: string | null;
    metodo_pagamento: string | null;
    numero_recibo: string | null;
    referencia_pagamento: string | null;
    observacoes: string | null;
    missing_documents: string[];
    items: MovementLineDetail[];
    documents: MovementDocumentDetail[];
    conciliation: ConciliationDetail;
    history: HistoryEvent[];
}

interface AvailableBankStatement {
    id: string;
    data_movimento: string | null;
    descricao: string;
    referencia: string | null;
    valor: number;
    conciliado: boolean;
    conciliacao_status: string | null;
    valor_por_conciliar: number | null;
}

interface FinanceiroShowPageProps {
    movement?: MovementDetail;
    suppliers?: SupplierOption[];
    availableBankStatements?: AvailableBankStatement[];
    canManageDocuments?: boolean;
    invoice?: unknown;
}

const formatCurrency = (value?: number | null) => {
    if (value === null || value === undefined || Number.isNaN(value)) return '-';
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    return new Date(value).toLocaleDateString('pt-PT');
};

const formatDateTime = (value?: string | null) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const getCsrfToken = () => {
    const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
    return token?.content || '';
};

const buildJsonHeaders = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': getCsrfToken(),
});

function documentTypeLabel(type: DocumentType) {
    const labels: Record<DocumentType, string> = {
        invoice: 'Fatura',
        receipt: 'Recibo',
        invoice_receipt: 'Fatura-recibo',
        payment_proof: 'Comprovativo',
        bank_statement_line: 'Linha bancária',
        credit_note: 'Nota de crédito',
        other: 'Outro',
    };
    return labels[type] || type;
}

function documentStatusBadge(status: DocumentStatus) {
    const variants: Record<DocumentStatus, string> = {
        pending_validation: 'bg-blue-100 text-blue-800',
        valid: 'bg-green-100 text-green-800',
        rejected: 'bg-red-100 text-red-800',
        duplicate: 'bg-slate-100 text-slate-800',
    };
    const labels: Record<DocumentStatus, string> = {
        pending_validation: 'Pendente',
        valid: 'Válido',
        rejected: 'Rejeitado',
        duplicate: 'Duplicado',
    };
    return <Badge className={variants[status]}>{labels[status]}</Badge>;
}

export default function FinanceiroShowPage({
    movement,
    suppliers = [],
    availableBankStatements = [],
    canManageDocuments = false,
}: FinanceiroShowPageProps) {
    const [activeTab, setActiveTab] = useState('resumo');
    const [movementState, setMovementState] = useState<MovementDetail | null>(movement ?? null);
    const [statementOptions, setStatementOptions] = useState<AvailableBankStatement[]>(availableBankStatements);
    const [uploadOpen, setUploadOpen] = useState(false);
    const [selectedStatementId, setSelectedStatementId] = useState<string>('');
    const [notes, setNotes] = useState(movement?.observacoes ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [uploadForm, setUploadForm] = useState({
        document_type: 'invoice' as DocumentType,
        document_number: '',
        issue_date: movement?.data_emissao ?? '',
        amount: movement ? Math.abs(movement.valor_total).toFixed(2) : '',
        supplier_id: movement?.supplier?.id ?? 'none',
        notes: '',
        file: null as File | null,
    });

    useEffect(() => {
        setMovementState(movement ?? null);
        setStatementOptions(availableBankStatements);
        setNotes(movement?.observacoes ?? '');
    }, [movement, availableBankStatements]);

    const movementId = movementState?.id;
    const title = movementState?.descricao || 'Movimento financeiro';
    const supplierName = movementState?.supplier?.nome || movementState?.nome_manual || 'Sem fornecedor';

    const summaryCards = useMemo(() => {
        if (!movementState) return [];
        return [
            { label: 'Valor total', value: formatCurrency(movementState.valor_total) },
            { label: 'Emissão', value: formatDate(movementState.data_emissao) },
            { label: 'Vencimento', value: formatDate(movementState.data_vencimento) },
            { label: 'Origem', value: movementState.origem_tipo ? `${movementState.origem_tipo}${movementState.origem_id ? ` · ${movementState.origem_id}` : ''}` : '-' },
        ];
    }, [movementState]);

    const reloadMovement = async () => {
        if (!movementId) return;

        const response = await fetch(route('financeiro.movimentos.show', movementId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Nao foi possivel recarregar a ficha do movimento.');
        }

        const payload = await response.json() as {
            movement: MovementDetail;
            availableBankStatements: AvailableBankStatement[];
        };

        setMovementState(payload.movement);
        setStatementOptions(payload.availableBankStatements || []);
        setNotes(payload.movement.observacoes ?? '');
    };

    const runMutation = async (callback: () => Promise<Response>, successMessage: string) => {
        setSubmitting(true);
        try {
            const response = await callback();
            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                throw new Error(payload?.message || 'Ocorreu um erro ao atualizar o movimento.');
            }

            await reloadMovement();
            toast.success(successMessage);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Ocorreu um erro inesperado.');
        } finally {
            setSubmitting(false);
        }
    };

    const handleUploadDocument = async () => {
        if (!movementId) return;

        const form = new FormData();
        form.append('document_type', uploadForm.document_type);
        if (uploadForm.document_number.trim()) form.append('document_number', uploadForm.document_number.trim());
        if (uploadForm.issue_date) form.append('issue_date', uploadForm.issue_date);
        if (uploadForm.amount) form.append('amount', uploadForm.amount);
        if (uploadForm.notes.trim()) form.append('notes', uploadForm.notes.trim());
        if (uploadForm.supplier_id !== 'none') form.append('supplier_id', uploadForm.supplier_id);
        if (uploadForm.file) form.append('file', uploadForm.file);

        await runMutation(
            () => fetch(route('financeiro.movimentos.documents.store', movementId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: form,
            }),
            'Documento anexado com sucesso.',
        );

        setUploadOpen(false);
        setUploadForm({
            document_type: 'invoice',
            document_number: '',
            issue_date: movementState?.data_emissao ?? '',
            amount: movementState ? Math.abs(movementState.valor_total).toFixed(2) : '',
            supplier_id: movementState?.supplier?.id ?? 'none',
            notes: '',
            file: null,
        });
    };

    const handleDocumentAction = async (documentId: string, action: 'validate' | 'reject' | 'duplicate') => {
        if (!movementId) return;
        const routeName = action === 'validate'
            ? 'financeiro.movimentos.documents.validate'
            : action === 'reject'
                ? 'financeiro.movimentos.documents.reject'
                : 'financeiro.movimentos.documents.duplicate';

        const label = action === 'validate' ? 'Documento validado.' : action === 'reject' ? 'Documento rejeitado.' : 'Documento marcado como duplicado.';

        await runMutation(
            () => fetch(route(routeName, [movementId, documentId]), {
                method: 'PATCH',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({}),
            }),
            label,
        );
    };

    const handleRecalculateDocumentState = async () => {
        if (!movementId) return;

        await runMutation(
            () => fetch(route('financeiro.movimentos.recalculate-document-status', movementId), {
                method: 'PATCH',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({}),
            }),
            'Estado documental recalculado.',
        );
    };

    const handleAssociateBankStatement = async () => {
        if (!movementState || !selectedStatementId) return;

        await runMutation(
            () => fetch(route('financeiro.extratos.conciliar', selectedStatementId), {
                method: 'POST',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    tipo: movementState.classificacao,
                    centro_custo_id: movementState.centro_custo?.id,
                    movimento_id: movementState.id,
                }),
            }),
            'Linha bancária associada ao movimento.',
        );

        setSelectedStatementId('');
    };

    const handleRemoveAssociation = async () => {
        const bankStatementId = movementState?.conciliation.bank_statement?.id;
        if (!bankStatementId) return;

        await runMutation(
            () => fetch(route('financeiro.extratos.desconciliar', bankStatementId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            }),
            'Associação bancária removida.',
        );
    };

    const handleMarkDivergent = async () => {
        if (!movementId) return;

        await runMutation(
            () => fetch(route('financeiro.movimentos.mark-divergent', movementId), {
                method: 'PATCH',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({}),
            }),
            'Movimento marcado como divergente.',
        );
    };

    const handleSaveNotes = async () => {
        if (!movementId) return;

        await runMutation(
            () => fetch(route('financeiro.movimentos.notes.update', movementId), {
                method: 'PATCH',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ observacoes: notes }),
            }),
            'Notas do movimento atualizadas.',
        );
    };

    if (!movementState) {
        return (
            <AuthenticatedLayout
                header={
                    <div>
                        <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Financeiro - Movimento</h1>
                        <p className="text-muted-foreground text-xs mt-0.5">A ficha detalhada está disponível apenas para movimentos financeiros.</p>
                    </div>
                }
            >
                <Head title="Financeiro - Movimento" />
                <div className={moduleViewportClass}>
                    <div className={moduleScrollableContentClass}>
                        <Card className="p-6 text-sm text-muted-foreground">Não foi possível carregar o detalhe do movimento solicitado.</Card>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="space-y-1">
                    <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Ficha do Movimento</h1>
                    <p className="text-muted-foreground text-xs">Consulta e gestão documental, bancária e operacional do movimento financeiro.</p>
                </div>
            }
        >
            <Head title={`Financeiro - ${title}`} />

            <div className={moduleViewportClass}>
                <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
                    <div className="w-full space-y-3">
                        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button variant="outline" size="sm" onClick={() => router.visit(route('financeiro.index'))}>
                                            <ArrowLeft size={16} className="mr-1" />
                                            Voltar
                                        </Button>
                                        <MovementPaymentStatusBadge status={movementState.estado_pagamento} />
                                        <MovementDocumentStatusBadge status={movementState.estado_documental as 'sem_documentos' | 'falta_fatura' | 'falta_recibo' | 'falta_comprovativo_pagamento' | 'pendente_validacao' | 'completo' | 'inconsistente' | null} />
                                        <MovementConciliationStatusBadge status={movementState.estado_conciliacao} />
                                        <Badge variant="outline">{movementState.classificacao}</Badge>
                                    </div>
                                    <div>
                                        <h2 className="text-xl font-semibold tracking-tight">{title}</h2>
                                        <p className="text-sm text-muted-foreground">{supplierName}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                                        <span>Emissão: {formatDate(movementState.data_emissao)}</span>
                                        <span>Vencimento: {formatDate(movementState.data_vencimento)}</span>
                                        <span>Origem: {movementState.origem_tipo ? `${movementState.origem_tipo}${movementState.origem_id ? ` · ${movementState.origem_id}` : ''}` : '-'}</span>
                                    </div>
                                </div>

                                <div className="text-left lg:text-right">
                                    <div className={`text-2xl font-bold ${movementState.valor_total < 0 ? 'text-red-600' : 'text-green-600'}`}>
                                        {formatCurrency(movementState.valor_total)}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Método pagamento: {movementState.metodo_pagamento || '-'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Recibo: {movementState.numero_recibo || '-'}
                                    </div>
                                </div>
                            </div>

                            {movementState.missing_documents.length > 0 && (
                                <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                    <WarningCircle size={18} className="mt-0.5" />
                                    <div>Documentos em falta: {movementState.missing_documents.join(', ')}.</div>
                                </div>
                            )}

                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                {summaryCards.map((item) => (
                                    <Card key={item.label} className="p-3">
                                        <div className="text-xs text-muted-foreground">{item.label}</div>
                                        <div className="mt-1 text-sm font-semibold">{item.value}</div>
                                    </Card>
                                ))}
                            </div>
                        </div>

                        <TabsList className="grid h-auto w-full grid-cols-2 gap-1 p-1 text-[11px] sm:h-9 sm:grid-cols-5 sm:text-xs">
                            <TabsTrigger value="resumo">Resumo</TabsTrigger>
                            <TabsTrigger value="linhas">Linhas</TabsTrigger>
                            <TabsTrigger value="documentos">Documentos</TabsTrigger>
                            <TabsTrigger value="conciliacao">Conciliação</TabsTrigger>
                            <TabsTrigger value="historico">Histórico / Notas</TabsTrigger>
                        </TabsList>
                    </div>

                    <div className={moduleScrollableContentClass}>
                        <TabsContent value="resumo" className={`${moduleTabbedContentClass} space-y-3`}>
                            <div className="grid gap-3 lg:grid-cols-2">
                                <Card className="p-4">
                                    <div className="grid gap-3 sm:grid-cols-2 text-sm">
                                        <div><div className="text-xs text-muted-foreground">Tipo / classificação</div><div className="font-medium">{movementState.tipo} / {movementState.classificacao}</div></div>
                                        <div><div className="text-xs text-muted-foreground">Fornecedor</div><div className="font-medium">{supplierName}</div></div>
                                        <div><div className="text-xs text-muted-foreground">Categoria</div><div className="font-medium">{movementState.categoria || '-'}</div></div>
                                        <div><div className="text-xs text-muted-foreground">Centro de custo</div><div className="font-medium">{movementState.centro_custo?.nome || '-'}</div></div>
                                        <div><div className="text-xs text-muted-foreground">Método pagamento</div><div className="font-medium">{movementState.metodo_pagamento || '-'}</div></div>
                                        <div><div className="text-xs text-muted-foreground">Valor total</div><div className="font-medium">{formatCurrency(movementState.valor_total)}</div></div>
                                    </div>
                                </Card>

                                <Card className="p-4 space-y-3">
                                    <div>
                                        <div className="text-xs text-muted-foreground">Estado de pagamento</div>
                                        <div className="mt-1"><MovementPaymentStatusBadge status={movementState.estado_pagamento} /></div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Estado documental</div>
                                        <div className="mt-1"><MovementDocumentStatusBadge status={movementState.estado_documental as 'sem_documentos' | 'falta_fatura' | 'falta_recibo' | 'falta_comprovativo_pagamento' | 'pendente_validacao' | 'completo' | 'inconsistente' | null} /></div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Estado de conciliação</div>
                                        <div className="mt-1"><MovementConciliationStatusBadge status={movementState.estado_conciliacao} /></div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">Observações</div>
                                        <div className="mt-1 whitespace-pre-wrap text-sm">{movementState.observacoes || '-'}</div>
                                    </div>
                                </Card>
                            </div>
                        </TabsContent>

                        <TabsContent value="linhas" className={`${moduleTabbedContentClass} space-y-3`}>
                            <Card className="p-3">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Descrição</TableHead>
                                            <TableHead>Quantidade</TableHead>
                                            <TableHead>Valor unitário</TableHead>
                                            <TableHead>IVA</TableHead>
                                            <TableHead>Total</TableHead>
                                            <TableHead>Centro custo</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {movementState.items.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="py-6 text-center text-sm text-muted-foreground">Sem linhas registadas.</TableCell>
                                            </TableRow>
                                        ) : movementState.items.map((item) => (
                                            <TableRow key={item.id}>
                                                <TableCell className="font-medium">{item.descricao}</TableCell>
                                                <TableCell>{item.quantidade}</TableCell>
                                                <TableCell>{formatCurrency(item.valor_unitario)}</TableCell>
                                                <TableCell>{item.imposto_percentual}%</TableCell>
                                                <TableCell>{formatCurrency(item.total_linha)}</TableCell>
                                                <TableCell>{item.centro_custo?.nome || '-'}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        </TabsContent>

                        <TabsContent value="documentos" className={`${moduleTabbedContentClass} space-y-3`}>
                            <div className="flex flex-wrap gap-2">
                                {canManageDocuments && (
                                    <Button onClick={() => setUploadOpen(true)}>
                                        <Files size={16} className="mr-1" />
                                        Anexar documento
                                    </Button>
                                )}
                                <Button variant="outline" onClick={() => void handleRecalculateDocumentState()} disabled={!canManageDocuments || submitting}>
                                    <ArrowsClockwise size={16} className="mr-1" />
                                    Recalcular controlo documental
                                </Button>
                            </div>

                            <Card className="p-3">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Número</TableHead>
                                            <TableHead>Data</TableHead>
                                            <TableHead>Valor</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead>Ficheiro</TableHead>
                                            <TableHead>Validação</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {movementState.documents.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={8} className="py-6 text-center text-sm text-muted-foreground">Sem documentos anexados.</TableCell>
                                            </TableRow>
                                        ) : movementState.documents.map((document) => (
                                            <TableRow key={document.id}>
                                                <TableCell>
                                                    <div className="font-medium">{documentTypeLabel(document.document_type)}</div>
                                                    <div className="text-xs text-muted-foreground">{document.supplier?.nome || '-'}</div>
                                                </TableCell>
                                                <TableCell>{document.document_number || '-'}</TableCell>
                                                <TableCell>{formatDate(document.issue_date)}</TableCell>
                                                <TableCell>{formatCurrency(document.amount)}</TableCell>
                                                <TableCell>{documentStatusBadge(document.status)}</TableCell>
                                                <TableCell>
                                                    {document.file_url ? (
                                                        <a href={document.file_url} target="_blank" rel="noreferrer" className="text-sm font-medium text-primary underline-offset-4 hover:underline">
                                                            {document.original_filename || 'Ver documento'}
                                                        </a>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="text-xs">{formatDateTime(document.validated_at)}</div>
                                                    <div className="text-xs text-muted-foreground">{document.validator?.name || '-'}</div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        {canManageDocuments && document.status !== 'valid' && (
                                                            <Button size="sm" variant="outline" onClick={() => void handleDocumentAction(document.id, 'validate')} disabled={submitting}>
                                                                Validar
                                                            </Button>
                                                        )}
                                                        {canManageDocuments && document.status !== 'rejected' && (
                                                            <Button size="sm" variant="outline" onClick={() => void handleDocumentAction(document.id, 'reject')} disabled={submitting}>
                                                                Rejeitar
                                                            </Button>
                                                        )}
                                                        {canManageDocuments && document.status !== 'duplicate' && (
                                                            <Button size="sm" variant="ghost" onClick={() => void handleDocumentAction(document.id, 'duplicate')} disabled={submitting}>
                                                                Duplicado
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        </TabsContent>

                        <TabsContent value="conciliacao" className={`${moduleTabbedContentClass} space-y-3`}>
                            <div className="grid gap-3 lg:grid-cols-[1.3fr_0.7fr]">
                                <Card className="p-4 space-y-3">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <div className="text-sm font-semibold">Linha bancária associada</div>
                                            <div className="text-xs text-muted-foreground">Mapa e estado atual da conciliação.</div>
                                        </div>
                                        <MovementConciliationStatusBadge status={movementState.estado_conciliacao} />
                                    </div>

                                    {movementState.conciliation.bank_statement ? (
                                        <div className="grid gap-3 sm:grid-cols-2 text-sm">
                                            <div><div className="text-xs text-muted-foreground">Data</div><div>{formatDate(movementState.conciliation.bank_statement.data_movimento)}</div></div>
                                            <div><div className="text-xs text-muted-foreground">Valor</div><div>{formatCurrency(movementState.conciliation.bank_statement.valor)}</div></div>
                                            <div className="sm:col-span-2"><div className="text-xs text-muted-foreground">Descrição bancária</div><div>{movementState.conciliation.bank_statement.descricao}</div></div>
                                            <div><div className="text-xs text-muted-foreground">Referência</div><div>{movementState.conciliation.bank_statement.referencia || '-'}</div></div>
                                            <div><div className="text-xs text-muted-foreground">Estado da conciliação</div><div>{movementState.conciliation.bank_statement.conciliacao_status || '-'}</div></div>
                                            <div><div className="text-xs text-muted-foreground">Mapa de conciliação</div><div>{movementState.conciliation.reconciliation_map?.id || '-'}</div></div>
                                            <div><div className="text-xs text-muted-foreground">Criado em</div><div>{formatDateTime(movementState.conciliation.reconciliation_map?.created_at || null)}</div></div>
                                        </div>
                                    ) : (
                                        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">Este movimento ainda não tem linha bancária associada.</div>
                                    )}
                                </Card>

                                <Card className="p-4 space-y-3">
                                    <div className="text-sm font-semibold">Ações de conciliação</div>
                                    <div className="space-y-2">
                                        <Label>Associar linha bancária</Label>
                                        <Select value={selectedStatementId} onValueChange={setSelectedStatementId}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Escolher linha disponível" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {statementOptions.length === 0 ? (
                                                    <SelectItem value="none" disabled>Sem linhas disponíveis</SelectItem>
                                                ) : statementOptions.map((statement) => (
                                                    <SelectItem key={statement.id} value={statement.id}>
                                                        {formatDate(statement.data_movimento)} · {formatCurrency(statement.valor)} · {statement.descricao}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button className="w-full" onClick={() => void handleAssociateBankStatement()} disabled={!canManageDocuments || !selectedStatementId || submitting}>
                                        Associar linha bancária
                                    </Button>
                                    <Button className="w-full" variant="outline" onClick={() => void handleRemoveAssociation()} disabled={!canManageDocuments || !movementState.conciliation.bank_statement || submitting}>
                                        <LinkBreak size={16} className="mr-1" />
                                        Remover associação
                                    </Button>
                                    <Button className="w-full" variant="outline" onClick={() => void handleMarkDivergent()} disabled={!canManageDocuments || submitting}>
                                        Marcar como divergente
                                    </Button>
                                    <Button className="w-full" variant="ghost" onClick={() => void handleRecalculateDocumentState()} disabled={!canManageDocuments || submitting}>
                                        Recalcular estado documental
                                    </Button>
                                </Card>
                            </div>
                        </TabsContent>

                        <TabsContent value="historico" className={`${moduleTabbedContentClass} space-y-3`}>
                            <div className="grid gap-3 lg:grid-cols-[1fr_0.9fr]">
                                <Card className="p-4 space-y-3">
                                    <div className="text-sm font-semibold">Histórico mínimo</div>
                                    {movementState.history.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">Sem eventos adicionais registados.</div>
                                    ) : (
                                        <div className="space-y-3">
                                            {movementState.history.map((event, index) => (
                                                <div key={`${event.type}-${index}`} className="rounded-lg border p-3">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="font-medium text-sm">{event.label}</div>
                                                        <div className="text-xs text-muted-foreground">{formatDateTime(event.at)}</div>
                                                    </div>
                                                    <div className="mt-1 text-sm text-muted-foreground">{event.details || '-'}</div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </Card>

                                <Card className="p-4 space-y-3">
                                    <div className="text-sm font-semibold">Notas do movimento</div>
                                    <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={10} placeholder="Registe notas operacionais, justificações ou contexto adicional." />
                                    <div className="text-xs text-muted-foreground">Ainda não existe tabela de auditoria dedicada; esta área guarda observações úteis no próprio movimento.</div>
                                    <Button onClick={() => void handleSaveNotes()} disabled={!canManageDocuments || submitting}>
                                        <CheckCircle size={16} className="mr-1" />
                                        Guardar notas
                                    </Button>
                                </Card>
                            </div>
                        </TabsContent>
                    </div>
                </Tabs>
            </div>

            <Dialog open={uploadOpen} onOpenChange={setUploadOpen}>
                <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Anexar documento</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-2 sm:col-span-2">
                            <Label>Tipo de documento</Label>
                            <Select value={uploadForm.document_type} onValueChange={(value) => setUploadForm((current) => ({ ...current, document_type: value as DocumentType }))}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="invoice">Fatura</SelectItem>
                                    <SelectItem value="receipt">Recibo</SelectItem>
                                    <SelectItem value="invoice_receipt">Fatura-recibo</SelectItem>
                                    <SelectItem value="payment_proof">Comprovativo</SelectItem>
                                    <SelectItem value="bank_statement_line">Linha bancária</SelectItem>
                                    <SelectItem value="credit_note">Nota de crédito</SelectItem>
                                    <SelectItem value="other">Outro</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Número do documento</Label>
                            <Input value={uploadForm.document_number} onChange={(event) => setUploadForm((current) => ({ ...current, document_number: event.target.value }))} />
                        </div>
                        <div className="space-y-2">
                            <Label>Data</Label>
                            <Input type="date" value={uploadForm.issue_date} onChange={(event) => setUploadForm((current) => ({ ...current, issue_date: event.target.value }))} />
                        </div>
                        <div className="space-y-2">
                            <Label>Valor</Label>
                            <Input type="number" step="0.01" value={uploadForm.amount} onChange={(event) => setUploadForm((current) => ({ ...current, amount: event.target.value }))} />
                        </div>
                        <div className="space-y-2">
                            <Label>Fornecedor</Label>
                            <Select value={uploadForm.supplier_id} onValueChange={(value) => setUploadForm((current) => ({ ...current, supplier_id: value }))}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Sem fornecedor" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Sem fornecedor</SelectItem>
                                    {suppliers.map((supplier) => (
                                        <SelectItem key={supplier.id} value={supplier.id}>{supplier.nome}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label>Ficheiro</Label>
                            <Input type="file" onChange={(event) => setUploadForm((current) => ({ ...current, file: event.target.files?.[0] || null }))} />
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label>Notas</Label>
                            <Textarea value={uploadForm.notes} onChange={(event) => setUploadForm((current) => ({ ...current, notes: event.target.value }))} rows={4} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setUploadOpen(false)}>Cancelar</Button>
                        <Button onClick={() => void handleUploadDocument()} disabled={submitting}>Anexar e recalcular</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
