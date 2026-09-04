import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Activity,
    Camera,
    ChevronRight,
    FileText,
    Pencil,
    Save,
    UserRound,
    X,
} from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import { portalRoutes } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

interface PortalBadge {
    label: string;
    tone: 'success' | 'info' | 'warning' | 'neutral';
}

interface DetailItem {
    label: string;
    value: string;
}

interface DocumentItem {
    label: string;
    status: 'valid' | 'expiring' | 'expired' | 'pending';
    state_label: string;
    helper: string;
    meta: string;
}

interface EditableProfileFields {
    nome_completo: string;
    data_nascimento: string | null;
    nif: string | null;
    cc: string | null;
    morada: string | null;
    codigo_postal: string | null;
    localidade: string | null;
    nacionalidade: string | null;
    sexo: 'masculino' | 'feminino' | null;
    contacto: string | null;
    email_secundario: string | null;
    num_federacao: string | null;
    numero_pmb: string | null;
    data_inscricao: string | null;
}

interface ProfilePayload {
    id: string;
    name: string;
    member_number: string | null;
    type: string;
    state: string;
    photo_url: string | null;
    viewing_self: boolean;
    can_edit: boolean;
    editable: EditableProfileFields;
    summary_badges: PortalBadge[];
    personal: DetailItem[];
    documents: DocumentItem[];
    sports: DetailItem[];
    flags: {
        is_athlete: boolean;
        is_socio: boolean;
        is_guardian: boolean;
        show_guardians: boolean;
    };
}

interface PortalProfileProps {
    profile: ProfilePayload;
    allowed_profiles: Array<{ id: string; name: string; portal_href: string }>;
    is_also_admin: boolean;
    has_family?: boolean;
    clubSettings?: ClubSettingsProps;
}

type PageProps = SharedPageProps<PortalProfileProps>;

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length > 1) {
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
    }

    return (parts[0]?.slice(0, 2) || 'U').toUpperCase();
}

function documentClasses(status: DocumentItem['status']): string {
    switch (status) {
        case 'valid':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        case 'expiring':
            return 'border-amber-200 bg-amber-50 text-amber-700';
        case 'expired':
            return 'border-rose-200 bg-rose-50 text-rose-700';
        default:
            return 'border-slate-200 bg-slate-100 text-slate-600';
    }
}

function inputClass(hasError: boolean): string {
    return `mt-1.5 w-full rounded-xl border bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition ${
        hasError ? 'border-rose-300 focus:border-rose-400' : 'border-slate-200 focus:border-blue-300'
    }`;
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="mt-1 text-xs font-medium text-rose-600">{message}</p> : null;
}

function SummaryList({ items }: { items: DetailItem[] }) {
    if (items.length === 0) {
        return <p className="text-sm text-slate-500">Sem informação disponível.</p>;
    }

    return (
        <div className="divide-y divide-slate-100">
            {items.map((item) => (
                <div key={item.label} className="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                    <span className="text-xs text-slate-500">{item.label}</span>
                    <span className="max-w-[60%] text-right text-sm font-medium text-slate-900">{item.value}</span>
                </div>
            ))}
        </div>
    );
}

