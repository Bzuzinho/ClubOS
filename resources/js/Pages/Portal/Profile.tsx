import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Activity,
    CalendarDays,
    Camera,
    ChevronRight,
    FileText,
    Home,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Save,
    UserRound,
} from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import { portalRoutes } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

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
    estado_civil: 'solteiro' | 'casado' | 'uniao_de_facto' | 'divorciado' | 'viuvo' | null;
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
    personal: DetailItem[];
    documents: DocumentItem[];
    sports: DetailItem[];
    flags: {
        is_athlete: boolean;
    };
}

interface PortalProfileProps {
    profile: ProfilePayload;
    is_also_admin: boolean;
    has_family?: boolean;
    clubSettings?: ClubSettingsProps;
}

type PageProps = SharedPageProps<PortalProfileProps>;

const maritalStatusOptions: Array<{ value: NonNullable<EditableProfileFields['estado_civil']>; label: string }> = [
    { value: 'solteiro', label: 'Solteiro(a)' },
    { value: 'casado', label: 'Casado(a)' },
    { value: 'uniao_de_facto', label: 'União de facto' },
    { value: 'divorciado', label: 'Divorciado(a)' },
    { value: 'viuvo', label: 'Viúvo(a)' },
];

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

function disabledInputClass(): string {
    return 'mt-1.5 w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-3 py-2.5 text-sm text-slate-500';
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="mt-1 text-xs font-medium text-rose-600">{message}</p> : null;
}

function ReadOnlyRow({ icon: Icon, label, value }: { icon: typeof UserRound; label: string; value: string }) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-xl border border-slate-100 bg-slate-50/70 px-3 py-2.5">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-slate-500">
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1 text-xs text-slate-500">{label}</span>
            <span className="min-w-0 max-w-[58%] break-words text-right text-sm font-medium text-slate-900">{value}</span>
        </div>
    );
}

