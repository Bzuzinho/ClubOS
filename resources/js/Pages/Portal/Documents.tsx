import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    Eye,
    FileText,
    Upload,
} from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import PortalLayout from '@/Layouts/PortalLayout';
import { portalRoutes } from '@/lib/portalRoutes';
import type { PageProps as SharedPageProps } from '@/types';

type DocumentStatusKey = 'valid' | 'pending' | 'expiring' | 'expired' | 'in_review';
type DocumentTone = 'success' | 'warning' | 'danger' | 'info' | 'muted';

interface DocumentStatus {
    key: DocumentStatusKey;
    label: string;
    tone: DocumentTone;
}

interface DocumentActions {
    view_url: string | null;
    download_url: string | null;
    upload_type: string;
    can_upload: boolean;
    primary_upload_label: string;
}

interface DocumentCard {
    id: string;
    type: string;
    name: string;
    group: 'essential' | 'other';
    description: string;
    status: DocumentStatus;
    signed_at: string | null;
    valid_until: string | null;
    highlight: string;
    priority: 'high' | 'normal';
    actions: DocumentActions;
}

interface DocumentsOverview {
    hero: {
        title: string;
        headline: string;
        subheadline: string;
        tone: DocumentTone;
        primary_upload_type: string;
    };
    kpis: {
        valid: number;
        expiring: number;
        pending: number;
        season: string;
    };
    documents: DocumentCard[];
    alerts: {
        items: Array<{
            id: string;
            name: string;
            status: string;
            message: string;
            valid_until: string | null;
            is_medical: boolean;
        }>;
        empty_message: string | null;
    };
    history: Array<{
        id: string;
        name: string;
        date: string | null;
        status: string;
    }>;
    notes: {
        family: string;
        settings: string;
    };
    upload: {
        enabled: boolean;
        route: string | null;
        accept: string;
        max_size_mb: number;
    };
}

interface PortalDocumentsProps {
    is_also_admin: boolean;
    has_family: boolean;
    documents_overview: DocumentsOverview;
}

type PageProps = SharedPageProps<PortalDocumentsProps>;

const statusToneClasses: Record<DocumentTone, string> = {
    success: 'bg-emerald-50 text-emerald-700',
    warning: 'bg-amber-50 text-amber-700',
    danger: 'bg-rose-50 text-rose-700',
    info: 'bg-sky-50 text-sky-700',
    muted: 'bg-slate-100 text-slate-600',
};

function openDocument(url: string | null, target: '_self' | '_blank' = '_blank') {
    if (!url) {
        return;
    }

    window.open(url, target, target === '_blank' ? 'noopener,noreferrer' : undefined);
}

function displayValue(value: string | null | undefined, fallback: string): string {
    return value && value.trim() !== '' ? value : fallback;
}

