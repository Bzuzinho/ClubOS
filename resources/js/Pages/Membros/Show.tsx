import { useEffect, useState, FormEventHandler } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { EnvelopeSimple } from '@phosphor-icons/react';
import { toast } from 'sonner';
import { DashboardTab } from '@/Components/Members/Tabs/DashboardTab';
import { PersonalTab } from '@/Components/Members/Tabs/PersonalTab';
import { FinancialTab } from '@/Components/Members/Tabs/FinancialTab';
import { SportsTab } from '@/Components/Members/Tabs/SportsTab';
import { ConfigurationTab } from '@/Components/Members/Tabs/ConfigurationTab';
import CommunicationsTab from './CommunicationsTab';

interface User {
    id: string;
    numero_socio?: string;
    nome_completo?: string;
    email_utilizador?: string;
    foto_perfil?: string;
    estado?: string;
    tipo_membro?: string[];
    data_nascimento?: string;
    perfil?: string;
    // ... other fields
    [key: string]: any;
}

interface Props {
    member: User;
    allUsers: User[];
    internalCommunications: {
        received: any[];
        sent: any[];
    };
    userTypes: any[];
    ageGroups: any[];
    faturas: any[];
    movimentos: any[];
    monthlyFees: any[];
    costCenters: any[];
}

interface PageProps {
    ziggy?: {
        query?: {
            tab?: string;
            folder?: 'received' | 'sent';
            message?: string;
        };
    };
}

type MemberTab = 'dashboard' | 'personal' | 'financial' | 'sports' | 'configuration' | 'communications';

const resolveMemberTab = (value: string | undefined, showSportsTab: boolean): MemberTab => {
    switch ((value || '').toLowerCase()) {
        case 'dashboard':
            return 'dashboard';
        case 'personal':
        case 'pessoal':
            return 'personal';
        case 'financial':
        case 'financeiro':
            return 'financial';
        case 'sports':
        case 'desportivo':
            return showSportsTab ? 'sports' : 'dashboard';
        case 'configuration':
        case 'configuracao':
            return 'configuration';
        case 'communications':
        case 'comunicacoes':
            return 'communications';
        default:
            return 'dashboard';
    }
};

const extractDateString = (value: any): string => {
    if (!value) return '';
    if (typeof value === 'string') return value;
    if (value?.date && typeof value.date === 'string') return value.date;
    if (value instanceof Date) return value.toISOString();
    return '';
};

const formatDateForInput = (value?: any): string => {
    const raw = extractDateString(value);
    if (!raw) return '';
    if (raw.includes('T')) return raw.split('T')[0];
    if (raw.includes(' ')) return raw.split(' ')[0];
    return raw;
};

const stringifyId = (value: unknown): string | null => {
    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return null;
};

const normalizeMember = (member: User): User => {
    const guardiansFromRelation = Array.isArray((member as any).encarregados) && (member as any).encarregados.length > 0
        ? (member as any).encarregados
            .map((g: any) => stringifyId(g.id))
            .filter((id: string | null): id is string => id !== null)
        : (member.encarregado_educacao || []);
    const educandosFromRelation = Array.isArray((member as any).educandos) && (member as any).educandos.length > 0
        ? (member as any).educandos
            .map((e: any) => stringifyId(e.id))
            .filter((id: string | null): id is string => id !== null)
        : (member.educandos || []);

    const normalizedBirthDate = formatDateForInput(
        member.data_nascimento ?? (member as any).birth_date ?? (member as any).data_nascimento
    );

    return {
        ...member,
        nome_completo: member.nome_completo ?? member.full_name ?? member.name ?? '',
        numero_socio: member.numero_socio ?? member.member_number ?? '',
        email_utilizador: member.email_utilizador ?? member.email ?? '',
        data_nascimento: normalizedBirthDate,
        encarregado_educacao: guardiansFromRelation,
        educandos: educandosFromRelation,
    };
};

const normalizeRelationIds = (value: unknown): string[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((entry) => {
            const directId = stringifyId(entry);
            if (directId !== null) {
                return directId;
            }

            if (entry && typeof entry === 'object' && 'id' in entry) {
                return stringifyId(entry.id);
            }

            return null;
        })
        .filter((entry): entry is string => Boolean(entry));
};