export default function Profile() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        profile,
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
    const personalValue = (label: string): string => profile.personal.find((item) => item.label === label)?.value ?? 'Sem informação';

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
                    <div className="flex min-w-0 items-start gap-3">
                        <button
                            type="button"
                            onClick={() => isEditing && photoInputRef.current?.click()}
                            disabled={!profile.can_edit || !isEditing || form.processing}
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
                            {profile.can_edit && isEditing ? (
                                <span className="absolute inset-x-0 bottom-0 flex justify-center bg-slate-950/45 py-1 text-white">
                                    <Camera className="h-3.5 w-3.5" />
                                </span>
                            ) : null}
                        </button>
                        <input
                            ref={photoInputRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            disabled={!profile.can_edit || !isEditing || form.processing}
                            onChange={(event) => form.setData('photo', event.target.files?.[0] ?? null)}
                        />

                        <div className="min-w-0 flex-1">
                            <div className="flex min-w-0 items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h1 className="break-words text-lg font-semibold leading-tight sm:text-xl">{profile.name}</h1>
                                    <p className="mt-1 text-xs text-blue-100">
                                        {profile.member_number ? `Sócio #${profile.member_number}` : 'Sem número de sócio'}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">
                                    {profile.state}
                                </span>
                            </div>
                            <p className="mt-2 break-words text-xs text-blue-100">{profile.type}</p>
                        </div>
                    </div>
                </section>

                <section className={`rounded-[22px] border bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5 ${isEditing ? 'border-blue-200' : 'border-slate-200'}`}>
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-2">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <UserRound className="h-4 w-4" />
                            </span>
                            <h2 className="text-base font-semibold text-slate-900">Dados pessoais</h2>
                        </div>

                        {profile.can_edit ? (
                            isEditing ? (
                                <button
                                    type="button"
                                    onClick={submit}
                                    disabled={form.processing}
                                    className="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                                >
                                    <Save className="h-3.5 w-3.5" />
                                    {form.processing ? 'A guardar...' : 'Guardar'}
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setIsEditing(true)}
                                    className="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                    Editar
                                </button>
                            )
                        ) : null}
                    </div>

                    {isEditing ? (
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Número de sócio
                                <input value={profile.member_number || 'Sem número de sócio'} disabled readOnly className={disabledInputClass()} />
                            </label>

                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Nome
                                <input value={form.data.nome_completo || ''} onChange={(event) => form.setData('nome_completo', event.target.value)} className={inputClass(Boolean(errors.nome_completo))} />
                                <FieldError message={errors.nome_completo} />
                            </label>

                            <label className="text-xs font-semibold text-slate-600">
                                Data de nascimento
                                <input type="date" value={form.data.data_nascimento || ''} onChange={(event) => form.setData('data_nascimento', event.target.value || null)} className={inputClass(Boolean(errors.data_nascimento))} />
                                <FieldError message={errors.data_nascimento} />
                            </label>

                            <label className="text-xs font-semibold text-slate-600">
                                NIF
                                <input value={form.data.nif || ''} onChange={(event) => form.setData('nif', event.target.value)} className={inputClass(Boolean(errors.nif))} />
                                <FieldError message={errors.nif} />
                            </label>

                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Nº de Cartão de Cidadão
                                <input value={form.data.cc || ''} onChange={(event) => form.setData('cc', event.target.value)} className={inputClass(Boolean(errors.cc))} />
                                <FieldError message={errors.cc} />
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
                                Estado civil
                                <select value={form.data.estado_civil || ''} onChange={(event) => form.setData('estado_civil', (event.target.value || null) as EditableProfileFields['estado_civil'])} className={inputClass(Boolean(errors.estado_civil))}>
                                    <option value="">Selecionar</option>
                                    {maritalStatusOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                                <FieldError message={errors.estado_civil} />
                            </label>

                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Email
                                <input type="email" value={form.data.email_secundario || ''} onChange={(event) => form.setData('email_secundario', event.target.value)} className={inputClass(Boolean(errors.email_secundario))} />
                                <FieldError message={errors.email_secundario} />
                            </label>

                            <label className="text-xs font-semibold text-slate-600">
                                Contacto
                                <input value={form.data.contacto || ''} onChange={(event) => form.setData('contacto', event.target.value)} className={inputClass(Boolean(errors.contacto))} />
                                <FieldError message={errors.contacto} />
                            </label>

                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Morada
                                <input value={form.data.morada || ''} onChange={(event) => form.setData('morada', event.target.value)} className={inputClass(Boolean(errors.morada))} />
                                <FieldError message={errors.morada} />
                            </label>

                            <label className="sm:col-span-2 text-xs font-semibold text-slate-600">
                                Localidade
                                <input value={form.data.localidade || ''} onChange={(event) => form.setData('localidade', event.target.value)} className={inputClass(Boolean(errors.localidade))} />
                                <FieldError message={errors.localidade} />
                            </label>
                        </div>
                    ) : (
                        <div className="mt-4 space-y-2">
                            <ReadOnlyRow icon={FileText} label="Número de sócio" value={profile.member_number || 'Sem informação'} />
                            <ReadOnlyRow icon={UserRound} label="Nome" value={personalValue('Nome completo')} />
                            <ReadOnlyRow icon={CalendarDays} label="Data de nascimento" value={personalValue('Data de nascimento')} />
                            <ReadOnlyRow icon={FileText} label="NIF" value={personalValue('NIF')} />
                            <ReadOnlyRow icon={FileText} label="Nº de Cartão de Cidadão" value={personalValue('CC')} />
                            <ReadOnlyRow icon={UserRound} label="Sexo" value={personalValue('Sexo')} />
                            <ReadOnlyRow icon={UserRound} label="Estado civil" value={personalValue('Estado civil')} />
                            <ReadOnlyRow icon={Mail} label="Email" value={personalValue('Email secundário')} />
                            <ReadOnlyRow icon={Phone} label="Contacto" value={personalValue('Contacto')} />
                            <ReadOnlyRow icon={Home} label="Morada" value={personalValue('Morada')} />
                            <ReadOnlyRow icon={MapPin} label="Localidade" value={personalValue('Localidade')} />
                        </div>
                    )}
                </section>

                <button
                    type="button"
                    onClick={() => router.visit(portalRoutes.documents)}
                    className="w-full rounded-[22px] border border-slate-200 bg-white p-4 text-left shadow-[0_8px_22px_rgba(15,23,42,0.045)] transition hover:border-blue-200 sm:p-5"
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
