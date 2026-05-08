import { lazy, Suspense, useState, useEffect, FormEventHandler } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Switch } from '@/Components/ui/switch';
import { Separator } from '@/Components/ui/separator';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Badge } from '@/Components/ui/badge';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, PencilSimple, Trash, FloppyDisk } from '@phosphor-icons/react';
import { toast } from 'sonner';
import { AccessControlBootstrap } from '@/types/access-control';

const UserTypePermissionSettings = lazy(() => import('@/Components/Configuracoes/Permissions/UserTypePermissionSettings').then((module) => ({ default: module.UserTypePermissionSettings })));
const ConfiguracoesDesportivoIndex = lazy(() => import('@/Pages/Configuracoes/Desportivo/Index'));

interface AgeGroup {
    id: string;
    nome: string;
    descricao?: string;
    idade_minima: number;
    idade_maxima: number;
    ativo: boolean;
}

interface UserType {
    id: string;
    nome: string;
    descricao?: string;
    ativo: boolean;
}

interface EventType {
    id: string;
    nome: string;
    descricao?: string;
    categoria?: string;
    cor?: string;
    icon?: string;
    visibilidade_default?: string;
    gera_taxa?: boolean;
    permite_convocatoria?: boolean;
    gera_presencas?: boolean;
    requer_transporte?: boolean;
    ativo: boolean;
}

interface BaseDesportivoConfig {
    id: string;
    codigo: string;
    nome: string;
    ativo: boolean;
    ordem: number;
}

interface TrainingZoneConfig extends BaseDesportivoConfig {
    percentagem_min?: number | null;
    percentagem_max?: number | null;
}

interface AbsenceReasonConfig extends BaseDesportivoConfig {
    requer_justificacao: boolean;
}

interface InjuryReasonConfig extends BaseDesportivoConfig {
    gravidade: string;
}

interface PoolTypeConfig extends BaseDesportivoConfig {
    comprimento_m?: number | null;
}

interface Permission {
    id: string;
    user_type_id: string;
    modulo: string;
    submodulo?: string | null;
    separador?: string | null;
    campo?: string | null;
    pode_ver: boolean;
    pode_criar: boolean;
    pode_editar: boolean;
    pode_eliminar: boolean;
}

interface MonthlyFee {
    id: string;
    designacao: string;
    valor: number;
    age_group_id?: string | null;
    ativo: boolean;
}

interface InvoiceType {
    id: string;
    codigo: string;
    nome: string;
    descricao?: string | null;
    ativo: boolean;
}

interface CostCenter {
    id: string;
    codigo: string;
    nome: string;
    tipo?: string | null;
    descricao?: string | null;
    orcamento?: number | null;
    ativo: boolean;
}

interface Product {
    id: string;
    codigo: string;
    nome: string;
    categoria?: string | null;
    preco: number;
    stock_minimo?: number;
    area_armazenamento?: string | null;
    descricao?: string | null;
    imagem?: string | null;
    ativo: boolean;
    visible_in_store?: boolean;
}

const toNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
};

interface ItemCategory {
    id: string;
    codigo: string;
    nome: string;
    ativo: boolean;
}

interface Supplier {
    id: string;
    nome: string;
    nif?: string | null;
    email?: string | null;
    telefone?: string | null;
    morada?: string | null;
    categoria?: string | null;
    ativo: boolean;
}

interface Sponsor {
    id: string;
    nome: string;
    descricao?: string | null;
    logo?: string | null;
    website?: string | null;
    contacto?: string | null;
    email?: string | null;
    tipo: 'principal' | 'secundario' | 'apoio';
    valor_anual?: number | null;
    data_inicio: string;
    data_fim?: string | null;
    estado: 'ativo' | 'inativo' | 'expirado';
}

interface ProvaTipo {
    id: string;
    nome: string;
    distancia: number;
    unidade: string;
    modalidade: string;
    ativo: boolean;
}

interface NotificationPrefs {
    id?: string;
    email_notificacoes: boolean;
    alertas_pagamento: boolean;
    alertas_atividade: boolean;
    automacoes_financeiro: boolean;
    automacoes_eventos: boolean;
    automacoes_logistica: boolean;
    automacoes_faturas_financeiras: boolean;
    automacoes_movimentos_financeiros: boolean;
    automacoes_convocatorias_eventos: boolean;
    automacoes_requisicoes_logistica: boolean;
    automacoes_alertas_operacionais: boolean;
}

interface CommunicationDynamicSource {
    id: string;
    name: string;
    description?: string | null;
    strategy: string;
    sort_order: number;
    is_active: boolean;
}

interface CommunicationAlertCategory {
    id: string;
    code: string;
    name: string;
    description?: string | null;
    channels: string[];
    sort_order: number;
    is_active: boolean;
}

interface DbUser {
    id: string;
    numero_socio?: string | null;
    nome_completo?: string | null;
    email_utilizador?: string | null;
    perfil?: string | null;
    estado?: string | null;
}

interface ClubSettings {
    id?: number;
    nome_clube: string;
    sigla?: string;
    morada?: string;
    codigo_postal?: string;
    localidade?: string;
    telefone?: string;
    email?: string;
    website?: string;
    nif?: string;
    logo_url?: string;
    iban?: string;
    monthly_fee_generation_enabled?: boolean;
    monthly_fee_start_month?: number;
    monthly_fee_end_month?: number;
    monthly_fee_due_day?: number;
    monthly_fee_hide_future?: boolean;
    monthly_fee_auto_activate_due?: boolean;
    monthly_fee_respect_registration_date?: boolean;
    monthly_fee_generate_months_ahead?: number | null;
    monthly_fee_default_period_mode?: string;
}

function TabFallback() {
    return <div className="py-8 text-sm text-muted-foreground">A carregar...</div>;
}

const permissionCatalog = [
    {
        value: 'dashboard',
        label: 'Dashboard',
        submodules: [],
    },
    {
        value: 'membros',
        label: 'Membros',
        submodules: [
            {
                value: 'lista',
                label: 'Lista',
                separators: [
                    { value: 'tabela', label: 'Tabela', fields: ['nome_completo', 'numero_socio', 'email_utilizador', 'estado'] },
                ],
            },
            {
                value: 'perfil',
                label: 'Perfil',
                separators: [
                    { value: 'dados_pessoais', label: 'Dados pessoais', fields: ['nome_completo', 'nif', 'cc', 'morada'] },
                    { value: 'contactos', label: 'Contactos', fields: ['telefone', 'email_utilizador'] },
                ],
            },
            {
                value: 'desportivo',
                label: 'Desportivo',
                separators: [
                    { value: 'treinos', label: 'Treinos', fields: ['frequencia', 'grupo'] },
                    { value: 'competicoes', label: 'Competições', fields: ['resultado', 'posicao'] },
                ],
            },
            {
                value: 'financeiro',
                label: 'Financeiro',
                separators: [
                    { value: 'mensalidades', label: 'Mensalidades', fields: ['tipo_mensalidade', 'valor'] },
                    { value: 'faturas', label: 'Faturas', fields: ['estado_pagamento', 'valor_total'] },
                ],
            },
        ],
    },
    {
        value: 'eventos',
        label: 'Eventos',
        submodules: [
            {
                value: 'calendario',
                label: 'Calendário',
                separators: [
                    { value: 'lista', label: 'Lista', fields: ['titulo', 'data', 'local'] },
                ],
            },
            {
                value: 'convocatorias',
                label: 'Convocatórias',
                separators: [
                    { value: 'atletas', label: 'Atletas', fields: ['presenca', 'estado'] },
                ],
            },
            {
                value: 'resultados',
                label: 'Resultados',
                separators: [
                    { value: 'provas', label: 'Provas', fields: ['tempo', 'posicao'] },
                ],
            },
        ],
    },
    {
        value: 'desportivo',
        label: 'Desportivo',
        submodules: [
            {
                value: 'treinos',
                label: 'Treinos',
                separators: [
                    { value: 'planeamento', label: 'Planeamento', fields: ['data', 'objetivo'] },
                ],
            },
            {
                value: 'competicoes',
                label: 'Competições',
                separators: [
                    { value: 'eventos', label: 'Eventos', fields: ['nome', 'data'] },
                ],
            },
        ],
    },
    {
        value: 'financeiro',
        label: 'Financeiro',
        submodules: [
            {
                value: 'faturas',
                label: 'Faturas',
                separators: [
                    { value: 'lista', label: 'Lista', fields: ['estado_pagamento', 'valor_total'] },
                ],
            },
            {
                value: 'movimentos',
                label: 'Movimentos',
                separators: [
                    { value: 'entradas', label: 'Entradas', fields: ['valor', 'descricao'] },
                    { value: 'saidas', label: 'Saídas', fields: ['valor', 'descricao'] },
                ],
            },
            {
                value: 'mensalidades',
                label: 'Mensalidades',
                separators: [
                    { value: 'config', label: 'Config', fields: ['designacao', 'valor'] },
                ],
            },
        ],
    },
    {
        value: 'loja',
        label: 'Loja',
        submodules: [
            {
                value: 'artigos',
                label: 'Artigos',
                separators: [
                    { value: 'catalogo', label: 'Catálogo', fields: ['codigo', 'nome', 'preco'] },
                ],
            },
            {
                value: 'vendas',
                label: 'Vendas',
                separators: [
                    { value: 'registos', label: 'Registos', fields: ['valor', 'data'] },
                ],
            },
        ],
    },
    {
        value: 'patrocinios',
        label: 'Patrocínios',
        submodules: [
            {
                value: 'sponsors',
                label: 'Patrocinadores',
                separators: [
                    { value: 'lista', label: 'Lista', fields: ['nome', 'valor'] },
                ],
            },
        ],
    },
    {
        value: 'comunicacao',
        label: 'Comunicação',
        submodules: [
            {
                value: 'mensagens',
                label: 'Mensagens',
                separators: [
                    { value: 'lista', label: 'Lista', fields: ['assunto', 'estado'] },
                ],
            },
        ],
    },
    {
        value: 'marketing',
        label: 'Marketing',
        submodules: [
            {
                value: 'campanhas',
                label: 'Campanhas',
                separators: [
                    { value: 'lista', label: 'Lista', fields: ['nome', 'estado'] },
                ],
            },
        ],
    },
    {
        value: 'configuracoes',
        label: 'Configurações',
        submodules: [
            {
                value: 'geral',
                label: 'Geral',
                separators: [
                    { value: 'escaloes', label: 'Escalões', fields: ['nome', 'idade_minima', 'idade_maxima'] },
                    { value: 'tipos_utilizador', label: 'Tipos de Utilizador', fields: ['nome', 'descricao'] },
                    { value: 'permissoes', label: 'Permissões', fields: ['modulo', 'submodulo'] },
                    { value: 'provas', label: 'Provas', fields: ['nome', 'distancia', 'modalidade'] },
                ],
            },
            {
                value: 'clube',
                label: 'Clube',
                separators: [
                    { value: 'info', label: 'Info', fields: ['nome_clube', 'nif', 'morada', 'telefone'] },
                ],
            },
            {
                value: 'financeiro',
                label: 'Financeiro',
                separators: [
                    { value: 'mensalidades', label: 'Mensalidades', fields: ['designacao', 'valor'] },
                    { value: 'tipos_fatura', label: 'Tipos de Fatura', fields: ['nome', 'codigo'] },
                    { value: 'centros_custo', label: 'Centros de Custo', fields: ['nome', 'orcamento'] },
                ],
            },
            {
                value: 'logistica',
                label: 'Logística',
                separators: [
                    { value: 'artigos', label: 'Artigos', fields: ['codigo', 'nome', 'preco'] },
                    { value: 'fornecedores', label: 'Fornecedores', fields: ['nome', 'nif'] },
                ],
            },
            {
                value: 'notificacoes',
                label: 'Notificações',
                separators: [
                    { value: 'preferencias', label: 'Preferências', fields: ['email_notificacoes', 'alertas_pagamento', 'alertas_atividade'] },
                ],
            },
            {
                value: 'base_dados',
                label: 'Base de Dados',
                separators: [
                    { value: 'utilizadores', label: 'Utilizadores', fields: ['nome_completo', 'email_utilizador', 'estado'] },
                ],
            },
        ],
    },
];

const customValue = '__custom__';

const dynamicSourceStrategies = [
    { value: 'all_members', label: 'Todos os membros' },
    { value: 'athletes', label: 'Atletas por escalão' },
    { value: 'guardians', label: 'Pais/Encarregados' },
    { value: 'coaches', label: 'Treinadores' },
    { value: 'team_members', label: 'Membros por equipa' },
    { value: 'age_group_members', label: 'Membros por escalão' },
    { value: 'overdue_payments', label: 'Pagamentos em atraso' },
    { value: 'event_participants', label: 'Participantes de evento' },
    { value: 'users_with_unread_alerts', label: 'Utilizadores com alertas por ler' },
] as const;

const dynamicSourceStrategyLabel = (strategy: string) => (
    dynamicSourceStrategies.find((item) => item.value === strategy)?.label || strategy
);