export default function Documents() {
    const { auth, clubSettings, is_also_admin, has_family, documents_overview } = usePage<PageProps>().props;
    const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
    const [selectedUploadType, setSelectedUploadType] = useState(documents_overview.hero.primary_upload_type || 'outro');
    const [withoutExpiry, setWithoutExpiry] = useState(false);
    const uploadForm = useForm<{
        type: string;
        name: string;
        expiry_date: string;
        file: File | null;
    }>({
        type: documents_overview.hero.primary_upload_type || 'outro',
        name: '',
        expiry_date: '',
        file: null,
    });

    const orderedDocuments = useMemo(() => {
        const priority = (document: DocumentCard) => {
            if (document.status.key === 'expired') return 0;
            if (document.status.key === 'expiring') return 1;
            if (document.status.key === 'pending' || document.status.key === 'in_review') return 2;
            return document.group === 'essential' ? 3 : 4;
        };

        return [...documents_overview.documents].sort((left, right) => priority(left) - priority(right));
    }, [documents_overview.documents]);

    const uploadTypeOptions = useMemo(
        () => documents_overview.documents.reduce<Array<{ type: string; name: string }>>((carry, document) => {
            if (!carry.some((option) => option.type === document.type)) {
                carry.push({ type: document.type, name: document.name });
            }

            return carry;
        }, []),
        [documents_overview.documents],
    );

    const resetUploadForm = (type = selectedUploadType) => {
        setSelectedUploadType(type);
        setWithoutExpiry(false);
        uploadForm.reset();
        uploadForm.clearErrors();
        uploadForm.setData({
            type,
            name: '',
            expiry_date: '',
            file: null,
        });
    };

    const openUploadModal = (type = documents_overview.hero.primary_upload_type || 'outro') => {
        resetUploadForm(type);
        setIsUploadModalOpen(true);
    };

    const submitUpload = () => {
        if (!documents_overview.upload.enabled || !documents_overview.upload.route) {
            return;
        }

        uploadForm.transform((data) => ({
            ...data,
            type: selectedUploadType,
            expiry_date: withoutExpiry ? '' : data.expiry_date,
        }));
        uploadForm.post(documents_overview.upload.route, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsUploadModalOpen(false);
                resetUploadForm(selectedUploadType);
            },
            onFinish: () => uploadForm.transform((data) => data),
        });
    };

    const attentionCount = documents_overview.kpis.expiring + documents_overview.kpis.pending;

    return (
        <>
            <Head title="Pagamentos e Documentos" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="documents"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_22px_rgba(15,23,42,0.045)]">
                    <div className="flex items-center gap-3 px-4 pb-3 pt-4 sm:px-5 sm:pt-5">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <FileText className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-lg font-semibold text-slate-900">Pagamentos e Documentos</h1>
                            <p className="mt-0.5 truncate text-xs text-slate-500">Validades, comprovativos e documentos pessoais.</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 border-t border-slate-100 px-2 pt-1">
                        <button
                            type="button"
                            onClick={() => router.visit(portalRoutes.payments)}
                            className="px-3 py-3 text-xs font-semibold text-slate-500 transition hover:text-blue-700"
                        >
                            Pagamentos
                        </button>
                        <button
                            type="button"
                            aria-current="page"
                            className="relative px-3 py-3 text-xs font-semibold text-blue-700"
                        >
                            Documentos
                            <span className="absolute inset-x-5 bottom-0 h-0.5 rounded-full bg-blue-600" />
                        </button>
                    </div>
                </section>

                <section className="grid grid-cols-3 gap-2.5">
                    <div className="rounded-[18px] border border-slate-200 bg-white p-3 shadow-[0_6px_18px_rgba(15,23,42,0.04)]">
                        <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-400">Válidos</p>
                        <div className="mt-1.5 flex items-center justify-between gap-2">
                            <p className="text-xl font-semibold text-slate-900">{documents_overview.kpis.valid}</p>
                            <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                        </div>
                    </div>
                    <div className="rounded-[18px] border border-slate-200 bg-white p-3 shadow-[0_6px_18px_rgba(15,23,42,0.04)]">
                        <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-400">Atenção</p>
                        <div className="mt-1.5 flex items-center justify-between gap-2">
                            <p className="text-xl font-semibold text-slate-900">{attentionCount}</p>
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                        </div>
                    </div>
                    <div className="rounded-[18px] border border-slate-200 bg-white p-3 shadow-[0_6px_18px_rgba(15,23,42,0.04)]">
                        <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-400">Época</p>
                        <p className="mt-1.5 truncate text-sm font-semibold text-slate-900">{documents_overview.kpis.season}</p>
                    </div>
                </section>

                {documents_overview.alerts.items.length > 0 ? (
                    <section className="rounded-[20px] border border-amber-200 bg-amber-50/70 p-3.5">
                        <div className="flex items-start gap-2.5">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                            <div className="min-w-0">
                                <p className="text-xs font-semibold text-amber-900">Há documentos que precisam de atenção.</p>
                                <p className="mt-1 line-clamp-2 text-xs leading-5 text-amber-800">
                                    {documents_overview.alerts.items.map((alert) => alert.name).join(' · ')}
                                </p>
                            </div>
                        </div>
                    </section>
                ) : null}

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Os meus documentos</h2>
                            <p className="mt-1 text-xs text-slate-500">Os que precisam de atenção aparecem primeiro.</p>
                        </div>
                        {documents_overview.upload.enabled ? (
                            <button
                                type="button"
                                onClick={() => openUploadModal()}
                                className="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700"
                            >
                                <Upload className="h-3.5 w-3.5" />
                                Enviar
                            </button>
                        ) : null}
                    </div>

                    <div className="mt-4 divide-y divide-slate-100">
                        {orderedDocuments.length > 0 ? orderedDocuments.map((document) => (
                            <article key={document.id} className="py-3 first:pt-0 last:pb-0">
                                <div className="flex items-start gap-3">
                                    <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${statusToneClasses[document.status.tone]}`}>
                                        <FileText className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-900">{document.name}</p>
                                                <p className="mt-0.5 line-clamp-1 text-[11px] text-slate-500">
                                                    {document.highlight || document.description}
                                                </p>
                                            </div>
                                            <span className={`shrink-0 rounded-full px-2 py-1 text-[9px] font-semibold ${statusToneClasses[document.status.tone]}`}>
                                                {document.status.label}
                                            </span>
                                        </div>

                                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-slate-500">
                                            <span>Registo: {displayValue(document.signed_at, '—')}</span>
                                            <span>Validade: {displayValue(document.valid_until, '—')}</span>
                                        </div>

                                        {(document.actions.view_url || document.actions.download_url || document.actions.can_upload) ? (
                                            <div className="mt-2.5 flex flex-wrap gap-2">
                                                {document.actions.view_url ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => openDocument(document.actions.view_url)}
                                                        className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[10px] font-semibold text-slate-700"
                                                    >
                                                        <Eye className="h-3 w-3" /> Ver
                                                    </button>
                                                ) : null}
                                                {document.actions.download_url ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => openDocument(document.actions.download_url, '_self')}
                                                        className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[10px] font-semibold text-slate-700"
                                                    >
                                                        <Download className="h-3 w-3" /> Descarregar
                                                    </button>
                                                ) : null}
                                                {document.actions.can_upload ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => openUploadModal(document.actions.upload_type)}
                                                        className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1.5 text-[10px] font-semibold text-blue-700"
                                                    >
                                                        <Upload className="h-3 w-3" /> {document.actions.primary_upload_label}
                                                    </button>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            </article>
                        )) : (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                Ainda não existem documentos configurados para este perfil.
                            </div>
                        )}
                    </div>
                </section>
            </PortalLayout>

            <Dialog open={isUploadModalOpen} onOpenChange={(open) => {
                setIsUploadModalOpen(open);
                if (!open) {
                    resetUploadForm();
                }
            }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Enviar documento</DialogTitle>
                        <DialogDescription>
                            Carrega o documento e, quando aplicável, indica a respetiva validade.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3 py-2">
                        <label className="block text-xs font-semibold text-slate-600">
                            Tipo de documento
                            <select
                                value={selectedUploadType}
                                onChange={(event) => setSelectedUploadType(event.target.value)}
                                className="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-300"
                            >
                                {uploadTypeOptions.map((option) => (
                                    <option key={option.type} value={option.type}>{option.name}</option>
                                ))}
                            </select>
                        </label>

                        <label className="block text-xs font-semibold text-slate-600">
                            Nome / referência <span className="font-normal text-slate-400">(opcional)</span>
                            <input
                                value={uploadForm.data.name}
                                onChange={(event) => uploadForm.setData('name', event.target.value)}
                                className="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-300"
                                placeholder="Ex.: Atestado médico 2026"
                            />
                            {uploadForm.errors.name ? <span className="mt-1 block text-[11px] text-rose-600">{uploadForm.errors.name}</span> : null}
                        </label>

                        <div>
                            <div className="flex items-center justify-between gap-3">
                                <label className="text-xs font-semibold text-slate-600" htmlFor="document-expiry-date">Validade</label>
                                <label className="inline-flex items-center gap-2 text-[11px] text-slate-500">
                                    <input
                                        type="checkbox"
                                        checked={withoutExpiry}
                                        onChange={(event) => setWithoutExpiry(event.target.checked)}
                                        className="rounded border-slate-300"
                                    />
                                    Sem validade
                                </label>
                            </div>
                            <input
                                id="document-expiry-date"
                                type="date"
                                value={uploadForm.data.expiry_date}
                                disabled={withoutExpiry}
                                onChange={(event) => uploadForm.setData('expiry_date', event.target.value)}
                                className="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-blue-300 disabled:bg-slate-100"
                            />
                            {uploadForm.errors.expiry_date ? <span className="mt-1 block text-[11px] text-rose-600">{uploadForm.errors.expiry_date}</span> : null}
                        </div>

                        <label className="block rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center">
                            <Upload className="mx-auto h-5 w-5 text-blue-600" />
                            <span className="mt-2 block text-xs font-semibold text-blue-700">Selecionar ficheiro</span>
                            <span className="mt-1 block text-[10px] text-slate-500">
                                Máximo {documents_overview.upload.max_size_mb} MB
                            </span>
                            <input
                                type="file"
                                accept={documents_overview.upload.accept}
                                onChange={(event) => uploadForm.setData('file', event.target.files?.[0] ?? null)}
                                className="sr-only"
                            />
                            {uploadForm.data.file ? (
                                <span className="mt-2 block truncate text-[11px] font-medium text-slate-700">{uploadForm.data.file.name}</span>
                            ) : null}
                            {uploadForm.errors.file ? <span className="mt-1 block text-[11px] text-rose-600">{uploadForm.errors.file}</span> : null}
                        </label>
                    </div>

                    <DialogFooter>
                        <button
                            type="button"
                            onClick={() => setIsUploadModalOpen(false)}
                            className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            onClick={submitUpload}
                            disabled={uploadForm.processing || !uploadForm.data.file}
                            className="rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {uploadForm.processing ? 'A enviar...' : 'Enviar documento'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