const buildMemberUpdatePayload = (user: User) => ({
    numero_socio: user.numero_socio || '',
    nome_completo: user.nome_completo || '',
    data_nascimento: formatDateForInput(user.data_nascimento),
    sexo: user.sexo || 'masculino',
    menor: Boolean(user.menor),
    tipo_membro: Array.isArray(user.tipo_membro) ? user.tipo_membro : [],
    estado: user.estado || 'ativo',
    nif: user.nif || '',
    morada: user.morada || '',
    codigo_postal: user.codigo_postal || '',
    localidade: user.localidade || '',
    nacionalidade: user.nacionalidade || '',
    estado_civil: user.estado_civil || '',
    contacto_telefonico: user.contacto_telefonico || '',
    email_secundario: user.email_secundario || '',
    numero_irmaos: user.numero_irmaos ?? null,
    ocupacao: user.ocupacao || '',
    empresa: user.empresa || '',
    escola: user.escola || '',
    email_utilizador: user.email_utilizador || '',
    perfil: user.perfil || '',
    rgpd: Boolean(user.rgpd),
    consentimento: Boolean(user.consentimento),
    afiliacao: Boolean(user.afiliacao),
    declaracao_de_transporte: Boolean(user.declaracao_de_transporte),
    tipo_mensalidade: user.tipo_mensalidade || '',
    discount_type: user.discount_type || '',
    discount_value: user.discount_value ?? '',
    discount_reason: user.discount_reason || '',
    centro_custo: normalizeRelationIds(user.centro_custo),
    ativo_desportivo: Boolean(user.ativo_desportivo),
    num_federacao: user.num_federacao || '',
    numero_pmb: user.numero_pmb || '',
    escalao: normalizeRelationIds(user.escalao),
    data_inscricao: formatDateForInput(user.data_inscricao),
    data_atestado_medico: formatDateForInput(user.data_atestado_medico),
    informacoes_medicas: user.informacoes_medicas || '',
    sync_encarregado_educacao: true,
    encarregado_educacao: normalizeRelationIds(user.encarregado_educacao),
    sync_educandos: true,
    educandos: normalizeRelationIds(user.educandos),
    foto_perfil: user.foto_perfil || '',
    cartao_federacao: user.cartao_federacao || '',
    arquivo_rgpd: user.arquivo_rgpd || '',
    arquivo_consentimento: user.arquivo_consentimento || '',
    arquivo_afiliacao: user.arquivo_afiliacao || '',
    declaracao_transporte: user.declaracao_transporte || '',
});