const alertCategoryChannels = [
    { value: 'email', label: 'Email' },
    { value: 'sms', label: 'SMS' },
    { value: 'alert_app', label: 'Alerta na app' },
] as const;

const alertCategoryChannelLabel = (channel: string) => (
    alertCategoryChannels.find((item) => item.value === channel)?.label || channel
);

const buildOptions = (values: Array<{ value: string; label: string }>) => [
    ...values,
    { value: customValue, label: 'Personalizado' },
];

interface Props {
    userTypes: UserType[];
    ageGroups: AgeGroup[];
    eventTypes: EventType[];
    athleteStatuses: BaseDesportivoConfig[];
    trainingTypes?: BaseDesportivoConfig[];
    trainingZones?: TrainingZoneConfig[];
    absenceReasons: AbsenceReasonConfig[];
    injuryReasons?: InjuryReasonConfig[];
    poolTypes?: PoolTypeConfig[];
    permissions: Permission[];
    monthlyFees?: MonthlyFee[];
    invoiceTypes?: InvoiceType[];
    costCenters?: CostCenter[];
    products?: Product[];
    sponsors?: Sponsor[];
    suppliers?: Supplier[];
    itemCategories?: ItemCategory[];
    provaTipos?: ProvaTipo[];
    notificationPrefs?: NotificationPrefs | null;
    communicationDynamicSources?: CommunicationDynamicSource[];
    communicationAlertCategories?: CommunicationAlertCategory[];
    users?: DbUser[];
    clubSettings?: ClubSettings;
    accessControlBootstrap: AccessControlBootstrap;
}

