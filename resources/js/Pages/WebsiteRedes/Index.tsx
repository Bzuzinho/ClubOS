import { FormEvent, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowSquareOut,
    CheckCircle,
    Clock,
    EnvelopeSimple,
    GlobeHemisphereWest,
    InstagramLogo,
    MetaLogo,
    UserCircle,
} from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

type SubmissionStatus = 'new' | 'in_review' | 'contacted' | 'accepted' | 'rejected';
type SubmissionType = 'contact' | 'registration';

interface Submission {
    id: string;
    type: SubmissionType;
    status: SubmissionStatus;
    athlete_name: string;
    birth_date: string;
    email: string;
    phone: string;
    program: string;
    experience: string;
    locality?: string | null;
    previous_club?: string | null;
    federation_number?: string | null;
    availability?: string | null;
    guardian_name?: string | null;
    guardian_relationship?: string | null;
    guardian_email?: string | null;
    guardian_phone?: string | null;
    notes?: string | null;
    privacy_consent_at?: string | null;
    email_queued_at?: string | null;
    admin_notified_at?: string | null;
    processed_at?: string | null;
    processed_by?: string | null;
    created_at: string;
    user?: {
        id: string;
        name: string;
        estado?: string | null;
        numero_socio?: string | null;
        url: string;
    } | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    summary: {
        new: number;
        in_review: number;
        registrations: number;
        contacts: number;
    };
    submissions: {
        data: Submission[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    selectedSubmission?: Submission | null;
    filters: {
        type?: string;
        status?: string;
        search?: string;
    };
    channels: {
        website: 'active' | 'pending';
        facebook: 'active' | 'pending';
        instagram: 'active' | 'pending';
    };
}

const statusLabels: Record<SubmissionStatus, string> = {
    new: 'Novo',
    in_review: 'Em análise',
    contacted: 'Contactado',
    accepted: 'Aceite',
    rejected: 'Recusado',
};

const statusClasses: Record<SubmissionStatus, string> = {
    new: 'border-blue-200 bg-blue-50 text-blue-800',
    in_review: 'border-amber-200 bg-amber-50 text-amber-800',
    contacted: 'border-violet-200 bg-violet-50 text-violet-800',
    accepted: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    rejected: 'border-slate-200 bg-slate-100 text-slate-700',
};

function formatDate(value?: string | null, includeTime = true): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        ...(includeTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(new Date(value));
}

function paginationLabel(label: string): string {
    if (label.includes('Previous')) return 'Anterior';
    if (label.includes('Next')) return 'Seguinte';
    return label.replace('&laquo;', '').replace('&raquo;', '').trim();
}

function DetailRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;

    return (
        <div className="grid gap-1 border-b py-2 last:border-b-0 sm:grid-cols-[155px_1fr]">
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
            <dd className="whitespace-pre-wrap text-sm text-foreground">{value}</dd>
        </div>
    );
}

export default function WebsiteRedesIndex({ summary, submissions, selectedSubmission, filters, channels }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type || 'all');
    const [status, setStatus] = useState(filters.status || 'all');
    const [updatingStatus, setUpdatingStatus] = useState(false);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/website-redes', {
            search: search || undefined,
            type: type === 'all' ? undefined : type,
            status: status === 'all' ? undefined : status,
        }, { preserveState: true, replace: true });
    };

    const updateStatus = (nextStatus: SubmissionStatus) => {
        if (!selectedSubmission) return;

        setUpdatingStatus(true);
        router.patch(`/website-redes/pedidos/${selectedSubmission.id}/estado`, { status: nextStatus }, {
            preserveScroll: true,
            onFinish: () => setUpdatingStatus(false),
        });
    };

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Gestão editorial e captação</p>
                        <h1 className="text-2xl font-semibold text-foreground">Website & Redes</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm"><Link href="/website-redes/paginas">Gerir páginas</Link></Button>
                        <Button asChild variant="outline" size="sm">
                            <a href="/" target="_blank" rel="noreferrer">Abrir website <ArrowSquareOut className="ml-2" /></a>
                        </Button>
                    </div>
                </div>
            }
        >
            <Head title="Website & Redes" />

            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { label: 'Novos pedidos', value: summary.new, icon: EnvelopeSimple, tone: 'text-blue-700 bg-blue-50' },
                        { label: 'Em análise', value: summary.in_review, icon: Clock, tone: 'text-amber-700 bg-amber-50' },
                        { label: 'Pré-inscrições', value: summary.registrations, icon: UserCircle, tone: 'text-emerald-700 bg-emerald-50' },
                        { label: 'Pedidos de contacto', value: summary.contacts, icon: CheckCircle, tone: 'text-violet-700 bg-violet-50' },
                    ].map(({ label, value, icon: Icon, tone }) => (
                        <Card key={label}>
                            <CardContent className="flex items-center justify-between p-4">
                                <div><p className="text-sm text-muted-foreground">{label}</p><p className="mt-1 text-3xl font-semibold">{value}</p></div>
                                <span className={`rounded-xl p-3 ${tone}`}><Icon size={24} /></span>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(360px,0.8fr)]">
                    <Card>
                        <CardHeader className="space-y-3 pb-3">
                            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <CardTitle>Pedidos recebidos</CardTitle>
                                <p className="text-xs text-muted-foreground">{submissions.from ?? 0}–{submissions.to ?? 0} de {submissions.total}</p>
                            </div>
                            <form onSubmit={applyFilters} className="grid gap-2 md:grid-cols-[minmax(180px,1fr)_170px_170px_auto]">
                                <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Pesquisar nome, email ou telefone" />
                                <Select value={type} onValueChange={setType}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos os tipos</SelectItem>
                                        <SelectItem value="registration">Pré-inscrições</SelectItem>
                                        <SelectItem value="contact">Contactos</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select value={status} onValueChange={setStatus}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos os estados</SelectItem>
                                        {Object.entries(statusLabels).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Button type="submit">Filtrar</Button>
                            </form>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {submissions.data.map((submission) => (
                                    <Link
                                        key={submission.id}
                                        href={`/website-redes/pedidos/${submission.id}`}
                                        preserveScroll
                                        className={`block px-4 py-3 transition hover:bg-muted/50 ${selectedSubmission?.id === submission.id ? 'bg-primary/5' : ''}`}
                                    >
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium text-foreground">{submission.athlete_name}</p>
                                                    <Badge variant="outline" className={statusClasses[submission.status]}>{statusLabels[submission.status]}</Badge>
                                                    <Badge variant="secondary">{submission.type === 'registration' ? 'Pré-inscrição' : 'Contacto'}</Badge>
                                                </div>
                                                <p className="mt-1 truncate text-sm text-muted-foreground">{submission.email} · {submission.phone}</p>
                                                <p className="mt-1 text-xs text-muted-foreground">{submission.program} · {submission.experience}</p>
                                            </div>
                                            <div className="shrink-0 text-left text-xs text-muted-foreground sm:text-right">
                                                <p>{formatDate(submission.created_at)}</p>
                                                {submission.user && <p className="mt-1 text-emerald-700">Ficha associada</p>}
                                            </div>
                                        </div>
                                    </Link>
                                ))}
                                {submissions.data.length === 0 && <div className="p-10 text-center text-sm text-muted-foreground">Não existem pedidos com estes filtros.</div>}
                            </div>

                            {submissions.links.length > 3 && (
                                <div className="flex flex-wrap gap-1 border-t p-3">
                                    {submissions.links.map((link, index) => (
                                        <Button key={`${link.label}-${index}`} asChild={Boolean(link.url)} disabled={!link.url} variant={link.active ? 'default' : 'outline'} size="sm">
                                            {link.url ? <Link href={link.url} preserveScroll>{paginationLabel(link.label)}</Link> : <span>{paginationLabel(link.label)}</span>}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {selectedSubmission ? (
                        <Card className="h-fit xl:sticky xl:top-4">
                            <CardHeader className="space-y-3">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div><p className="text-xs uppercase tracking-wide text-muted-foreground">Detalhe do pedido</p><CardTitle className="mt-1">{selectedSubmission.athlete_name}</CardTitle></div>
                                    <Badge variant="outline" className={statusClasses[selectedSubmission.status]}>{statusLabels[selectedSubmission.status]}</Badge>
                                </div>
                                <Select value={selectedSubmission.status} onValueChange={(value) => updateStatus(value as SubmissionStatus)} disabled={updatingStatus}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{Object.entries(statusLabels).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
                                </Select>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {selectedSubmission.user && (
                                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                        <p className="text-sm font-medium text-emerald-900">Ficha ClubOS associada</p>
                                        <p className="text-xs text-emerald-800">{selectedSubmission.user.name} · {selectedSubmission.user.estado || 'Sem estado'}</p>
                                        <Button asChild variant="outline" size="sm" className="mt-2 bg-white"><Link href={selectedSubmission.user.url}>Abrir ficha do membro</Link></Button>
                                    </div>
                                )}

                                <dl>
                                    <DetailRow label="Tipo" value={selectedSubmission.type === 'registration' ? 'Pré-inscrição' : 'Pedido de contacto'} />
                                    <DetailRow label="Nascimento" value={formatDate(selectedSubmission.birth_date, false)} />
                                    <DetailRow label="Email" value={selectedSubmission.email} />
                                    <DetailRow label="Telefone" value={selectedSubmission.phone} />
                                    <DetailRow label="Localidade" value={selectedSubmission.locality} />
                                    <DetailRow label="Programa" value={selectedSubmission.program} />
                                    <DetailRow label="Experiência" value={selectedSubmission.experience} />
                                    <DetailRow label="Clube anterior" value={selectedSubmission.previous_club} />
                                    <DetailRow label="N.º federação" value={selectedSubmission.federation_number} />
                                    <DetailRow label="Disponibilidade" value={selectedSubmission.availability} />
                                    <DetailRow label="Encarregado" value={selectedSubmission.guardian_name} />
                                    <DetailRow label="Relação" value={selectedSubmission.guardian_relationship} />
                                    <DetailRow label="Email EE" value={selectedSubmission.guardian_email} />
                                    <DetailRow label="Telefone EE" value={selectedSubmission.guardian_phone} />
                                    <DetailRow label="Notas" value={selectedSubmission.notes} />
                                </dl>

                                <div className="grid grid-cols-2 gap-2 text-xs">
                                    <div className={`rounded-lg border p-2 ${selectedSubmission.email_queued_at ? 'border-emerald-200 bg-emerald-50' : 'bg-muted/40'}`}>
                                        <p className="font-medium">Email ao clube</p><p className="text-muted-foreground">{selectedSubmission.email_queued_at ? 'Colocado em fila' : 'Sem confirmação'}</p>
                                    </div>
                                    <div className={`rounded-lg border p-2 ${selectedSubmission.admin_notified_at ? 'border-emerald-200 bg-emerald-50' : 'bg-muted/40'}`}>
                                        <p className="font-medium">Alerta na app</p><p className="text-muted-foreground">{selectedSubmission.admin_notified_at ? 'Criado' : 'Sem destinatário'}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader><CardTitle>Canais</CardTitle></CardHeader>
                                <CardContent className="space-y-2">
                                    {[
                                        { label: 'Website', state: channels.website, icon: GlobeHemisphereWest },
                                        { label: 'Facebook', state: channels.facebook, icon: MetaLogo },
                                        { label: 'Instagram', state: channels.instagram, icon: InstagramLogo },
                                    ].map(({ label, state, icon: Icon }) => (
                                        <div key={label} className="flex items-center justify-between rounded-lg border p-3">
                                            <span className="flex items-center gap-2 text-sm font-medium"><Icon size={20} /> {label}</span>
                                            <Badge variant={state === 'active' ? 'default' : 'secondary'}>{state === 'active' ? 'Ativo' : 'Fase seguinte'}</Badge>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader><CardTitle>Próximas entregas</CardTitle></CardHeader>
                                <CardContent className="space-y-3 text-sm text-muted-foreground">
                                    <p><strong className="text-foreground">Editor de páginas:</strong> textos, cards, imagens, ordem e histórico de versões.</p>
                                    <p><strong className="text-foreground">Publicação multicanal:</strong> website, Facebook e Instagram com agendamento e estado por canal.</p>
                                    <p><strong className="text-foreground">Biblioteca multimédia:</strong> imagens reutilizáveis, texto alternativo e rastreio de utilização.</p>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