export default function Show({ member, allUsers, internalCommunications, userTypes, ageGroups, faturas, movimentos, monthlyFees, costCenters }: Props) {
    const page = usePage<PageProps>();
    const [user, setUser] = useState<User>(() => normalizeMember(member));
    const [hasChanges, setHasChanges] = useState(false);
    const query = page.props.ziggy?.query;
    const showSportsTab = (member.tipo_membro?.includes('atleta') || false);
    const initialTab = resolveMemberTab(query?.tab, showSportsTab);

    useEffect(() => {
        setUser(normalizeMember(member));
        setHasChanges(false);

        if (import.meta.env.DEV) {
            console.debug('[Membros/Show] loaded member props', {
                memberId: member.id,
                educandos: Array.isArray((member as any).educandos)
                    ? (member as any).educandos.map((entry: any) => entry?.id ?? entry)
                    : member.educandos,
                encarregado_educacao: Array.isArray((member as any).encarregado_educacao)
                    ? (member as any).encarregado_educacao.map((entry: any) => entry?.id ?? entry)
                    : member.encarregado_educacao,
                path: typeof window !== 'undefined' ? window.location.pathname : null,
            });
        }
    }, [
        member.id,
        member.updated_at,
        JSON.stringify(member.educandos ?? []),
        JSON.stringify(member.encarregado_educacao ?? []),
    ]);

    const handleChange = (field: keyof User, value: any) => {
        setUser(prev => ({ ...prev, [field]: value }));
        setHasChanges(true);
    };

    const handleSave: FormEventHandler = (e) => {
        e.preventDefault();
        const payload = buildMemberUpdatePayload(user);

        if (import.meta.env.DEV) {
            console.debug('[Membros/Show] saving member payload', {
                memberId: user.id,
                educandos: payload.educandos,
                encarregado_educacao: payload.encarregado_educacao,
                sync_educandos: payload.sync_educandos,
                sync_encarregado_educacao: payload.sync_encarregado_educacao,
            });
        }

        router.put(route('membros.update', user.id), payload, {
            onSuccess: () => {
                setHasChanges(false);
                toast.success('Membro atualizado com sucesso!');

                if (import.meta.env.DEV) {
                    console.debug('[Membros/Show] update request succeeded', {
                        memberId: user.id,
                    });
                }

                router.visit(route('membros.show', user.id), {
                    preserveScroll: true,
                    preserveState: false,
                    replace: true,
                });
            },
            onError: (errors) => {
                console.error('Erro ao atualizar membro:', errors);

                if (import.meta.env.DEV) {
                    console.debug('[Membros/Show] update request returned validation errors', {
                        memberId: user.id,
                        errors,
                    });
                }

                toast.error('Erro ao atualizar membro');
            }
        });
    };

    const handleBack = () => {
        if (hasChanges) {
            if (window.confirm('Tem alterações não guardadas. Deseja sair sem guardar?')) {
                router.visit(route('membros.index'));
            }
        } else {
            router.visit(route('membros.index'));
        }
    };

    const currentShowSportsTab = user.tipo_membro?.includes('atleta') || false;

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="icon" onClick={handleBack} className="h-8 w-8">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Button>
                        <div>
                            <h1 className="text-base sm:text-lg font-semibold tracking-tight">
                                {user.nome_completo || 'Novo Membro'}
                            </h1>
                            <p className="text-muted-foreground text-xs">
                                Nº de Sócio: {user.numero_socio || '-'}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={handleBack} className="h-8 text-xs">
                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Cancelar
                        </Button>
                        <Button size="sm" onClick={handleSave} disabled={!hasChanges} className="h-8 text-xs">
                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                            </svg>
                            Guardar
                        </Button>
                    </div>
                </div>
            }
        >
            <Head title={`Membro - ${user.nome_completo || 'Novo Membro'}`} />

            <div className={moduleViewportClass}>
            <Card className="flex min-h-0 flex-1 flex-col p-2 sm:p-3 bg-white border-0">
                <Tabs defaultValue={resolveMemberTab(query?.tab, currentShowSportsTab)} className={moduleTabsClass}>
                    <TabsList className={`grid w-full shrink-0 h-auto gap-1 p-1 ${currentShowSportsTab ? 'grid-cols-2 sm:grid-cols-6' : 'grid-cols-2 sm:grid-cols-5'}`}>
                            <TabsTrigger value="dashboard" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Dashboard
                            </TabsTrigger>
                            <TabsTrigger value="personal" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Pessoal
                            </TabsTrigger>
                            <TabsTrigger value="financial" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Financeiro
                            </TabsTrigger>
                            {currentShowSportsTab && (
                                <TabsTrigger value="sports" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                    Desportivo
                                </TabsTrigger>
                            )}
                            <TabsTrigger value="configuration" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Configuração
                            </TabsTrigger>
                            <TabsTrigger value="communications" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8 inline-flex items-center justify-center gap-1">
                                <EnvelopeSimple size={14} weight="duotone" />
                                Comunicações
                            </TabsTrigger>
                        </TabsList>

                    <TabsContent value="dashboard" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <DashboardTab user={user as any} faturas={faturas} />
                    </TabsContent>

                    <TabsContent value="personal" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <PersonalTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                            allUsers={allUsers}
                            userTypes={userTypes}
                            onNavigateToUser={(userId) => router.visit(route('membros.show', userId))}
                        />
                    </TabsContent>

                    <TabsContent value="financial" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <FinancialTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                            faturas={faturas}
                            movimentos={movimentos}
                            monthlyFees={monthlyFees}
                            costCenters={costCenters}
                        />
                    </TabsContent>

                    {currentShowSportsTab && (
                        <TabsContent value="sports" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                            <SportsTab 
                                user={user as any}
                                onChange={handleChange}
                                isAdmin={true}
                            />
                        </TabsContent>
                    )}

                    <TabsContent value="configuration" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <ConfigurationTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                        />
                    </TabsContent>

                    <TabsContent value="communications" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <CommunicationsTab
                            members={allUsers}
                            communications={internalCommunications}
                            initialFolder={query?.folder || 'received'}
                            initialMessageId={query?.message || null}
                            ownerLabel={user.nome_completo || 'este membro'}
                        />
                    </TabsContent>
                </Tabs>
            </Card>
            </div>

            {hasChanges && (
                <div className="fixed bottom-2 right-2 sm:bottom-4 sm:right-4 bg-accent text-accent-foreground p-2 rounded-lg shadow-lg border">
                    <p className="text-xs font-medium">Alterações não guardadas</p>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