export default function Profile() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        profile,
        allowed_profiles = [],
        is_also_admin,
        has_family = false,
        clubSettings,
    } = props;

    const [isEditing, setIsEditing] = useState(false);
    const photoInputRef = useRef<HTMLInputElement | null>(null);
    const form = useForm<EditableProfileFields & { photo: File | null }>({
        ...profile.editable,
        photo: null,
    });

    useEffect(() => {
        setIsEditing(false);
        form.reset();
        form.setData({ ...profile.editable, photo: null });
        form.clearErrors();
    }, [profile.id]);

    const photoPreviewUrl = useMemo(() => {
        return form.data.photo ? URL.createObjectURL(form.data.photo) : null;
    }, [form.data.photo]);

    useEffect(() => {
        return () => {
            if (photoPreviewUrl) {
                URL.revokeObjectURL(photoPreviewUrl);
            }
        };
    }, [photoPreviewUrl]);

    const errors = form.errors as Partial<Record<keyof (EditableProfileFields & { photo: File | null }), string>>;
    const currentPhotoUrl = photoPreviewUrl || profile.photo_url;
    const essentialPersonal = profile.personal.filter((item) => [
        'Nome completo',
        'Data de nascimento',
        'Contacto',
        'Email secundário',
        'Localidade',
        'Nacionalidade',
    ].includes(item.label));

    const submit = () => {
        const targetRoute = profile.viewing_self
            ? route('portal.profile.update')
            : route('portal.profile.update', { member: profile.id });

        form.transform((data) => ({ ...data, _method: 'patch' }));
        form.post(targetRoute, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsEditing(false);
                form.reset('photo');
            },
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <>
            <Head title="Perfil" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="profile"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[22px] bg-[linear-gradient(145deg,#0f62c8_0%,#0c4d9d_100%)] p-4 text-white shadow-[0_16px_32px_rgba(15,76,152,0.18)] sm:p-5">
                    <div className="flex items-start gap-3">
                        <button
                            type="button"
                            onClick={() => photoInputRef.current?.click()}
                            disabled={!profile.can_edit || form.processing}
                            className="group relative h-16 w-16 shrink-0 overflow-hidden rounded-2xl border border-white/20 bg-white/90 disabled:cursor-default"
                            aria-label="Alterar fotografia de perfil"
                        >
                            {currentPhotoUrl ? (
                                <img src={currentPhotoUrl} alt={profile.name} className="h-full w-full object-cover" />
                            ) : (
                                <span className="flex h-full w-full items-center justify-center text-base font-bold text-blue-700">
                                    {getInitials(profile.name)}
                                </span>
                            )}
                            {profile.can_edit ? (
                                <span className="absolute inset-x-0 bottom-0 flex justify-center bg-slate-950/45 py-1 text-white opacity-0 transition group-hover:opacity-100">
                                    <Camera className="h-3.5 w-3.5" />
                                </span>
                            ) : null}
                        </button>
                        <input
                            ref={photoInputRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            disabled={!profile.can_edit || form.processing}
                            onChange={(event) => form.setData('photo', event.target.files?.[0] ?? null)}
                        />

                        <div className="min-w-0 flex-1">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h1 className="truncate text-lg font-semibold leading-tight sm:text-xl">{profile.name}</h1>
                                    <p className="mt-1 text-xs text-blue-100">
                                        {profile.member_number ? `Sócio #${profile.member_number}` : 'Sem número de sócio'}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">
                                    {profile.state}
                                </span>
                            </div>
                            <p className="mt-2 text-xs text-blue-100">{profile.type}</p>
                        </div>
                    </div>

                    <div className="mt-4 flex items-center justify-between gap-3">
                        {allowed_profiles.length > 1 ? (
                            <div className="flex min-w-0 gap-1.5 overflow-x-auto pb-1">
                                {allowed_profiles.map((allowedProfile) => (
                                    <button
                                        key={allowedProfile.id}
                                        type="button"
                                        onClick={() => router.visit(allowedProfile.portal_href)}
                                        className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-semibold ${
                                            allowedProfile.id === profile.id
                                                ? 'bg-white text-blue-700'
                                                : 'border border-white/20 bg-white/10 text-white'
                                        }`}
                                    >
                                        {allowedProfile.name}
                                    </button>
                                ))}
                            </div>
                        ) : <span />}

                        {profile.can_edit ? (
                            <button
                                type="button"
                                onClick={() => setIsEditing((current) => !current)}
                                className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/20"
                            >
                                {isEditing ? <X className="h-3.5 w-3.5" /> : <Pencil className="h-3.5 w-3.5" />}
                                {isEditing ? 'Cancelar' : 'Editar'}
                            </button>
                        ) : null}
                    </div>
                </section>

                {isEditing ? (
                    <section className="rounded-[22px] border border-blue-200 bg-blue-50/40 p-4 sm:p-5">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold text-slate-900">Editar dados pessoais</h2>
                                <p className="mt-1 text-xs text-slate-500">Mantém aqui apenas a informação que precisas de atualizar.</p>
                            </div>
                            <button
                                type="button"
                                onClick={submit}
                                disabled={form.processing}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                            >
                                <Save className="h-3.5 w-3.5" />
                                {form.processing ? 'A guardar...' : 'Guardar'}
                            </button>
                        </div>

                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Nome completo
                                <input value={form.data.nome_completo || ''} onChange={(event) => form.setData('nome_completo', event.target.value)} className={inputClass(Boolean(errors.nome_completo))} />
                                <FieldError message={errors.nome_completo} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Data de nascimento
                                <input type="date" value={form.data.data_nascimento || ''} onChange={(event) => form.setData('data_nascimento', event.target.value || null)} className={inputClass(Boolean(errors.data_nascimento))} />
                                <FieldError message={errors.data_nascimento} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Sexo
                                <select value={form.data.sexo || ''} onChange={(event) => form.setData('sexo', (event.target.value || null) as EditableProfileFields['sexo'])} className={inputClass(Boolean(errors.sexo))}>
                                    <option value="">Selecionar</option>
                                    <option value="masculino">Masculino</option>
                                    <option value="feminino">Feminino</option>
                                </select>
                                <FieldError message={errors.sexo} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Contacto
                                <input value={form.data.contacto || ''} onChange={(event) => form.setData('contacto', event.target.value)} className={inputClass(Boolean(errors.contacto))} />
                                <FieldError message={errors.contacto} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Email secundário
                                <input type="email" value={form.data.email_secundario || ''} onChange={(event) => form.setData('email_secundario', event.target.value)} className={inputClass(Boolean(errors.email_secundario))} />
                                <FieldError message={errors.email_secundario} />
                            </label>
                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Morada
                                <input value={form.data.morada || ''} onChange={(event) => form.setData('morada', event.target.value)} className={inputClass(Boolean(errors.morada))} />
                                <FieldError message={errors.morada} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Código postal
                                <input value={form.data.codigo_postal || ''} onChange={(event) => form.setData('codigo_postal', event.target.value)} className={inputClass(Boolean(errors.codigo_postal))} />
                                <FieldError message={errors.codigo_postal} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Localidade
                                <input value={form.data.localidade || ''} onChange={(event) => form.setData('localidade', event.target.value)} className={inputClass(Boolean(errors.localidade))} />
                                <FieldError message={errors.localidade} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                NIF
                                <input value={form.data.nif || ''} onChange={(event) => form.setData('nif', event.target.value)} className={inputClass(Boolean(errors.nif))} />
                                <FieldError message={errors.nif} />
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Cartão de Cidadão
                                <input value={form.data.cc || ''} onChange={(event) => form.setData('cc', event.target.value)} className={inputClass(Boolean(errors.cc))} />
                                <FieldError message={errors.cc} />
                            </label>
                        </div>
                    </section>
                ) : null}

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                        <div className="mb-3 flex items-center gap-2">
                            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <UserRound className="h-4 w-4" />
                            </span>
                            <h2 className="text-base font-semibold text-slate-900">Dados pessoais</h2>
                        </div>
                        <SummaryList items={essentialPersonal.length > 0 ? essentialPersonal : profile.personal.slice(0, 6)} />
                    </div>

                    <button
                        type="button"
                        onClick={() => router.visit(portalRoutes.documents)}
                        className="rounded-[22px] border border-slate-200 bg-white p-4 text-left shadow-[0_8px_22px_rgba(15,23,42,0.045)] transition hover:border-blue-200 sm:p-5"
                    >
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <FileText className="h-4 w-4" />
                                </span>
                                <h2 className="text-base font-semibold text-slate-900">Documentos</h2>
                            </div>
                            <ChevronRight className="h-4 w-4 text-slate-300" />
                        </div>

                        <div className="space-y-2.5">
                            {profile.documents.slice(0, 4).map((document) => (
                                <div key={document.label} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-slate-900">{document.label}</p>
                                        <p className="mt-0.5 truncate text-[11px] text-slate-500">{document.helper || document.meta}</p>
                                    </div>
                                    <span className={`shrink-0 rounded-full border px-2 py-1 text-[10px] font-semibold ${documentClasses(document.status)}`}>
                                        {document.state_label}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </button>
                </section>

                {profile.flags.is_athlete ? (
                    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <Activity className="h-4 w-4" />
                                </span>
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">Desporto</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">Resumo da época atual.</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.visit(portalRoutes.results)}
                                className="text-xs font-semibold text-blue-700"
                            >
                                Ver resultados
                            </button>
                        </div>

                        <div className="mt-4 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            {profile.sports.slice(0, 6).map((item) => (
                                <div key={item.label} className="rounded-xl bg-slate-50 px-3 py-2.5">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{item.label}</p>
                                    <p className="mt-1 text-sm font-medium text-slate-900">{item.value}</p>
                                </div>
                            ))}
                        </div>

                        <button
                            type="button"
                            onClick={() => router.visit(portalRoutes.trainings)}
                            className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-blue-700"
                        >
                            Ver treinos <ChevronRight className="h-3.5 w-3.5" />
                        </button>
                    </section>
                ) : null}
            </PortalLayout>
        </>
    );
}