export default function SettingsIndex({
    userTypes,
    ageGroups,
    eventTypes,
    athleteStatuses,
    trainingTypes = [],
    trainingZones = [],
    absenceReasons,
    injuryReasons = [],
    poolTypes = [],
    permissions,
    monthlyFees = [],
    invoiceTypes = [],
    costCenters = [],
    products = [],
    sponsors = [],
    suppliers = [],
    itemCategories = [],
    provaTipos = [],
    notificationPrefs: initialNotificationPrefs,
    communicationDynamicSources = [],
    communicationAlertCategories = [],
    users = [],
    clubSettings,
    accessControlBootstrap,
}: Props) {
    const page = usePage<Props>();
    const [currentTab, setCurrentTab] = useState(() => {
        if (typeof window !== 'undefined') {
            return new URLSearchParams(window.location.search).get('tab') || 'geral';
        }

        return 'geral';
    });
    const [currentGeneralTab, setCurrentGeneralTab] = useState(() => {
        if (typeof window !== 'undefined') {
            return new URLSearchParams(window.location.search).get('subtab') || 'geral-tipos-utilizador';
        }

        return 'geral-tipos-utilizador';
    });
    const [currentFinanceiroTab, setCurrentFinanceiroTab] = useState('financeiro-mensalidades');
    const [currentLogisticaTab, setCurrentLogisticaTab] = useState('logistica-artigos');
    const [currentNotificacoesTab, setCurrentNotificacoesTab] = useState('fontes-dinamicas');
    const [loadingRootTab, setLoadingRootTab] = useState<string | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<any>(null);
    const [productImagePreview, setProductImagePreview] = useState<string | null>(null);
    const [sponsorLogoPreview, setSponsorLogoPreview] = useState<string | null>(null);
    const [dynamicSourceDialogOpen, setDynamicSourceDialogOpen] = useState(false);
    const [editingDynamicSource, setEditingDynamicSource] = useState<CommunicationDynamicSource | null>(null);
    const [alertCategoryDialogOpen, setAlertCategoryDialogOpen] = useState(false);
    const [editingAlertCategory, setEditingAlertCategory] = useState<CommunicationAlertCategory | null>(null);
    const dynamicSourceForm = useForm({
        name: '',
        description: '',
        strategy: 'all_members',
        sort_order: 0,
        is_active: true,
    });
    const alertCategoryForm = useForm({
        code: '',
        name: '',
        description: '',
        channels: ['email', 'alert_app'] as string[],
        sort_order: 0,
        is_active: true,
    });
    const notificationPrefsForm = useForm<NotificationPrefs>({
        email_notificacoes: initialNotificationPrefs?.email_notificacoes ?? true,
        alertas_pagamento: initialNotificationPrefs?.alertas_pagamento ?? true,
        alertas_atividade: initialNotificationPrefs?.alertas_atividade ?? true,
        automacoes_financeiro: initialNotificationPrefs?.automacoes_financeiro ?? true,
        automacoes_eventos: initialNotificationPrefs?.automacoes_eventos ?? true,
        automacoes_logistica: initialNotificationPrefs?.automacoes_logistica ?? true,
        automacoes_faturas_financeiras: initialNotificationPrefs?.automacoes_faturas_financeiras ?? true,
        automacoes_movimentos_financeiros: initialNotificationPrefs?.automacoes_movimentos_financeiros ?? true,
        automacoes_convocatorias_eventos: initialNotificationPrefs?.automacoes_convocatorias_eventos ?? true,
        automacoes_requisicoes_logistica: initialNotificationPrefs?.automacoes_requisicoes_logistica ?? true,
        automacoes_alertas_operacionais: initialNotificationPrefs?.automacoes_alertas_operacionais ?? true,
    });

    // Generic form for CRUD operations
    const { data, setData, post, put, delete: destroy, reset, clearErrors, processing, errors } = useForm<any>({});

    const moduleOptions = buildOptions(
        permissionCatalog.map((item) => ({ value: item.value, label: item.label }))
    );

    const selectedModule = permissionCatalog.find((item) => item.value === data.modulo);
    const submoduleOptions = buildOptions(
        (selectedModule?.submodules || []).map((item) => ({ value: item.value, label: item.label }))
    );

    const selectedSubmodule = selectedModule?.submodules.find((item) => item.value === data.submodulo);
    const separatorOptions = buildOptions(
        (selectedSubmodule?.separators || []).map((item) => ({ value: item.value, label: item.label }))
    );

    const selectedSeparator = selectedSubmodule?.separators.find((item) => item.value === data.separador);
    const fieldOptions = buildOptions(
        (selectedSeparator?.fields || []).map((item) => ({ value: item, label: item }))
    );

    const getSelectValue = (value: string | undefined | null, options: Array<{ value: string }>) => {
        if (!value) return '';
        return options.some((option) => option.value === value) ? value : customValue;
    };

    // Club settings form
    const clubForm = useForm({
        nome_clube: clubSettings?.nome_clube || '',
        sigla: clubSettings?.sigla || '',
        morada: clubSettings?.morada || '',
        codigo_postal: clubSettings?.codigo_postal || '',
        localidade: clubSettings?.localidade || '',
        telefone: clubSettings?.telefone || '',
        email: clubSettings?.email || '',
        website: clubSettings?.website || '',
        nif: clubSettings?.nif || '',
        logo_url: clubSettings?.logo_url || '',
        iban: clubSettings?.iban || '',
        monthly_fee_generation_enabled: clubSettings?.monthly_fee_generation_enabled ?? true,
        monthly_fee_start_month: clubSettings?.monthly_fee_start_month ?? 9,
        monthly_fee_end_month: clubSettings?.monthly_fee_end_month ?? 7,
        monthly_fee_due_day: clubSettings?.monthly_fee_due_day ?? 1,
        monthly_fee_hide_future: clubSettings?.monthly_fee_hide_future ?? true,
        monthly_fee_auto_activate_due: clubSettings?.monthly_fee_auto_activate_due ?? true,
        monthly_fee_respect_registration_date: clubSettings?.monthly_fee_respect_registration_date ?? true,
        monthly_fee_generate_months_ahead: clubSettings?.monthly_fee_generate_months_ahead ?? '',
        monthly_fee_default_period_mode: clubSettings?.monthly_fee_default_period_mode || 'financial_cycle',
        logo: null as File | null,
    });

    const [logoPreview, setLogoPreview] = useState<string | null>(clubSettings?.logo_url || null);
    const monthOptions = [
        { value: 1, label: 'Janeiro' },
        { value: 2, label: 'Fevereiro' },
        { value: 3, label: 'Marco' },
        { value: 4, label: 'Abril' },
        { value: 5, label: 'Maio' },
        { value: 6, label: 'Junho' },
        { value: 7, label: 'Julho' },
        { value: 8, label: 'Agosto' },
        { value: 9, label: 'Setembro' },
        { value: 10, label: 'Outubro' },
        { value: 11, label: 'Novembro' },
        { value: 12, label: 'Dezembro' },
    ];
    const settingsViewportClass = 'flex h-[calc(100dvh-10rem)] min-h-0 w-full flex-col sm:h-[calc(100dvh-11rem)]';
    const rootTabsClass = 'flex h-full min-h-0 flex-col space-y-3';
    const sectionTabsClass = 'flex h-full min-h-0 flex-col space-y-4';
    const scrollableTabContentClass = 'mt-0 min-h-0 flex-1 overflow-auto pr-1';
    const nestedScrollableTabContentClass = 'min-h-0 flex-1 overflow-auto pr-1';
    const hasMonthlyFees = Object.prototype.hasOwnProperty.call(page.props, 'monthlyFees');
    const hasInvoiceTypes = Object.prototype.hasOwnProperty.call(page.props, 'invoiceTypes');
    const hasCostCenters = Object.prototype.hasOwnProperty.call(page.props, 'costCenters');
    const hasProducts = Object.prototype.hasOwnProperty.call(page.props, 'products');
    const hasSponsors = Object.prototype.hasOwnProperty.call(page.props, 'sponsors');
    const hasSuppliers = Object.prototype.hasOwnProperty.call(page.props, 'suppliers');
    const hasItemCategories = Object.prototype.hasOwnProperty.call(page.props, 'itemCategories');
    const hasNotificationPrefs = Object.prototype.hasOwnProperty.call(page.props, 'notificationPrefs');
    const hasCommunicationDynamicSources = Object.prototype.hasOwnProperty.call(page.props, 'communicationDynamicSources');
    const hasCommunicationAlertCategories = Object.prototype.hasOwnProperty.call(page.props, 'communicationAlertCategories');
    const hasUsers = Object.prototype.hasOwnProperty.call(page.props, 'users');
    const hasTrainingTypes = Object.prototype.hasOwnProperty.call(page.props, 'trainingTypes');
    const hasTrainingZones = Object.prototype.hasOwnProperty.call(page.props, 'trainingZones');
    const hasInjuryReasons = Object.prototype.hasOwnProperty.call(page.props, 'injuryReasons');
    const hasPoolTypes = Object.prototype.hasOwnProperty.call(page.props, 'poolTypes');
    const hasProvaTipos = Object.prototype.hasOwnProperty.call(page.props, 'provaTipos');

    useEffect(() => {
        if (clubSettings?.logo_url) {
            setLogoPreview(clubSettings.logo_url);
            return;
        }

        setLogoPreview(null);
    }, [clubSettings?.logo_url]);

    useEffect(() => {
        clubForm.setData({
            nome_clube: clubSettings?.nome_clube || '',
            sigla: clubSettings?.sigla || '',
            morada: clubSettings?.morada || '',
            codigo_postal: clubSettings?.codigo_postal || '',
            localidade: clubSettings?.localidade || '',
            telefone: clubSettings?.telefone || '',
            email: clubSettings?.email || '',
            website: clubSettings?.website || '',
            nif: clubSettings?.nif || '',
            logo_url: clubSettings?.logo_url || '',
            iban: clubSettings?.iban || '',
            monthly_fee_generation_enabled: clubSettings?.monthly_fee_generation_enabled ?? true,
            monthly_fee_start_month: clubSettings?.monthly_fee_start_month ?? 9,
            monthly_fee_end_month: clubSettings?.monthly_fee_end_month ?? 7,
            monthly_fee_due_day: clubSettings?.monthly_fee_due_day ?? 1,
            monthly_fee_hide_future: clubSettings?.monthly_fee_hide_future ?? true,
            monthly_fee_auto_activate_due: clubSettings?.monthly_fee_auto_activate_due ?? true,
            monthly_fee_respect_registration_date: clubSettings?.monthly_fee_respect_registration_date ?? true,
            monthly_fee_generate_months_ahead: clubSettings?.monthly_fee_generate_months_ahead ?? '',
            monthly_fee_default_period_mode: clubSettings?.monthly_fee_default_period_mode || 'financial_cycle',
            logo: null,
        });
    }, [
        clubSettings?.codigo_postal,
        clubSettings?.email,
        clubSettings?.iban,
        clubSettings?.localidade,
        clubSettings?.logo_url,
        clubSettings?.morada,
        clubSettings?.monthly_fee_auto_activate_due,
        clubSettings?.monthly_fee_default_period_mode,
        clubSettings?.monthly_fee_due_day,
        clubSettings?.monthly_fee_end_month,
        clubSettings?.monthly_fee_generate_months_ahead,
        clubSettings?.monthly_fee_generation_enabled,
        clubSettings?.monthly_fee_hide_future,
        clubSettings?.monthly_fee_respect_registration_date,
        clubSettings?.monthly_fee_start_month,
        clubSettings?.nif,
        clubSettings?.nome_clube,
        clubSettings?.sigla,
        clubSettings?.telefone,
        clubSettings?.website,
    ]);

    useEffect(() => {
        notificationPrefsForm.setData({
            email_notificacoes: initialNotificationPrefs?.email_notificacoes ?? true,
            alertas_pagamento: initialNotificationPrefs?.alertas_pagamento ?? true,
            alertas_atividade: initialNotificationPrefs?.alertas_atividade ?? true,
            automacoes_financeiro: initialNotificationPrefs?.automacoes_financeiro ?? true,
            automacoes_eventos: initialNotificationPrefs?.automacoes_eventos ?? true,
            automacoes_logistica: initialNotificationPrefs?.automacoes_logistica ?? true,
            automacoes_faturas_financeiras: initialNotificationPrefs?.automacoes_faturas_financeiras ?? true,
            automacoes_movimentos_financeiros: initialNotificationPrefs?.automacoes_movimentos_financeiros ?? true,
            automacoes_convocatorias_eventos: initialNotificationPrefs?.automacoes_convocatorias_eventos ?? true,
            automacoes_requisicoes_logistica: initialNotificationPrefs?.automacoes_requisicoes_logistica ?? true,
            automacoes_alertas_operacionais: initialNotificationPrefs?.automacoes_alertas_operacionais ?? true,
        });
    }, [initialNotificationPrefs]);

    useEffect(() => {
        const pendingByTab: Record<string, { ready: boolean; props: string[] }> = {
            financeiro: { ready: hasMonthlyFees && hasInvoiceTypes && hasCostCenters, props: ['monthlyFees', 'invoiceTypes', 'costCenters'] },
            logistica: { ready: hasProducts && hasSponsors && hasSuppliers && hasItemCategories, props: ['products', 'sponsors', 'suppliers', 'itemCategories'] },
            notificacoes: { ready: hasNotificationPrefs && hasCommunicationDynamicSources && hasCommunicationAlertCategories, props: ['notificationPrefs', 'communicationDynamicSources', 'communicationAlertCategories'] },
            'base-dados': { ready: hasUsers, props: ['users'] },
            desportivo: { ready: hasTrainingTypes && hasTrainingZones && hasInjuryReasons && hasPoolTypes && hasProvaTipos, props: ['trainingTypes', 'trainingZones', 'injuryReasons', 'poolTypes', 'provaTipos'] },
        };

        const pending = pendingByTab[currentTab];

        if (!pending || pending.ready) {
            setLoadingRootTab((current) => (current === currentTab ? null : current));
            return;
        }

        setLoadingRootTab(currentTab);
        router.reload({
            only: pending.props,
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoadingRootTab((current) => (current === currentTab ? null : current)),
        });
    }, [
        currentTab,
        hasCommunicationAlertCategories,
        hasCommunicationDynamicSources,
        hasCostCenters,
        hasInvoiceTypes,
        hasInjuryReasons,
        hasItemCategories,
        hasMonthlyFees,
        hasNotificationPrefs,
        hasPoolTypes,
        hasProducts,
        hasProvaTipos,
        hasSponsors,
        hasSuppliers,
        hasTrainingTypes,
        hasTrainingZones,
        hasUsers,
    ]);

    const openAddDialog = (type: string) => {
        reset();
        clearErrors();
        if (type === 'sponsor') {
            setData('tipo', 'secundario');
            setData('estado', 'ativo');
            setData('data_inicio', new Date().toISOString().slice(0, 10));
            setData('valor_anual', '');
            setData('logo', null);
            setSponsorLogoPreview(null);
        }
        if (type === 'product') {
            setData('ativo', true);
            setData('visible_in_store', false);
            setData('stock_minimo', 0);
            setData('area_armazenamento', '');
        }
        if (type === 'product') setProductImagePreview(null);
        if (type === 'item-category') {
            setData('ativo', true);
        }
        if (type === 'cost-center') {
            setData({
                codigo: '',
                nome: '',
                tipo: 'departamento',
                descricao: '',
                orcamento: '',
                ativo: true,
            });
        }
        setEditingItem({ type });
        setDialogOpen(true);
    };

    const openEditDialog = (item: any, type: string) => {
        clearErrors();
        if (type === 'sponsor') {
            setData({ ...item, logo: null });
            setSponsorLogoPreview(item.logo || null);
        } else {
            setData(item);
        }
        setEditingItem({ ...item, type });
        if (type === 'product') {
            setProductImagePreview(item.imagem || null);
        }
        setDialogOpen(true);
    };

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        
        if (!editingItem) return;

        const type = editingItem.type;
        const isEditing = !!editingItem.id;
        const shouldUseMethodSpoofing = type === 'product' && isEditing;

        if (shouldUseMethodSpoofing) {
            setData('_method', 'put');
        }

        const routes: Record<string, string> = {
            'age-group': isEditing 
                ? route('configuracoes.escaloes.update', editingItem.id)
                : route('configuracoes.escaloes.store'),
            'user-type': isEditing 
                ? route('configuracoes.tipos-utilizador.update', editingItem.id)
                : route('configuracoes.tipos-utilizador.store'),
            'event-type': isEditing 
                ? route('configuracoes.tipos-evento.update', editingItem.id)
                : route('configuracoes.tipos-evento.store'),
            'permission': isEditing
                ? route('configuracoes.permissoes.update', editingItem.id)
                : route('configuracoes.permissoes.store'),
            'monthly-fee': isEditing
                ? route('configuracoes.mensalidades.update', editingItem.id)
                : route('configuracoes.mensalidades.store'),
            'invoice-type': isEditing
                ? route('configuracoes.tipos-fatura.update', editingItem.id)
                : route('configuracoes.tipos-fatura.store'),
            'cost-center': isEditing
                ? route('configuracoes.centros-custo.update', editingItem.id)
                : route('configuracoes.centros-custo.store'),
            'product': isEditing
                ? route('configuracoes.artigos.update', editingItem.id)
                : route('configuracoes.artigos.store'),
            'sponsor': isEditing
                ? route('configuracoes.patrocinadores.update', editingItem.id)
                : route('configuracoes.patrocinadores.store'),
            'item-category': isEditing
                ? route('configuracoes.categorias-itens.update', editingItem.id)
                : route('configuracoes.categorias-itens.store'),
            'supplier': isEditing
                ? route('configuracoes.fornecedores.update', editingItem.id)
                : route('configuracoes.fornecedores.store'),
            'prova-tipo': isEditing
                ? route('configuracoes.provas.update', editingItem.id)
                : route('configuracoes.provas.store'),
            'athlete-status': isEditing
                ? route('configuracoes.desportivo.estados-atleta.update', editingItem.id)
                : route('configuracoes.desportivo.estados-atleta.store'),
            'absence-reason': isEditing
                ? route('configuracoes.desportivo.motivos-ausencia.update', editingItem.id)
                : route('configuracoes.desportivo.motivos-ausencia.store'),
        };

        const options = {
            ...((type === 'product' || type === 'sponsor') && { forceFormData: true }),
            onSuccess: () => {
                setDialogOpen(false);
                reset();
                setEditingItem(null);
                if (type === 'product') setProductImagePreview(null);
                if (type === 'sponsor') setSponsorLogoPreview(null);
                toast.success(isEditing ? 'Atualizado com sucesso!' : 'Criado com sucesso!');
            },
            onError: (formErrors: Record<string, string>) => {
                const firstError = Object.values(formErrors)[0];
                toast.error(firstError || 'Erro ao processar a operação.');
            },
        };

        if (shouldUseMethodSpoofing) {
            post(routes[type], options);
        } else if (isEditing) {
            put(routes[type], options);
        } else {
            post(routes[type], options);
        }
    };

    const handleDelete = (id: string, type: string) => {
        if (!confirm('Tem certeza que deseja eliminar este item?')) return;

        const routes: Record<string, string> = {
            'age-group': route('configuracoes.escaloes.destroy', id),
            'user-type': route('configuracoes.tipos-utilizador.destroy', id),
            'event-type': route('configuracoes.tipos-evento.destroy', id),
            'permission': route('configuracoes.permissoes.destroy', id),
            'monthly-fee': route('configuracoes.mensalidades.destroy', id),
            'invoice-type': route('configuracoes.tipos-fatura.destroy', id),
            'cost-center': route('configuracoes.centros-custo.destroy', id),
            'product': route('configuracoes.artigos.destroy', id),
            'sponsor': route('configuracoes.patrocinadores.destroy', id),
            'item-category': route('configuracoes.categorias-itens.destroy', id),
            'supplier': route('configuracoes.fornecedores.destroy', id),
            'prova-tipo': route('configuracoes.provas.destroy', id),
            'athlete-status': route('configuracoes.desportivo.estados-atleta.destroy', id),
            'absence-reason': route('configuracoes.desportivo.motivos-ausencia.destroy', id),
        };

        router.delete(routes[type], {
            onSuccess: () => toast.success('Eliminado com sucesso!'),
            onError: () => toast.error('Erro ao eliminar.'),
        });
    };

    const handleSaveClubSettings: FormEventHandler = (e) => {
        e.preventDefault();

        clubForm.transform((formData) => ({
            ...formData,
            _method: 'put',
        }));

        clubForm.post(route('configuracoes.clube.update'), {
            forceFormData: true,
            onSuccess: () => toast.success('Configurações do clube atualizadas com sucesso!'),
            onError: (formErrors) => {
                const firstError = Object.values(formErrors)[0];
                toast.error(firstError || 'Erro ao atualizar configurações.');
            },
        });
    };

    const resetFinancialCycleDefaults = () => {
        clubForm.setData('monthly_fee_generation_enabled', true);
        clubForm.setData('monthly_fee_start_month', 9);
        clubForm.setData('monthly_fee_end_month', 7);
        clubForm.setData('monthly_fee_due_day', 1);
        clubForm.setData('monthly_fee_hide_future', true);
        clubForm.setData('monthly_fee_auto_activate_due', true);
        clubForm.setData('monthly_fee_respect_registration_date', true);
        clubForm.setData('monthly_fee_generate_months_ahead', '');
        clubForm.setData('monthly_fee_default_period_mode', 'financial_cycle');
    };

    const openCreateDynamicSource = () => {
        setEditingDynamicSource(null);
        dynamicSourceForm.setData({
            name: '',
            description: '',
            strategy: 'all_members',
            sort_order: communicationDynamicSources.length + 1,
            is_active: true,
        });
        dynamicSourceForm.clearErrors();
        setDynamicSourceDialogOpen(true);
    };

    const openEditDynamicSource = (source: CommunicationDynamicSource) => {
        setEditingDynamicSource(source);
        dynamicSourceForm.setData({
            name: source.name,
            description: source.description || '',
            strategy: source.strategy,
            sort_order: source.sort_order,
            is_active: source.is_active,
        });
        dynamicSourceForm.clearErrors();
        setDynamicSourceDialogOpen(true);
    };

    const saveDynamicSource = () => {
        const isEditing = !!editingDynamicSource;
        const routeName = isEditing
            ? route('configuracoes.notificacoes.fontes-dinamicas.update', editingDynamicSource.id)
            : route('configuracoes.notificacoes.fontes-dinamicas.store');

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setDynamicSourceDialogOpen(false);
                setEditingDynamicSource(null);
                dynamicSourceForm.reset();
                toast.success(isEditing ? 'Fonte dinâmica atualizada' : 'Fonte dinâmica criada');
            },
            onError: () => toast.error('Erro ao guardar fonte dinâmica.'),
        };

        if (isEditing) {
            dynamicSourceForm.put(routeName, options);
            return;
        }

        dynamicSourceForm.post(routeName, options);
    };

    const deleteDynamicSource = (sourceId: string) => {
        if (!confirm('Tem certeza que deseja eliminar esta fonte dinâmica?')) return;

        router.delete(route('configuracoes.notificacoes.fontes-dinamicas.destroy', sourceId), {
            preserveScroll: true,
            onSuccess: () => toast.success('Fonte dinâmica eliminada'),
            onError: () => toast.error('Erro ao eliminar fonte dinâmica.'),
        });
    };

    const openCreateAlertCategory = () => {
        setEditingAlertCategory(null);
        alertCategoryForm.setData({
            code: '',
            name: '',
            description: '',
            channels: ['email', 'alert_app'],
            sort_order: communicationAlertCategories.length + 1,
            is_active: true,
        });
        alertCategoryForm.clearErrors();
        setAlertCategoryDialogOpen(true);
    };

    const openEditAlertCategory = (category: CommunicationAlertCategory) => {
        setEditingAlertCategory(category);
        alertCategoryForm.setData({
            code: category.code,
            name: category.name,
            description: category.description || '',
            channels: category.channels || [],
            sort_order: category.sort_order,
            is_active: category.is_active,
        });
        alertCategoryForm.clearErrors();
        setAlertCategoryDialogOpen(true);
    };

    const toggleAlertCategoryChannel = (channel: string, enabled: boolean) => {
        const current = new Set(alertCategoryForm.data.channels);

        if (enabled) {
            current.add(channel);
        } else {
            current.delete(channel);
        }

        alertCategoryForm.setData('channels', Array.from(current));
    };

    const saveAlertCategory = () => {
        const isEditing = !!editingAlertCategory;
        const routeName = isEditing
            ? route('configuracoes.notificacoes.categorias-alerta.update', editingAlertCategory.id)
            : route('configuracoes.notificacoes.categorias-alerta.store');

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setAlertCategoryDialogOpen(false);
                setEditingAlertCategory(null);
                alertCategoryForm.reset();
                toast.success(isEditing ? 'Categoria do alerta atualizada' : 'Categoria do alerta criada');
            },
            onError: () => toast.error('Erro ao guardar categoria do alerta.'),
        };

        if (isEditing) {
            alertCategoryForm.put(routeName, options);
            return;
        }

        alertCategoryForm.post(routeName, options);
    };

    const deleteAlertCategory = (categoryId: string) => {
        if (!confirm('Tem certeza que deseja eliminar esta categoria do alerta?')) return;

        router.delete(route('configuracoes.notificacoes.categorias-alerta.destroy', categoryId), {
            preserveScroll: true,
            onSuccess: () => toast.success('Categoria do alerta eliminada'),
            onError: () => toast.error('Erro ao eliminar categoria do alerta.'),
        });
    };

    const toggleNotificationPreference = (field: keyof NotificationPrefs, checked: boolean) => {
        notificationPrefsForm.setData(field, checked);
    };

    const saveNotificationPreferences = () => {
        notificationPrefsForm.put(route('configuracoes.notificacoes.update'), {
            preserveScroll: true,
            onSuccess: () => toast.success('Preferências de automação atualizadas'),
            onError: () => toast.error('Erro ao guardar preferências de automação.'),
        });
    };

    const handleExportUsers = () => {
        const dataStr = JSON.stringify(users, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `users-export-${new Date().toISOString()}.json`;
        link.click();
        URL.revokeObjectURL(url);
        toast.success('Dados exportados com sucesso');
    };

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Configurações</h1>
                        <p className="text-muted-foreground text-xs mt-0.5">Gerir definições do sistema</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {currentTab === 'clube' && (
                            <Button onClick={handleSaveClubSettings} disabled={clubForm.processing} size="sm">
                                <FloppyDisk className="mr-2" size={16} />
                                Guardar Alterações
                            </Button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Configurações" />

            <div className={settingsViewportClass}>
                <Tabs value={currentTab} onValueChange={setCurrentTab} className={rootTabsClass}>
                    <div className="w-full shrink-0 overflow-x-auto -mx-2 px-2 sm:mx-0 sm:px-0">
                        <TabsList className="inline-flex w-auto sm:grid sm:w-full sm:grid-cols-7 h-9 text-sm min-w-full sm:min-w-0">
                            <TabsTrigger value="geral" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Geral
                            </TabsTrigger>
                            <TabsTrigger value="clube" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Clube
                            </TabsTrigger>
                            <TabsTrigger value="desportivo" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Desportivo
                            </TabsTrigger>
                            <TabsTrigger value="financeiro" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Financeiro
                            </TabsTrigger>
                            <TabsTrigger value="logistica" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Logistica
                            </TabsTrigger>
                            <TabsTrigger value="notificacoes" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Notificações
                            </TabsTrigger>
                            <TabsTrigger value="base-dados" className="text-sm whitespace-nowrap px-3 sm:px-2">
                                Base de Dados
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    {/* Tab: Geral */}
                    <TabsContent value="geral" className="mt-0 min-h-0 flex-1 overflow-hidden">
                        {currentTab === 'geral' ? (
                        <Tabs value={currentGeneralTab} onValueChange={setCurrentGeneralTab} className={sectionTabsClass}>
                            <TabsList className="w-full shrink-0 flex flex-wrap h-auto gap-1 justify-start">
                                <TabsTrigger value="geral-tipos-utilizador">Tipos de Utilizador</TabsTrigger>
                                <TabsTrigger value="geral-tipos-evento">Tipos de Evento</TabsTrigger>
                                <TabsTrigger value="geral-permissoes">Permissões</TabsTrigger>
                                <TabsTrigger value="geral-estados">Estados</TabsTrigger>
                                <TabsTrigger value="geral-motivos-ausencia">Motivos Ausência</TabsTrigger>
                            </TabsList>


                        <TabsContent value="geral-tipos-utilizador" className={nestedScrollableTabContentClass}>
                        {currentGeneralTab === 'geral-tipos-utilizador' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Tipos de Utilizador</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir os tipos de utilizadores do sistema
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('user-type')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Tipo
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Descrição</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {userTypes.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={3} className="text-center text-muted-foreground">
                                                    Nenhum tipo de utilizador cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            userTypes.map((type) => (
                                                <TableRow key={type.id}>
                                                    <TableCell className="font-medium">{type.nome}</TableCell>
                                                    <TableCell>{type.descricao || '-'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(type, 'user-type')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(type.id, 'user-type')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="geral-tipos-evento" className={nestedScrollableTabContentClass}>
                        {currentGeneralTab === 'geral-tipos-evento' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Tipos de Evento</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir os tipos de eventos do sistema
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-4">
                                    <Button onClick={() => openAddDialog('event-type')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Tipo
                                    </Button>
                                </div>
                                {eventTypes.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        Nenhum tipo de evento cadastrado
                                    </div>
                                ) : (
                                    <div className="grid gap-1.5 sm:gap-2 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
                                        {eventTypes.map((type) => (
                                            <Card key={type.id} className="flex flex-col p-0 border">
                                                <div className="flex items-center justify-between gap-0 px-2 py-0 text-xs">
                                                    <div className="flex items-center gap-0 flex-1 min-w-0">
                                                        {type.cor && (
                                                            <div
                                                                className="w-2 h-2 rounded-full shrink-0 mr-0.5"
                                                                style={{ backgroundColor: type.cor }}
                                                            />
                                                        )}
                                                        <span className="truncate font-medium text-sm leading-none">{type.nome}</span>
                                                    </div>
                                                    {type.ativo && (
                                                        <span className="shrink-0 text-xs font-medium py-0 px-1.5 bg-blue-100 text-blue-700 rounded text-nowrap leading-none ml-0.5">
                                                            Ativo
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="px-2 py-0 -mt-0.5" style={{ lineHeight: '1' }}>
                                                    <div className="flex flex-wrap gap-0" style={{ fontSize: '11px' }}>
                                                        {type.gera_taxa && (
                                                            <span className="bg-amber-100 text-amber-700 px-0.5 py-0 rounded text-xs leading-none h-4 flex items-center mr-0.5">
                                                                Gera Taxa
                                                            </span>
                                                        )}
                                                        {type.permite_convocatoria && (
                                                            <span className="bg-blue-100 text-blue-700 px-0.5 py-0 rounded text-xs leading-none h-4 flex items-center mr-0.5">
                                                                Permite Conv.
                                                            </span>
                                                        )}
                                                        {type.gera_presencas && (
                                                            <span className="bg-purple-100 text-purple-700 px-0.5 py-0 rounded text-xs leading-none h-4 flex items-center mr-0.5">
                                                                Presenças
                                                            </span>
                                                        )}
                                                        {type.requer_transporte && (
                                                            <span className="bg-green-100 text-green-700 px-0.5 py-0 rounded text-xs leading-none h-4 flex items-center mr-0.5">
                                                                Transporte
                                                            </span>
                                                        )}
                                                        {type.visibilidade_default && (
                                                            <span className="bg-gray-100 text-gray-700 px-0.5 py-0 rounded text-xs leading-none h-4 flex items-center">
                                                                {type.visibilidade_default}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="border-t flex gap-1 px-2 py-0 mt-0">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="flex-1 h-5 text-xs py-0 px-1"
                                                        onClick={() => openEditDialog(type, 'event-type')}
                                                    >
                                                        <PencilSimple size={8} className="mr-0.5" />
                                                        Editar
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-5 w-5 py-0 px-0 flex items-center justify-center"
                                                        onClick={() => handleDelete(type.id, 'event-type')}
                                                    >
                                                        <Trash size={12} />
                                                    </Button>
                                                </div>
                                            </Card>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="geral-permissoes" className={nestedScrollableTabContentClass}>
                        {currentGeneralTab === 'geral-permissoes' ? (
                        <Suspense fallback={<TabFallback />}>
                        <UserTypePermissionSettings
                            userTypes={userTypes}
                            bootstrap={accessControlBootstrap}
                        />
                        </Suspense>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="geral-estados" className={nestedScrollableTabContentClass}>
                        {currentGeneralTab === 'geral-estados' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Estados</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir os estados de atletas disponiveis
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('athlete-status')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Estado
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Codigo</TableHead>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Cor</TableHead>
                                            <TableHead>Ativo</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {athleteStatuses.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center text-muted-foreground">
                                                    Nenhum estado cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            athleteStatuses.map((status) => (
                                                <TableRow key={status.id}>
                                                    <TableCell className="font-medium">{status.codigo}</TableCell>
                                                    <TableCell>{status.nome}</TableCell>
                                                    <TableCell>
                                                        {status.cor && typeof status.cor === 'string' ? (
                                                            <span className="inline-flex items-center gap-2">
                                                                <span className="h-3 w-3 rounded-full border" style={{ backgroundColor: status.cor }} />
                                                                {status.cor}
                                                            </span>
                                                        ) : '-'}
                                                    </TableCell>
                                                    <TableCell>{status.ativo ? 'Sim' : 'Nao'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(status, 'athlete-status')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(status.id, 'athlete-status')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="geral-motivos-ausencia" className={nestedScrollableTabContentClass}>
                        {currentGeneralTab === 'geral-motivos-ausencia' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Motivos de Ausência</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir os motivos de ausência disponiveis
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('absence-reason')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Motivo
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Codigo</TableHead>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Requer Justificação</TableHead>
                                            <TableHead>Ativo</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {absenceReasons.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center text-muted-foreground">
                                                    Nenhum motivo cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            absenceReasons.map((reason) => (
                                                <TableRow key={reason.id}>
                                                    <TableCell className="font-medium">{reason.codigo}</TableCell>
                                                    <TableCell>{reason.nome}</TableCell>
                                                    <TableCell>{reason.requer_justificacao ? 'Sim' : 'Nao'}</TableCell>
                                                    <TableCell>{reason.ativo ? 'Sim' : 'Nao'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(reason, 'absence-reason')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(reason.id, 'absence-reason')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>
                        </Tabs>
                        ) : null}
                    </TabsContent>

                    {/* Tab: Clube */}
                    <TabsContent value="clube" className={scrollableTabContentClass}>
                        {currentTab === 'clube' ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>Informações do Clube</CardTitle>
                                <CardDescription>
                                    Configure as informações básicas do clube
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleSaveClubSettings} className="space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="nome_clube">Nome do Clube *</Label>
                                            <Input
                                                id="nome_clube"
                                                value={clubForm.data.nome_clube}
                                                onChange={e => clubForm.setData('nome_clube', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="sigla">Sigla</Label>
                                            <Input
                                                id="sigla"
                                                value={clubForm.data.sigla}
                                                onChange={e => clubForm.setData('sigla', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="nif">NIF</Label>
                                            <Input
                                                id="nif"
                                                value={clubForm.data.nif}
                                                onChange={e => clubForm.setData('nif', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="telefone">Telefone</Label>
                                            <Input
                                                id="telefone"
                                                value={clubForm.data.telefone}
                                                onChange={e => clubForm.setData('telefone', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="email">Email</Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                value={clubForm.data.email}
                                                onChange={e => clubForm.setData('email', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="website">Website</Label>
                                            <Input
                                                id="website"
                                                type="url"
                                                value={clubForm.data.website}
                                                onChange={e => clubForm.setData('website', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="logo_url">Logotipo do Clube</Label>
                                            <input
                                                id="logo_url"
                                                type="file"
                                                accept="image/*"
                                                onChange={(e) => {
                                                    const file = e.target.files?.[0] || null;
                                                    clubForm.setData('logo', file);
                                                    if (file) {
                                                        setLogoPreview(URL.createObjectURL(file));
                                                    }
                                                }}
                                            />
                                            {logoPreview && (
                                                <img
                                                    src={logoPreview}
                                                    alt="Logotipo do clube"
                                                    className="h-16 w-auto rounded-md border"
                                                />
                                            )}
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="morada">Morada</Label>
                                            <Input
                                                id="morada"
                                                value={clubForm.data.morada}
                                                onChange={e => clubForm.setData('morada', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="codigo_postal">Código Postal</Label>
                                            <Input
                                                id="codigo_postal"
                                                value={clubForm.data.codigo_postal}
                                                onChange={e => clubForm.setData('codigo_postal', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="localidade">Localidade</Label>
                                            <Input
                                                id="localidade"
                                                value={clubForm.data.localidade}
                                                onChange={e => clubForm.setData('localidade', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="iban">IBAN</Label>
                                            <Input
                                                id="iban"
                                                value={clubForm.data.iban}
                                                onChange={e => clubForm.setData('iban', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                        ) : null}
                    </TabsContent>

                    {/* Tab: Financeiro */}
                    <TabsContent value="financeiro" className="mt-0 min-h-0 flex-1 overflow-hidden">
                        {currentTab === 'financeiro' ? (
                        !hasMonthlyFees || !hasInvoiceTypes || !hasCostCenters || loadingRootTab === 'financeiro' ? (
                        <TabFallback />
                        ) : (
                        <Tabs value={currentFinanceiroTab} onValueChange={setCurrentFinanceiroTab} className={sectionTabsClass}>
                            <TabsList className="w-full shrink-0 flex flex-wrap h-auto gap-1 justify-start">
                                <TabsTrigger value="financeiro-mensalidades">Mensalidades</TabsTrigger>
                                <TabsTrigger value="financeiro-tipos-fatura">Itens de Fatura</TabsTrigger>
                                <TabsTrigger value="financeiro-centros-custos">Centros de Custos</TabsTrigger>
                                <TabsTrigger value="financeiro-ciclo">Ciclo Financeiro</TabsTrigger>
                            </TabsList>

                        <TabsContent value="financeiro-mensalidades" className={nestedScrollableTabContentClass}>
                        {currentFinanceiroTab === 'financeiro-mensalidades' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Mensalidades</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir os valores das mensalidades por escalao
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('monthly-fee')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Mensalidade
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Escalao</TableHead>
                                            <TableHead>Valor</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {monthlyFees.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center text-muted-foreground">
                                                    Nenhuma mensalidade cadastrada
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            monthlyFees.map((fee) => {
                                                const ageGroup = ageGroups.find((group) => group.id === fee.age_group_id);
                                                return (
                                                    <TableRow key={fee.id}>
                                                        <TableCell className="font-medium">{fee.designacao}</TableCell>
                                                        <TableCell>{ageGroup?.nome || '-'}</TableCell>
                                                        <TableCell>€{toNumber(fee.valor).toFixed(2)}</TableCell>
                                                        <TableCell className="text-right">
                                                            <div className="flex justify-end gap-2">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => openEditDialog(fee, 'monthly-fee')}
                                                                >
                                                                    <PencilSimple size={16} />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => handleDelete(fee.id, 'monthly-fee')}
                                                                >
                                                                    <Trash size={16} />
                                                                </Button>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="financeiro-tipos-fatura" className={nestedScrollableTabContentClass}>
                        {currentFinanceiroTab === 'financeiro-tipos-fatura' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Itens de Fatura</CardTitle>
                                <CardDescription className="text-sm">
                                    Definir os tipos disponiveis na criacao de faturas
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('invoice-type')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Tipo
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Codigo</TableHead>
                                            <TableHead>Ativo</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoiceTypes.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center text-muted-foreground">
                                                    Nenhum tipo de fatura cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            invoiceTypes.map((type) => (
                                                <TableRow key={type.id}>
                                                    <TableCell className="font-medium">{type.nome}</TableCell>
                                                    <TableCell>{type.codigo}</TableCell>
                                                    <TableCell>{type.ativo ? 'Sim' : 'Nao'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(type, 'invoice-type')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(type.id, 'invoice-type')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="financeiro-ciclo" className={nestedScrollableTabContentClass}>
                        {currentFinanceiroTab === 'financeiro-ciclo' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Ciclo financeiro das mensalidades</CardTitle>
                                <CardDescription className="text-sm">
                                    Esta configuracao define o ciclo administrativo das mensalidades. Nao depende da epoca desportiva. A epoca desportiva continua reservada ao planeamento, treinos e competicoes.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleSaveClubSettings} className="space-y-6">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-3 rounded-lg border p-4 md:col-span-2">
                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <Label htmlFor="monthly_fee_generation_enabled" className="text-sm font-medium">Gerar mensalidades automaticamente</Label>
                                                    <p className="text-xs text-muted-foreground">Ativa a geracao automatica e manual com base no ciclo financeiro configurado.</p>
                                                </div>
                                                <Switch
                                                    id="monthly_fee_generation_enabled"
                                                    checked={clubForm.data.monthly_fee_generation_enabled}
                                                    onCheckedChange={(checked) => clubForm.setData('monthly_fee_generation_enabled', checked)}
                                                />
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="monthly_fee_start_month">Mes de inicio do ciclo</Label>
                                            <Select value={String(clubForm.data.monthly_fee_start_month)} onValueChange={(value) => clubForm.setData('monthly_fee_start_month', Number(value))}>
                                                <SelectTrigger id="monthly_fee_start_month">
                                                    <SelectValue placeholder="Selecionar mes" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {monthOptions.map((month) => (
                                                        <SelectItem key={month.value} value={String(month.value)}>{month.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="monthly_fee_end_month">Mes de fim do ciclo</Label>
                                            <Select value={String(clubForm.data.monthly_fee_end_month)} onValueChange={(value) => clubForm.setData('monthly_fee_end_month', Number(value))}>
                                                <SelectTrigger id="monthly_fee_end_month">
                                                    <SelectValue placeholder="Selecionar mes" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {monthOptions.map((month) => (
                                                        <SelectItem key={month.value} value={String(month.value)}>{month.label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="monthly_fee_due_day">Dia de vencimento</Label>
                                            <Select value={String(clubForm.data.monthly_fee_due_day)} onValueChange={(value) => clubForm.setData('monthly_fee_due_day', Number(value))}>
                                                <SelectTrigger id="monthly_fee_due_day">
                                                    <SelectValue placeholder="Selecionar dia" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {Array.from({ length: 28 }, (_, index) => index + 1).map((day) => (
                                                        <SelectItem key={day} value={String(day)}>{day}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="monthly_fee_default_period_mode">Modo do periodo</Label>
                                            <Select value={clubForm.data.monthly_fee_default_period_mode} onValueChange={(value) => clubForm.setData('monthly_fee_default_period_mode', value)}>
                                                <SelectTrigger id="monthly_fee_default_period_mode">
                                                    <SelectValue placeholder="Selecionar modo" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="financial_cycle">Ciclo financeiro</SelectItem>
                                                    <SelectItem value="custom">Manual/personalizado</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="monthly_fee_generate_months_ahead">Meses a gerar antecipadamente</Label>
                                            <Input
                                                id="monthly_fee_generate_months_ahead"
                                                type="number"
                                                min={1}
                                                max={24}
                                                value={clubForm.data.monthly_fee_generate_months_ahead}
                                                onChange={(e) => clubForm.setData('monthly_fee_generate_months_ahead', e.target.value === '' ? '' : Number(e.target.value))}
                                            />
                                            <p className="text-xs text-muted-foreground">Deixe vazio para gerar todo o ciclo financeiro.</p>
                                        </div>

                                        <div className="space-y-3 rounded-lg border p-4 md:col-span-2">
                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <Label htmlFor="monthly_fee_hide_future" className="text-sm font-medium">Ocultar mensalidades futuras</Label>
                                                    <p className="text-xs text-muted-foreground">As mensalidades futuras ficam ocultas ate chegarem ao vencimento.</p>
                                                </div>
                                                <Switch id="monthly_fee_hide_future" checked={clubForm.data.monthly_fee_hide_future} onCheckedChange={(checked) => clubForm.setData('monthly_fee_hide_future', checked)} />
                                            </div>

                                            <Separator />

                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <Label htmlFor="monthly_fee_auto_activate_due" className="text-sm font-medium">Ativar mensalidades vencidas automaticamente</Label>
                                                    <p className="text-xs text-muted-foreground">Quando o vencimento chega, as mensalidades ocultas passam a visiveis automaticamente.</p>
                                                </div>
                                                <Switch id="monthly_fee_auto_activate_due" checked={clubForm.data.monthly_fee_auto_activate_due} onCheckedChange={(checked) => clubForm.setData('monthly_fee_auto_activate_due', checked)} />
                                            </div>

                                            <Separator />

                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <Label htmlFor="monthly_fee_respect_registration_date" className="text-sm font-medium">Respeitar data de inscricao</Label>
                                                    <p className="text-xs text-muted-foreground">Evita gerar mensalidades anteriores ao inicio financeiro do utilizador.</p>
                                                </div>
                                                <Switch id="monthly_fee_respect_registration_date" checked={clubForm.data.monthly_fee_respect_registration_date} onCheckedChange={(checked) => clubForm.setData('monthly_fee_respect_registration_date', checked)} />
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                                        <Button type="button" variant="outline" onClick={resetFinancialCycleDefaults}>
                                            Repor defaults
                                        </Button>
                                        <Button type="submit" disabled={clubForm.processing}>
                                            Guardar configuracoes
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="financeiro-centros-custos" className={nestedScrollableTabContentClass}>
                        {currentFinanceiroTab === 'financeiro-centros-custos' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Centros de Custos</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir centros de custos para controlo financeiro
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('cost-center')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Centro de Custos
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Descricao</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {costCenters.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center text-muted-foreground">
                                                    Nenhum centro de custos cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            costCenters.map((center) => (
                                                <TableRow key={center.id}>
                                                    <TableCell className="font-medium">{center.nome}</TableCell>
                                                    <TableCell>{center.tipo || '-'}</TableCell>
                                                    <TableCell>{center.descricao || '-'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(center, 'cost-center')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(center.id, 'cost-center')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>
                        </Tabs>
                        )
                        ) : null}
                    </TabsContent>

                    {/* Tab: Logistica */}
                    <TabsContent value="logistica" className="mt-0 min-h-0 flex-1 overflow-hidden">
                        {currentTab === 'logistica' ? (
                        !hasProducts || !hasSponsors || !hasSuppliers || !hasItemCategories || loadingRootTab === 'logistica' ? (
                        <TabFallback />
                        ) : (
                        <Tabs value={currentLogisticaTab} onValueChange={setCurrentLogisticaTab} className={sectionTabsClass}>
                            <TabsList className="w-full shrink-0 flex flex-wrap h-auto gap-1 justify-start">
                                <TabsTrigger value="logistica-artigos">Artigos</TabsTrigger>
                                <TabsTrigger value="logistica-categorias">Categorias de Itens</TabsTrigger>
                                <TabsTrigger value="logistica-patrocinadores">Patrocinadores</TabsTrigger>
                                <TabsTrigger value="logistica-fornecedores">Fornecedores</TabsTrigger>
                            </TabsList>

                        <TabsContent value="logistica-artigos" className={nestedScrollableTabContentClass}>
                        {currentLogisticaTab === 'logistica-artigos' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Artigos</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir o catalogo de artigos do clube
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('product')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Artigo
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Codigo</TableHead>
                                            <TableHead>Imagem</TableHead>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Categoria</TableHead>
                                            <TableHead>Preco</TableHead>
                                            <TableHead>Stock Mínimo</TableHead>
                                            <TableHead>Área de armazenamento</TableHead>
                                            <TableHead>Visível na Loja</TableHead>
                                            <TableHead>Ativo</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {products.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={10} className="text-center text-muted-foreground">
                                                    Nenhum artigo cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            products.map((product) => (
                                                <TableRow key={product.id}>
                                                    <TableCell className="font-medium">{product.codigo}</TableCell>
                                                    <TableCell>
                                                        {product.imagem ? (
                                                            <img src={product.imagem} alt={product.nome} className="h-10 w-10 object-cover rounded" />
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>{product.nome}</TableCell>
                                                    <TableCell>{product.categoria || '-'}</TableCell>
                                                    <TableCell>€{Number(product.preco).toFixed(2)}</TableCell>
                                                    <TableCell>{product.stock_minimo ?? 0}</TableCell>
                                                    <TableCell>{product.area_armazenamento || '-'}</TableCell>
                                                    <TableCell>
                                                        <Badge variant={product.visible_in_store ? 'secondary' : 'outline'}>
                                                            {product.visible_in_store ? 'Sim' : 'Não'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant={product.ativo ? 'secondary' : 'outline'}>
                                                            {product.ativo ? 'Sim' : 'Não'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(product, 'product')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(product.id, 'product')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="logistica-categorias" className={nestedScrollableTabContentClass}>
                        {currentLogisticaTab === 'logistica-categorias' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Categorias de Itens</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir as categorias de itens do clube
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('item-category')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Categoria
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Codigo</TableHead>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Ativo</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {itemCategories.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center text-muted-foreground">
                                                    Nenhuma categoria cadastrada
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            itemCategories.map((category) => (
                                                <TableRow key={category.id}>
                                                    <TableCell className="font-medium">{category.codigo}</TableCell>
                                                    <TableCell>{category.nome}</TableCell>
                                                    <TableCell>
                                                        <Badge variant={category.ativo ? 'secondary' : 'outline'}>
                                                            {category.ativo ? 'Sim' : 'Não'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(category, 'item-category')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(category.id, 'item-category')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="logistica-patrocinadores" className={nestedScrollableTabContentClass}>
                        {currentLogisticaTab === 'logistica-patrocinadores' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Patrocinadores</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir a base central de patrocinadores usada no módulo de patrocínios
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('sponsor')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Patrocinador
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Logo</TableHead>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Contacto</TableHead>
                                            <TableHead>Email</TableHead>
                                            <TableHead>Website</TableHead>
                                            <TableHead>Valor anual</TableHead>
                                            <TableHead>Período</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {sponsors.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={10} className="text-center text-muted-foreground">
                                                    Nenhum patrocinador cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            sponsors.map((sponsor) => (
                                                <TableRow key={sponsor.id}>
                                                    <TableCell>
                                                        {sponsor.logo ? (
                                                            <img src={sponsor.logo} alt={sponsor.nome} className="h-10 w-10 rounded object-cover" />
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="font-medium">{sponsor.nome}</div>
                                                        <div className="text-xs text-muted-foreground line-clamp-1">{sponsor.descricao || 'Sem descrição'}</div>
                                                    </TableCell>
                                                    <TableCell className="capitalize">{sponsor.tipo}</TableCell>
                                                    <TableCell>{sponsor.contacto || '-'}</TableCell>
                                                    <TableCell>{sponsor.email || '-'}</TableCell>
                                                    <TableCell>
                                                        {sponsor.website ? (
                                                            <a href={sponsor.website} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                                                {sponsor.website}
                                                            </a>
                                                        ) : '-'}
                                                    </TableCell>
                                                    <TableCell>{sponsor.valor_anual ? `€${Number(sponsor.valor_anual).toFixed(2)}` : '-'}</TableCell>
                                                    <TableCell>
                                                        <div>{sponsor.data_inicio}</div>
                                                        <div className="text-xs text-muted-foreground">até {sponsor.data_fim || 'sem fim'}</div>
                                                    </TableCell>
                                                    <TableCell className="capitalize">{sponsor.estado}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(sponsor, 'sponsor')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(sponsor.id, 'sponsor')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>

                        <TabsContent value="logistica-fornecedores" className={nestedScrollableTabContentClass}>
                        {currentLogisticaTab === 'logistica-fornecedores' ? (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Fornecedores</CardTitle>
                                <CardDescription className="text-sm">
                                    Gerir fornecedores e parceiros do clube
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex justify-end mb-3">
                                    <Button onClick={() => openAddDialog('supplier')} size="sm">
                                        <Plus className="mr-2" size={16} />
                                        Adicionar Fornecedor
                                    </Button>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nome</TableHead>
                                            <TableHead>NIF</TableHead>
                                            <TableHead>Email</TableHead>
                                            <TableHead>Telefone</TableHead>
                                            <TableHead>Categoria</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {suppliers.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="text-center text-muted-foreground">
                                                    Nenhum fornecedor cadastrado
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            suppliers.map((supplier) => (
                                                <TableRow key={supplier.id}>
                                                    <TableCell className="font-medium">{supplier.nome}</TableCell>
                                                    <TableCell>{supplier.nif || '-'}</TableCell>
                                                    <TableCell>{supplier.email || '-'}</TableCell>
                                                    <TableCell>{supplier.telefone || '-'}</TableCell>
                                                    <TableCell>{supplier.categoria || '-'}</TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(supplier, 'supplier')}
                                                            >
                                                                <PencilSimple size={16} />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleDelete(supplier.id, 'supplier')}
                                                            >
                                                                <Trash size={16} />
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        ) : null}
                        </TabsContent>
                        </Tabs>
                        )
                        ) : null}
                    </TabsContent>

                    {/* Tab: Notificações */}
                    <TabsContent value="notificacoes" className="mt-0 min-h-0 flex-1 overflow-hidden">
                        {currentTab === 'notificacoes' ? (
                        !hasNotificationPrefs || !hasCommunicationDynamicSources || !hasCommunicationAlertCategories || loadingRootTab === 'notificacoes' ? (
                        <TabFallback />
                        ) : (
                        <Tabs value={currentNotificacoesTab} onValueChange={setCurrentNotificacoesTab} className={sectionTabsClass}>
                            <TabsList className="w-full shrink-0 flex flex-wrap h-auto gap-1 justify-start">
                                <TabsTrigger value="automacoes">Automações</TabsTrigger>
                                <TabsTrigger value="fontes-dinamicas">Fontes Dinâmicas</TabsTrigger>
                                <TabsTrigger value="categorias-alerta">Categoria do Alerta</TabsTrigger>
                            </TabsList>

                            <TabsContent value="automacoes" className={nestedScrollableTabContentClass}>
                                {currentNotificacoesTab === 'automacoes' ? (
                                <Card>
                                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <CardTitle>Automações da Comunicação</CardTitle>
                                            <CardDescription>
                                                Ativa ou desativa as comunicações automáticas por origem e por fluxo específico.
                                            </CardDescription>
                                        </div>
                                        <Button size="sm" onClick={saveNotificationPreferences} disabled={notificationPrefsForm.processing}>
                                            {notificationPrefsForm.processing ? 'A guardar...' : 'Guardar preferências'}
                                        </Button>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                            {[
                                                ['email_notificacoes', 'Email automático', 'Permite envios automáticos por email.'],
                                                ['alertas_pagamento', 'Categoria Financeiro', 'Ativa alertas automáticos ligados a pagamentos e cobranças.'],
                                                ['alertas_atividade', 'Categoria Atividade', 'Ativa alertas automáticos ligados a eventos e atividade.'],
                                                ['automacoes_financeiro', 'Origem Financeiro', 'Ativa a origem automática do módulo Financeiro.'],
                                                ['automacoes_eventos', 'Origem Eventos', 'Ativa a origem automática do módulo Eventos.'],
                                                ['automacoes_logistica', 'Origem Logística', 'Ativa a origem automática do módulo Logística.'],
                                                ['automacoes_faturas_financeiras', 'Faturas', 'Notifica emissão automática de novas faturas.'],
                                                ['automacoes_movimentos_financeiros', 'Movimentos', 'Notifica criação automática de novos movimentos financeiros.'],
                                                ['automacoes_convocatorias_eventos', 'Convocatórias', 'Notifica convocatórias criadas para eventos.'],
                                                ['automacoes_requisicoes_logistica', 'Requisições logística', 'Notifica criação e alteração de estado de requisições logísticas.'],
                                                ['automacoes_alertas_operacionais', 'Alertas operacionais', 'Cria alertas internos para compras de fornecedor e fluxos operacionais.'],
                                            ].map(([field, label, description]) => (
                                                <div key={field} className="flex items-center justify-between rounded-md border px-3 py-3">
                                                    <div>
                                                        <Label>{label}</Label>
                                                        <p className="text-xs text-muted-foreground">{description}</p>
                                                    </div>
                                                    <Switch
                                                        checked={notificationPrefsForm.data[field as keyof NotificationPrefs] as boolean}
                                                        onCheckedChange={(checked) => toggleNotificationPreference(field as keyof NotificationPrefs, checked)}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                                ) : null}
                            </TabsContent>

                            <TabsContent value="fontes-dinamicas" className={nestedScrollableTabContentClass}>
                                {currentNotificacoesTab === 'fontes-dinamicas' ? (
                                <Card>
                                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <CardTitle>Fontes Dinâmicas</CardTitle>
                                            <CardDescription>
                                                Gere as fontes dinâmicas usadas na criação de segmentos do módulo Comunicação.
                                            </CardDescription>
                                        </div>
                                        <Button size="sm" onClick={openCreateDynamicSource}>
                                            <Plus className="mr-2" size={16} />
                                            Nova fonte
                                        </Button>
                                    </CardHeader>
                                    <CardContent>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Nome</TableHead>
                                                    <TableHead>Estratégia</TableHead>
                                                    <TableHead>Ordem</TableHead>
                                                    <TableHead>Estado</TableHead>
                                                    <TableHead>Descrição</TableHead>
                                                    <TableHead className="text-right">Ações</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {communicationDynamicSources.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                                            Nenhuma fonte dinâmica configurada.
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    communicationDynamicSources.map((source) => (
                                                        <TableRow key={source.id}>
                                                            <TableCell className="font-medium">{source.name}</TableCell>
                                                            <TableCell>{dynamicSourceStrategyLabel(source.strategy)}</TableCell>
                                                            <TableCell>{source.sort_order}</TableCell>
                                                            <TableCell>
                                                                <Badge variant={source.is_active ? 'secondary' : 'destructive'}>
                                                                    {source.is_active ? 'Ativa' : 'Inativa'}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell className="max-w-[320px] text-xs text-muted-foreground">
                                                                {source.description || '-'}
                                                            </TableCell>
                                                            <TableCell className="text-right">
                                                                <div className="flex justify-end gap-2">
                                                                    <Button variant="ghost" size="sm" onClick={() => openEditDynamicSource(source)}>
                                                                        <PencilSimple size={16} />
                                                                    </Button>
                                                                    <Button variant="ghost" size="sm" onClick={() => deleteDynamicSource(source.id)}>
                                                                        <Trash size={16} />
                                                                    </Button>
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))
                                                )}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                                ) : null}
                            </TabsContent>

                            <TabsContent value="categorias-alerta" className={nestedScrollableTabContentClass}>
                                {currentNotificacoesTab === 'categorias-alerta' ? (
                                <Card>
                                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <CardTitle>Categoria do Alerta</CardTitle>
                                            <CardDescription>
                                                Defina as categorias disponíveis e os canais de comunicação permitidos para cada uma.
                                            </CardDescription>
                                        </div>
                                        <Button size="sm" onClick={openCreateAlertCategory}>
                                            <Plus className="mr-2" size={16} />
                                            Nova categoria
                                        </Button>
                                    </CardHeader>
                                    <CardContent>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Nome</TableHead>
                                                    <TableHead>Código</TableHead>
                                                    <TableHead>Canais</TableHead>
                                                    <TableHead>Ordem</TableHead>
                                                    <TableHead>Estado</TableHead>
                                                    <TableHead>Descrição</TableHead>
                                                    <TableHead className="text-right">Ações</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {communicationAlertCategories.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                                            Nenhuma categoria do alerta configurada.
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    communicationAlertCategories.map((category) => (
                                                        <TableRow key={category.id}>
                                                            <TableCell className="font-medium">{category.name}</TableCell>
                                                            <TableCell>{category.code}</TableCell>
                                                            <TableCell>
                                                                <div className="flex flex-wrap gap-1">
                                                                    {category.channels.map((channel) => (
                                                                        <Badge key={channel} variant="outline">{alertCategoryChannelLabel(channel)}</Badge>
                                                                    ))}
                                                                </div>
                                                            </TableCell>
                                                            <TableCell>{category.sort_order}</TableCell>
                                                            <TableCell>
                                                                <Badge variant={category.is_active ? 'secondary' : 'destructive'}>
                                                                    {category.is_active ? 'Ativa' : 'Inativa'}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell className="max-w-[280px] text-xs text-muted-foreground">
                                                                {category.description || '-'}
                                                            </TableCell>
                                                            <TableCell className="text-right">
                                                                <div className="flex justify-end gap-2">
                                                                    <Button variant="ghost" size="sm" onClick={() => openEditAlertCategory(category)}>
                                                                        <PencilSimple size={16} />
                                                                    </Button>
                                                                    <Button variant="ghost" size="sm" onClick={() => deleteAlertCategory(category.id)}>
                                                                        <Trash size={16} />
                                                                    </Button>
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))
                                                )}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                                ) : null}
                            </TabsContent>
                        </Tabs>
                        )
                        ) : null}
                    </TabsContent>

                    {/* Tab: Base de Dados */}
                    <TabsContent value="base-dados" className={scrollableTabContentClass}>
                        {currentTab === 'base-dados' ? (
                        !hasUsers || loadingRootTab === 'base-dados' ? (
                        <TabFallback />
                        ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle>Utilizadores na Base de Dados</CardTitle>
                                <CardDescription>
                                    Verificação da persistência dos dados de utilizadores
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex gap-2 mb-3">
                                    <Button onClick={handleExportUsers} variant="outline" size="sm">
                                        <FloppyDisk className="mr-2" size={16} />
                                        Exportar Utilizadores
                                    </Button>
                                </div>

                                <div className="space-y-4">
                                    <div>
                                        <Label className="text-sm font-medium">
                                            Total de utilizadores: {users.length}
                                        </Label>
                                    </div>

                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Nº Sócio</TableHead>
                                                <TableHead>Nome</TableHead>
                                                <TableHead>Email</TableHead>
                                                <TableHead>Perfil</TableHead>
                                                <TableHead>Estado</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {users.length === 0 ? (
                                                <TableRow>
                                                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                                                        Nenhum utilizador encontrado na base de dados
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                users.map((user) => (
                                                    <TableRow key={user.id}>
                                                        <TableCell className="font-medium">{user.numero_socio || '-'}</TableCell>
                                                        <TableCell>{user.nome_completo || '-'}</TableCell>
                                                        <TableCell>{user.email_utilizador || '-'}</TableCell>
                                                        <TableCell>{user.perfil || '-'}</TableCell>
                                                        <TableCell>{user.estado || '-'}</TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>
                        )
                        ) : null}
                    </TabsContent>

                    <TabsContent value="desportivo" className={scrollableTabContentClass}>
                        {currentTab === 'desportivo' ? (
                        !hasTrainingTypes || !hasTrainingZones || !hasInjuryReasons || !hasPoolTypes || !hasProvaTipos || loadingRootTab === 'desportivo' ? (
                        <TabFallback />
                        ) : (
                        <Suspense fallback={<TabFallback />}>
                        <ConfiguracoesDesportivoIndex
                            athleteStatuses={athleteStatuses}
                            trainingTypes={trainingTypes}
                            trainingZones={trainingZones}
                            absenceReasons={absenceReasons}
                            injuryReasons={injuryReasons}
                            poolTypes={poolTypes}
                            ageGroups={ageGroups}
                            provaTipos={provaTipos}
                            embedded
                            showSummary={false}
                        />
                        </Suspense>
                        )
                        ) : null}
                    </TabsContent>
                </Tabs>
            </div>

            <Dialog open={dynamicSourceDialogOpen} onOpenChange={setDynamicSourceDialogOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingDynamicSource ? 'Editar fonte dinâmica' : 'Nova fonte dinâmica'}</DialogTitle>
                        <DialogDescription>
                            Define o nome apresentado em Comunicação e a estratégia técnica usada para resolver os destinatários.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="dynamic-source-name">Nome</Label>
                            <Input
                                id="dynamic-source-name"
                                value={dynamicSourceForm.data.name}
                                onChange={(e) => dynamicSourceForm.setData('name', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="dynamic-source-strategy">Estratégia</Label>
                            <Select
                                value={dynamicSourceForm.data.strategy}
                                onValueChange={(value) => dynamicSourceForm.setData('strategy', value)}
                            >
                                <SelectTrigger id="dynamic-source-strategy">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {dynamicSourceStrategies.map((strategy) => (
                                        <SelectItem key={strategy.value} value={strategy.value}>
                                            {strategy.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="dynamic-source-order">Ordem</Label>
                                <Input
                                    id="dynamic-source-order"
                                    type="number"
                                    min={0}
                                    value={String(dynamicSourceForm.data.sort_order)}
                                    onChange={(e) => dynamicSourceForm.setData('sort_order', Number(e.target.value || 0))}
                                />
                            </div>
                            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                <div>
                                    <Label htmlFor="dynamic-source-active">Ativa</Label>
                                    <p className="text-xs text-muted-foreground">Disponível no módulo Comunicação</p>
                                </div>
                                <Switch
                                    id="dynamic-source-active"
                                    checked={dynamicSourceForm.data.is_active}
                                    onCheckedChange={(checked) => dynamicSourceForm.setData('is_active', checked)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="dynamic-source-description">Descrição</Label>
                            <Textarea
                                id="dynamic-source-description"
                                rows={3}
                                value={dynamicSourceForm.data.description}
                                onChange={(e) => dynamicSourceForm.setData('description', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDynamicSourceDialogOpen(false)} disabled={dynamicSourceForm.processing}>
                            Cancelar
                        </Button>
                        <Button onClick={saveDynamicSource} disabled={dynamicSourceForm.processing}>
                            {dynamicSourceForm.processing ? 'A guardar...' : 'Guardar fonte'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={alertCategoryDialogOpen} onOpenChange={setAlertCategoryDialogOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingAlertCategory ? 'Editar categoria do alerta' : 'Nova categoria do alerta'}</DialogTitle>
                        <DialogDescription>
                            Define a categoria e escolhe logo os canais de comunicação disponíveis para essa categoria.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="alert-category-name">Nome</Label>
                                <Input
                                    id="alert-category-name"
                                    value={alertCategoryForm.data.name}
                                    onChange={(e) => alertCategoryForm.setData('name', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="alert-category-code">Código</Label>
                                <Input
                                    id="alert-category-code"
                                    value={alertCategoryForm.data.code}
                                    onChange={(e) => alertCategoryForm.setData('code', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Canais permitidos</Label>
                            <div className="grid gap-2 sm:grid-cols-3">
                                {alertCategoryChannels.map((channel) => {
                                    const checked = alertCategoryForm.data.channels.includes(channel.value);

                                    return (
                                        <label key={channel.value} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={(value) => toggleAlertCategoryChannel(channel.value, value === true)}
                                            />
                                            <span>{channel.label}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="alert-category-order">Ordem</Label>
                                <Input
                                    id="alert-category-order"
                                    type="number"
                                    min={0}
                                    value={String(alertCategoryForm.data.sort_order)}
                                    onChange={(e) => alertCategoryForm.setData('sort_order', Number(e.target.value || 0))}
                                />
                            </div>
                            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                <div>
                                    <Label htmlFor="alert-category-active">Ativa</Label>
                                    <p className="text-xs text-muted-foreground">Disponível para seleção</p>
                                </div>
                                <Switch
                                    id="alert-category-active"
                                    checked={alertCategoryForm.data.is_active}
                                    onCheckedChange={(checked) => alertCategoryForm.setData('is_active', checked)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="alert-category-description">Descrição</Label>
                            <Textarea
                                id="alert-category-description"
                                rows={3}
                                value={alertCategoryForm.data.description}
                                onChange={(e) => alertCategoryForm.setData('description', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAlertCategoryDialogOpen(false)} disabled={alertCategoryForm.processing}>
                            Cancelar
                        </Button>
                        <Button onClick={saveAlertCategory} disabled={alertCategoryForm.processing}>
                            {alertCategoryForm.processing ? 'A guardar...' : 'Guardar categoria'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Edit/Add Dialog */}
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingItem?.id ? 'Editar' : 'Adicionar'}{' '}
                            {editingItem?.type === 'age-group' && 'Escalão'}
                            {editingItem?.type === 'user-type' && 'Tipo de Utilizador'}
                            {editingItem?.type === 'event-type' && 'Tipo de Evento'}
                            {editingItem?.type === 'permission' && 'Permissão'}
                            {editingItem?.type === 'monthly-fee' && 'Mensalidade'}
                            {editingItem?.type === 'invoice-type' && 'Tipo de Fatura'}
                            {editingItem?.type === 'cost-center' && 'Centro de Custos'}
                            {editingItem?.type === 'product' && 'Artigo'}
                            {editingItem?.type === 'sponsor' && 'Patrocinador'}
                            {editingItem?.type === 'item-category' && 'Categoria de Item'}
                            {editingItem?.type === 'supplier' && 'Fornecedor'}
                            {editingItem?.type === 'prova-tipo' && 'Prova'}
                            {editingItem?.type === 'athlete-status' && 'Estado'}
                            {editingItem?.type === 'absence-reason' && 'Motivo de Ausência'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit}>
                        <div className="space-y-4 py-4">
                            {editingItem?.type === 'cost-center' && Object.keys(errors).length > 0 && (
                                <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                    Verifique os campos obrigatórios antes de guardar.
                                </div>
                            )}

                            {editingItem?.type === 'age-group' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="idade_minima">Idade Mínima *</Label>
                                            <Input
                                                id="idade_minima"
                                                type="number"
                                                min="0"
                                                value={data.idade_minima || ''}
                                                onChange={e => setData('idade_minima', e.target.value ? parseInt(e.target.value, 10) : '')}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="idade_maxima">Idade Máxima *</Label>
                                            <Input
                                                id="idade_maxima"
                                                type="number"
                                                min="0"
                                                value={data.idade_maxima || ''}
                                                onChange={e => setData('idade_maxima', e.target.value ? parseInt(e.target.value, 10) : '')}
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descrição</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'user-type' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome do artigo *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descrição</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'event-type' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descrição</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="categoria">Categoria</Label>
                                        <Input
                                            id="categoria"
                                            value={data.categoria || ''}
                                            onChange={e => setData('categoria', e.target.value)}
                                            placeholder="ex: treino, competição, evento"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Cor</Label>
                                        <div className="flex gap-2">
                                            <Input
                                                type="color"
                                                value={data.cor || '#000000'}
                                                onChange={e => setData('cor', e.target.value)}
                                                className="w-20 h-10 cursor-pointer"
                                            />
                                            <Input
                                                value={data.cor || ''}
                                                onChange={e => setData('cor', e.target.value)}
                                                placeholder="#000000"
                                            />
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="icon">Ícone</Label>
                                        <Select
                                            value={data.icon || ''}
                                            onValueChange={(value) => setData('icon', value)}
                                        >
                                            <SelectTrigger id="icon">
                                                <SelectValue placeholder="Selecionar ícone" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="medal">🏅 Medal</SelectItem>
                                                <SelectItem value="dumbbell">💪 Dumbbell</SelectItem>
                                                <SelectItem value="users">👥 Users</SelectItem>
                                                <SelectItem value="swimmer">🏊 Swimmer</SelectItem>
                                                <SelectItem value="target">🎯 Target</SelectItem>
                                                <SelectItem value="trophy">🏆 Trophy</SelectItem>
                                                <SelectItem value="star">⭐ Star</SelectItem>
                                                <SelectItem value="zap">⚡ Zap</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="visibilidade_default">Visibilidade Padrão</Label>
                                        <Select
                                            value={data.visibilidade_default || 'publico'}
                                            onValueChange={(value) => setData('visibilidade_default', value)}
                                        >
                                            <SelectTrigger id="visibilidade_default">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="publico">🌍 Público</SelectItem>
                                                <SelectItem value="restrito">🔒 Restrito</SelectItem>
                                                <SelectItem value="privado">🔐 Privado</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-3 pt-4 border-t">
                                        <Label className="text-base font-semibold">Configurações</Label>
                                        <div className="space-y-3">
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="gera_taxa"
                                                    checked={data.gera_taxa ?? false}
                                                    onCheckedChange={checked => setData('gera_taxa', checked)}
                                                />
                                                <Label htmlFor="gera_taxa" className="font-normal cursor-pointer">Gera taxa de inscrição</Label>
                                            </div>
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="permite_convocatoria"
                                                    checked={data.permite_convocatoria ?? false}
                                                    onCheckedChange={checked => setData('permite_convocatoria', checked)}
                                                />
                                                <Label htmlFor="permite_convocatoria" className="font-normal cursor-pointer">Permite convocatória</Label>
                                            </div>
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="gera_presencas"
                                                    checked={data.gera_presencas ?? false}
                                                    onCheckedChange={checked => setData('gera_presencas', checked)}
                                                />
                                                <Label htmlFor="gera_presencas" className="font-normal cursor-pointer">Gera presenças</Label>
                                            </div>
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="requer_transporte"
                                                    checked={data.requer_transporte ?? false}
                                                    onCheckedChange={checked => setData('requer_transporte', checked)}
                                                />
                                                <Label htmlFor="requer_transporte" className="font-normal cursor-pointer">Requer transporte</Label>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center space-x-2 pt-4 border-t">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'permission' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="user_type_id">Tipo de Utilizador *</Label>
                                        <Select
                                            value={data.user_type_id || ''}
                                            onValueChange={(value) => setData('user_type_id', value)}
                                        >
                                            <SelectTrigger id="user_type_id">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {userTypes.map((type) => (
                                                    <SelectItem key={type.id} value={type.id}>
                                                        {type.nome}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="modulo">Módulo *</Label>
                                        <Select
                                            value={getSelectValue(data.modulo, moduleOptions)}
                                            onValueChange={(value) => {
                                                if (value === customValue) {
                                                    setData('modulo', '');
                                                } else {
                                                    setData('modulo', value);
                                                }
                                                setData('submodulo', '');
                                                setData('separador', '');
                                                setData('campo', '');
                                            }}
                                        >
                                            <SelectTrigger id="modulo">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {moduleOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {getSelectValue(data.modulo, moduleOptions) === customValue && (
                                            <Input
                                                id="modulo_custom"
                                                value={data.modulo || ''}
                                                onChange={e => setData('modulo', e.target.value)}
                                                placeholder="Introduzir módulo"
                                                required
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="submodulo">Submódulo</Label>
                                        <Select
                                            value={getSelectValue(data.submodulo, submoduleOptions)}
                                            onValueChange={(value) => {
                                                if (value === customValue) {
                                                    setData('submodulo', '');
                                                } else {
                                                    setData('submodulo', value);
                                                }
                                                setData('separador', '');
                                                setData('campo', '');
                                            }}
                                            disabled={!data.modulo}
                                        >
                                            <SelectTrigger id="submodulo">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {submoduleOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {getSelectValue(data.submodulo, submoduleOptions) === customValue && (
                                            <Input
                                                id="submodulo_custom"
                                                value={data.submodulo || ''}
                                                onChange={e => setData('submodulo', e.target.value)}
                                                placeholder="ex: atletas, faturas"
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="separador">Separador</Label>
                                        <Select
                                            value={getSelectValue(data.separador, separatorOptions)}
                                            onValueChange={(value) => {
                                                if (value === customValue) {
                                                    setData('separador', '');
                                                } else {
                                                    setData('separador', value);
                                                }
                                                setData('campo', '');
                                            }}
                                            disabled={!data.submodulo}
                                        >
                                            <SelectTrigger id="separador">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {separatorOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {getSelectValue(data.separador, separatorOptions) === customValue && (
                                            <Input
                                                id="separador_custom"
                                                value={data.separador || ''}
                                                onChange={e => setData('separador', e.target.value)}
                                                placeholder="ex: geral, financeiro"
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="campo">Campo</Label>
                                        <Select
                                            value={getSelectValue(data.campo, fieldOptions)}
                                            onValueChange={(value) => {
                                                if (value === customValue) {
                                                    setData('campo', '');
                                                } else {
                                                    setData('campo', value);
                                                }
                                            }}
                                            disabled={!data.separador}
                                        >
                                            <SelectTrigger id="campo">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {fieldOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {getSelectValue(data.campo, fieldOptions) === customValue && (
                                            <Input
                                                id="campo_custom"
                                                value={data.campo || ''}
                                                onChange={e => setData('campo', e.target.value)}
                                                placeholder="ex: email, valor"
                                            />
                                        )}
                                    </div>
                                    <div className="grid grid-cols-3 gap-4">
                                        <div className="flex items-center space-x-2">
                                            <Switch
                                                id="pode_ver"
                                                checked={data.pode_ver ?? true}
                                                onCheckedChange={checked => setData('pode_ver', checked)}
                                            />
                                            <Label htmlFor="pode_ver">Ver</Label>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <Switch
                                                id="pode_criar"
                                                checked={data.pode_criar ?? false}
                                                onCheckedChange={checked => setData('pode_criar', checked)}
                                            />
                                            <Label htmlFor="pode_criar">Criar</Label>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <Switch
                                                id="pode_editar"
                                                checked={data.pode_editar ?? false}
                                                onCheckedChange={checked => setData('pode_editar', checked)}
                                            />
                                            <Label htmlFor="pode_editar">Editar</Label>
                                        </div>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="pode_eliminar"
                                            checked={data.pode_eliminar ?? false}
                                            onCheckedChange={checked => setData('pode_eliminar', checked)}
                                        />
                                        <Label htmlFor="pode_eliminar">Eliminar</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'monthly-fee' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="designacao">Nome *</Label>
                                        <Input
                                            id="designacao"
                                            value={data.designacao || ''}
                                            onChange={e => setData('designacao', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="age_group_id">Escalão</Label>
                                        <Select
                                            value={data.age_group_id || ''}
                                            onValueChange={(value) => setData('age_group_id', value)}
                                        >
                                            <SelectTrigger id="age_group_id">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {ageGroups.map((group) => (
                                                    <SelectItem key={group.id} value={group.id}>
                                                        {group.nome}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="valor">Valor (€) *</Label>
                                        <Input
                                            id="valor"
                                            type="number"
                                            step="0.01"
                                            value={data.valor ?? ''}
                                            onChange={e => setData('valor', e.target.value ? parseFloat(e.target.value) : '')}
                                            required
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'invoice-type' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Codigo</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            placeholder="mensalidade"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descricao</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'cost-center' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Codigo</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            placeholder="CC-0001"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Opcional ao criar. Se ficar vazio, o sistema gera automaticamente.
                                        </p>
                                        {errors.codigo && <p className="text-sm text-red-600">{errors.codigo}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                        {errors.nome && <p className="text-sm text-red-600">{errors.nome}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="tipo">Tipo</Label>
                                        <Input
                                            id="tipo"
                                            value={data.tipo || ''}
                                            onChange={e => setData('tipo', e.target.value)}
                                            placeholder="departamento"
                                        />
                                        {errors.tipo && <p className="text-sm text-red-600">{errors.tipo}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descricao</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                        {errors.descricao && <p className="text-sm text-red-600">{errors.descricao}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="orcamento">Orcamento (€)</Label>
                                        <Input
                                            id="orcamento"
                                            type="number"
                                            step="0.01"
                                            value={data.orcamento ?? ''}
                                            onChange={e => setData('orcamento', e.target.value ? parseFloat(e.target.value) : '')}
                                        />
                                        {errors.orcamento && <p className="text-sm text-red-600">{errors.orcamento}</p>}
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                    {errors.ativo && <p className="text-sm text-red-600">{errors.ativo}</p>}
                                </>
                            )}

                            {editingItem?.type === 'product' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Codigo *</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="categoria">Categoria</Label>
                                        <Select
                                            value={data.categoria || ''}
                                            onValueChange={(value) => setData('categoria', value === '__none__' ? '' : value)}
                                        >
                                            <SelectTrigger id="categoria">
                                                <SelectValue placeholder="Selecionar categoria" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">— Sem categoria —</SelectItem>
                                                {itemCategories.map((cat) => (
                                                    <SelectItem key={cat.id} value={cat.nome}>
                                                        {cat.nome}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="preco">Preço de Venda (€) *</Label>
                                        <Input
                                            id="preco"
                                            type="number"
                                            step="0.01"
                                            value={data.preco ?? ''}
                                            onChange={e => setData('preco', e.target.value ? parseFloat(e.target.value) : '')}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="stock_minimo">Stock Mínimo</Label>
                                        <Input
                                            id="stock_minimo"
                                            type="number"
                                            min="0"
                                            step="1"
                                            value={data.stock_minimo ?? 0}
                                            onChange={e => setData('stock_minimo', e.target.value ? parseInt(e.target.value, 10) : 0)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="area_armazenamento">Área de armazenamento</Label>
                                        <Input
                                            id="area_armazenamento"
                                            value={data.area_armazenamento || ''}
                                            onChange={e => setData('area_armazenamento', e.target.value)}
                                            placeholder="Ex: Prateleira A3"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="visible_in_store">Visível na Loja</Label>
                                        <Select
                                            value={(data.visible_in_store ?? false) ? 'sim' : 'nao'}
                                            onValueChange={(value) => setData('visible_in_store', value === 'sim')}
                                        >
                                            <SelectTrigger id="visible_in_store">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="sim">Sim</SelectItem>
                                                <SelectItem value="nao">Não</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="ativo">Ativo</Label>
                                        <Select
                                            value={(data.ativo ?? true) ? 'sim' : 'nao'}
                                            onValueChange={(value) => setData('ativo', value === 'sim')}
                                        >
                                            <SelectTrigger id="ativo">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="sim">Sim</SelectItem>
                                                <SelectItem value="nao">Não</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="descricao">Descrição</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="imagem_file">Imagem do Artigo</Label>
                                        <input
                                            id="imagem_file"
                                            type="file"
                                            accept="image/*"
                                            className="block w-full text-sm text-muted-foreground file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 cursor-pointer"
                                            onChange={(e) => {
                                                const file = e.target.files?.[0] || null;
                                                setData('imagem_file', file);
                                                if (file) {
                                                    setProductImagePreview(URL.createObjectURL(file));
                                                }
                                            }}
                                        />
                                        {productImagePreview && (
                                            <div className="mt-2">
                                                <img
                                                    src={productImagePreview}
                                                    alt="Preview"
                                                    className="h-24 w-24 object-cover rounded border"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'sponsor' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="tipo">Tipo *</Label>
                                        <Select value={data.tipo || 'secundario'} onValueChange={(value) => setData('tipo', value)}>
                                            <SelectTrigger id="tipo">
                                                <SelectValue placeholder="Selecionar tipo" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="principal">Principal</SelectItem>
                                                <SelectItem value="secundario">Secundário</SelectItem>
                                                <SelectItem value="apoio">Apoio</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="estado">Estado *</Label>
                                        <Select value={data.estado || 'ativo'} onValueChange={(value) => setData('estado', value)}>
                                            <SelectTrigger id="estado">
                                                <SelectValue placeholder="Selecionar estado" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="ativo">Ativo</SelectItem>
                                                <SelectItem value="inativo">Inativo</SelectItem>
                                                <SelectItem value="expirado">Expirado</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="contacto">Contacto</Label>
                                        <Input
                                            id="contacto"
                                            value={data.contacto || ''}
                                            onChange={e => setData('contacto', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email || ''}
                                            onChange={e => setData('email', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="website">Website</Label>
                                        <Input
                                            id="website"
                                            type="url"
                                            value={data.website || ''}
                                            onChange={e => setData('website', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="valor_anual">Valor anual (€)</Label>
                                        <Input
                                            id="valor_anual"
                                            type="number"
                                            step="0.01"
                                            value={data.valor_anual ?? ''}
                                            onChange={e => setData('valor_anual', e.target.value ? parseFloat(e.target.value) : '')}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="data_inicio">Data de início *</Label>
                                        <Input
                                            id="data_inicio"
                                            type="date"
                                            value={data.data_inicio || ''}
                                            onChange={e => setData('data_inicio', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="data_fim">Data de fim</Label>
                                        <Input
                                            id="data_fim"
                                            type="date"
                                            value={data.data_fim || ''}
                                            onChange={e => setData('data_fim', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="descricao">Descrição</Label>
                                        <Textarea
                                            id="descricao"
                                            value={data.descricao || ''}
                                            onChange={e => setData('descricao', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2 md:col-span-2">
                                        <Label htmlFor="logo">Logotipo</Label>
                                        <input
                                            id="logo"
                                            type="file"
                                            accept="image/*"
                                            className="block w-full text-sm text-muted-foreground file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 cursor-pointer"
                                            onChange={(e) => {
                                                const file = e.target.files?.[0] || null;
                                                setData('logo', file);
                                                if (file) {
                                                    setSponsorLogoPreview(URL.createObjectURL(file));
                                                }
                                            }}
                                        />
                                        {sponsorLogoPreview && (
                                            <img
                                                src={sponsorLogoPreview}
                                                alt="Logotipo do patrocinador"
                                                className="h-20 w-20 rounded border object-cover"
                                            />
                                        )}
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'item-category' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Codigo *</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="ativo">Ativo</Label>
                                        <Select
                                            value={(data.ativo ?? true) ? 'sim' : 'nao'}
                                            onValueChange={(value) => setData('ativo', value === 'sim')}
                                        >
                                            <SelectTrigger id="ativo">
                                                <SelectValue placeholder="Selecionar" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="sim">Sim</SelectItem>
                                                <SelectItem value="nao">Não</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'supplier' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nif">NIF</Label>
                                        <Input
                                            id="nif"
                                            value={data.nif || ''}
                                            onChange={e => setData('nif', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email || ''}
                                            onChange={e => setData('email', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="telefone">Telefone</Label>
                                        <Input
                                            id="telefone"
                                            value={data.telefone || ''}
                                            onChange={e => setData('telefone', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="morada">Morada</Label>
                                        <Input
                                            id="morada"
                                            value={data.morada || ''}
                                            onChange={e => setData('morada', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="categoria">Categoria</Label>
                                        <Input
                                            id="categoria"
                                            value={data.categoria || ''}
                                            onChange={e => setData('categoria', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'prova-tipo' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="distancia">Distancia *</Label>
                                            <Input
                                                id="distancia"
                                                type="number"
                                                min="0"
                                                value={data.distancia ?? ''}
                                                onChange={e => setData('distancia', e.target.value ? parseInt(e.target.value, 10) : '')}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="unidade">Unidade *</Label>
                                            <Select
                                                value={data.unidade || ''}
                                                onValueChange={(value) => setData('unidade', value)}
                                            >
                                                <SelectTrigger id="unidade">
                                                    <SelectValue placeholder="Selecionar" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="metros">Metros</SelectItem>
                                                    <SelectItem value="quilometros">Quilometros</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="modalidade">Modalidade *</Label>
                                        <Input
                                            id="modalidade"
                                            value={data.modalidade || ''}
                                            onChange={e => setData('modalidade', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'athlete-status' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Código *</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="cor">Cor</Label>
                                        <Input
                                            id="cor"
                                            type="color"
                                            value={data.cor || '#6B7280'}
                                            onChange={e => setData('cor', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}

                            {editingItem?.type === 'absence-reason' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="codigo">Código *</Label>
                                        <Input
                                            id="codigo"
                                            value={data.codigo || ''}
                                            onChange={e => setData('codigo', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="nome">Nome *</Label>
                                        <Input
                                            id="nome"
                                            value={data.nome || ''}
                                            onChange={e => setData('nome', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="requer_justificacao"
                                            checked={data.requer_justificacao ?? false}
                                            onCheckedChange={checked => setData('requer_justificacao', checked)}
                                        />
                                        <Label htmlFor="requer_justificacao">Requer Justificação</Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="ativo"
                                            checked={data.ativo ?? true}
                                            onCheckedChange={checked => setData('ativo', checked)}
                                        />
                                        <Label htmlFor="ativo">Ativo</Label>
                                    </div>
                                </>
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'A guardar...' : 'Guardar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
