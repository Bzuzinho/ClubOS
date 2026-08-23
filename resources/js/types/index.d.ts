export type User = Partial<import('@/lib/types').User> & {
    id: number | string;
    name?: string;
    nome_completo?: string;
    email?: string;
    email_verified_at?: string;
    full_name?: string;
    member_number?: string;
    photo?: string;
    foto_perfil?: string;
};

export type Event = import('@/lib/types').Event;
export type ConvocatoriaAtleta = import('@/lib/types').ConvocatoriaAtleta;
export type ConvocatoriaGrupo = import('@/lib/types').ConvocatoriaGrupo;
export type ResultadoProva = import('@/lib/types').ResultadoProva;
export type EventoPresenca = import('@/lib/types').EventoPresenca;

export interface ClubSettingsProps {
    nome_clube: string;
    sigla: string;
    morada: string | null;
    codigo_postal: string | null;
    localidade: string | null;
    telefone: string | null;
    email: string | null;
    website: string | null;
    nif: string | null;
    iban: string | null;
    logo_url: string | null;
    horario_funcionamento: Record<string, unknown> | unknown[];
    redes_sociais: Record<string, unknown> | unknown[];
    display_name: string;
    short_name: string;
    default_financial_entity_name: string;
    default_meeting_point: string;
}

export interface CommunicationAlertItem {
    id: string;
    title: string;
    message: string;
    type: 'info' | 'warning' | 'success' | 'error';
    link?: string | null;
    is_read: boolean;
    created_at: string;
    sender?: {
        id: string;
        name: string;
    } | null;
}

export interface CommunicationMemberOption {
    id: string;
    numero_socio?: string | null;
    nome_completo?: string | null;
    name?: string | null;
    email?: string | null;
}

export type PageProps<
    T extends object = Record<string, unknown>,
> = T & {
    [key: string]: unknown;
    auth: {
        user: User;
    };
    clubSettings?: ClubSettingsProps;
    communicationAlerts?: {
        unreadCount: number;
        recent?: CommunicationAlertItem[];
    };
    communicationMembers?: CommunicationMemberOption[];
};
