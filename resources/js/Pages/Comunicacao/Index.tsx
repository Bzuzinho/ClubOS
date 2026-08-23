import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import InputError from '@/Components/InputError';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { ScrollArea } from '@/Components/ui/scroll-area';
import {
  ChartLineUp,
  Files,
  Info,
  MegaphoneSimple,
  PencilSimple,
  PaperPlaneTilt,
  Plus,
  Trash,
  UsersThree,
} from '@phosphor-icons/react';
type CampaignStatus = 'rascunho' | 'agendada' | 'em_processamento' | 'enviada' | 'falhada' | 'cancelada';
type AlertCategory = string;
type RecipientProfile = 'all' | 'athletes' | 'members';

type Paginated<T> = {
  data: T[];
};

interface CampaignRow {
  id: string;
  codigo: string;
  title: string;
  description?: string | null;
  notes?: string | null;
  status: CampaignStatus;
  scheduled_at?: string | null;
  sent_at?: string | null;
  author?: { id: string; name?: string | null; nome_completo?: string | null } | null;
  segment?: { id: string; name: string; rules_json?: Record<string, unknown> | null } | null;
  channels: Array<{ id: string; channel: Channel; is_enabled: boolean; template_id?: string | null; subject?: string | null; message_body?: string | null }>;
  deliveries: Array<{
    id: string;
    channel: Channel;
    status: DeliveryRow['status'];
    success_count: number;
    failed_count: number;
    error_message?: string | null;
    sent_at?: string | null;
    created_at?: string | null;
  }>;
  create_in_app_alert: boolean;
  alert_title?: string | null;
  alert_message?: string | null;
  alert_link?: string | null;
  alert_type?: 'info' | 'warning' | 'success' | 'error';
}

interface DeliveryRow {
  id: string;
  channel: Channel;
  status: 'pending' | 'processing' | 'completed' | 'partial' | 'failed';
  sent_at?: string | null;
  total_recipients: number;
  success_count: number;
  failed_count: number;
  pending_count: number;
  result_summary?: string | null;
  error_message?: string | null;
  campaign?: { id: string; codigo: string; title: string } | null;
  segment?: { id: string; name: string } | null;
}

interface TemplateRow {
  id: string;
  name: string;
  channel: Channel;
  category?: AlertCategory | null;
  subject?: string | null;
  body: string;
  variables_json?: Record<string, string> | string[] | null;
  status: 'ativo' | 'em_revisao' | 'inativo';
}

interface SegmentRow {
  id: string;
  name: string;
  type: 'dynamic' | 'manual' | 'system';
  description?: string | null;
  rules_json?: Record<string, unknown> | null;
  is_active: boolean;
  estimated_recipients?: number;
  resolved_age_group_labels?: string[];
  resolved_user_types?: string[];
  resolved_user_ids?: string[];
}

interface DynamicSourceOption {
  id: string;
  name: string;
  description?: string | null;
  strategy: string;
  sort_order: number;
  is_active: boolean;
}

interface AlertCategoryOption {
  id: string;
  code: string;
  name: string;
  description?: string | null;
  channels: Array<'email' | 'sms' | 'alert_app'>;
  sort_order: number;
  is_active: boolean;
}

interface Props {
  stats: {
    scheduled_campaigns: number;
    completed_deliveries: number;
    failed_deliveries: number;
    active_templates: number;
    total_sent: number;
    total_failed: number;
    total_pending: number;
    alerts_unread: number;
  };
  campaigns?: Paginated<CampaignRow>;
  deliveries?: Paginated<DeliveryRow>;
  templates?: Paginated<TemplateRow>;
  segments?: Paginated<SegmentRow>;
  latestCampaigns: Array<{ id: string; codigo: string; title: string; status: CampaignStatus }>;
  channelSummary: Array<{ channel: Channel; total: number; success_count: number; failed_count: number }>;
  alertsSummary: { total: number; unread: number; read: number };
  filterOptions?: {
    authors: Array<{ id: string; name?: string | null; nome_completo?: string | null }>;
    segments: Array<{ id: string; name: string }>;
    dynamicSources: DynamicSourceOption[];
    alertCategories: AlertCategoryOption[];
    templates: Array<{ id: string; name: string; channel: Channel; category?: AlertCategory | null }>;
    ageGroups: Array<{ id: string; nome: string }>;
    userTypes: Array<{ value: string; label: string }>;
    templateVariables: Array<{ key: string; label: string; description: string }>;
  };
  recipientOptions?: Array<{
    id: string;
    name?: string | null;
    nome_completo?: string | null;
    email?: string | null;
    telemovel?: string | null;
    contacto_telefonico?: string | null;
    contacto?: string | null;
    tipo_membro?: string[] | null;
    escalao?: string[] | null;
    age_group_id?: string | null;
    numero_socio?: string | null;
  }>;
  filters: Record<string, string | undefined>;
}

type ComunicacaoPageProps = Props & Record<string, unknown>;

const EMPTY_FILTER_OPTIONS: NonNullable<Props['filterOptions']> = {
  authors: [],
  segments: [],
  dynamicSources: [],
  alertCategories: [],
  templates: [],
  ageGroups: [],
  userTypes: [],
  templateVariables: [],
};

function SectionLoadingState({ message = 'A carregar...' }: { message?: string }) {
  return (
    <Card>
      <CardContent className="py-8 text-sm text-muted-foreground">{message}</CardContent>
    </Card>
  );
}

const CHANNELS: Array<{ value: Channel; label: string }> = [
  { value: 'email', label: 'Email' },
  { value: 'sms', label: 'SMS' },
  { value: 'push', label: 'Push' },
  { value: 'interno', label: 'Interno' },
  { value: 'alert_app', label: 'Alerta App' },
];

const DIRECT_CHANNEL_CONFIG: Array<'email' | 'sms' | 'alert_app'> = [
  'email',
  'sms',
  'alert_app',
];

const SEGMENT_CHANNEL_CONFIG: Array<'email' | 'sms' | 'alert_app'> = [
  'email',
  'sms',
  'alert_app',
];

const BASE_CHANNEL_CONFIG = SEGMENT_CHANNEL_CONFIG.map((item) => ({
  channel: item,
  is_enabled: false,
  template_id: '',
  subject: '',
  message_body: '',
}));

const channelLabel = (channel: Channel) => CHANNELS.find((item) => item.value === channel)?.label || channel;
const alertCategoryLabel = (categories: Array<{ value: string; label: string }>, code?: string | null) => categories.find((item) => item.value === code)?.label || code || '-';
const userTypeLabel = (value: string) => value.split('_').join(' ');
const deliveryStatusTone = (
  campaignStatus: CampaignStatus,
  delivery?: { status: DeliveryRow['status']; failed_count: number; success_count: number } | null,
) => {
  if (!delivery) {
    if (campaignStatus === 'falhada' || campaignStatus === 'cancelada') {
      return 'border-red-200 bg-red-100 text-red-800';
    }

    if (campaignStatus === 'agendada' || campaignStatus === 'em_processamento') {
      return 'border-amber-200 bg-amber-100 text-amber-800';
    }

    if (campaignStatus === 'enviada') {
      return 'border-emerald-200 bg-emerald-100 text-emerald-800';
    }

    return 'border-slate-200 bg-slate-100 text-slate-700';
  }

  if (delivery.status === 'completed' && delivery.failed_count === 0 && delivery.success_count > 0) {
    return 'border-emerald-200 bg-emerald-100 text-emerald-800';
  }

  if (delivery.status === 'failed' || delivery.status === 'partial' || delivery.failed_count > 0) {
    return 'border-red-200 bg-red-100 text-red-800';
  }

  return 'border-amber-200 bg-amber-100 text-amber-800';
};

const DEFAULT_DYNAMIC_SOURCE_STRATEGY = 'all_members';

export default function ComunicacaoIndex({
  stats,
  campaigns,
  deliveries,
  templates,
  segments,
  latestCampaigns,
  channelSummary,
  alertsSummary,
  filterOptions = EMPTY_FILTER_OPTIONS,
  recipientOptions,
  filters,
}: Props) {
  const page = usePage<ComunicacaoPageProps>();
  const fallbackAlertCategories = useMemo<Array<{ value: AlertCategory; label: string; channels: Array<'email' | 'sms' | 'alert_app'> }>>(
    () => [
      { value: 'mensalidade', label: 'Mensalidade', channels: ['email', 'sms', 'alert_app'] },
      { value: 'presencas', label: 'Presenças', channels: ['email', 'sms', 'alert_app'] },
      { value: 'comportamento', label: 'Comportamento', channels: ['email', 'alert_app'] },
      { value: 'geral', label: 'Geral', channels: ['email', 'sms', 'alert_app'] },
    ],
    [],
  );

  const alertCategories = useMemo(
    () => (filterOptions.alertCategories.length > 0
      ? filterOptions.alertCategories.map((category) => ({ value: category.code, label: category.name, channels: category.channels }))
      : fallbackAlertCategories),
    [fallbackAlertCategories, filterOptions.alertCategories],
  );

  const defaultAlertCategory = alertCategories[0]?.value || 'geral';
  const defaultAlertChannels = alertCategories[0]?.channels || DIRECT_CHANNEL_CONFIG;
  const [activeTab, setActiveTab] = useState('dashboard');
  const [loadingTab, setLoadingTab] = useState<string | null>(null);
  const [loadingRecipients, setLoadingRecipients] = useState(false);
  const [showCampaignModal, setShowCampaignModal] = useState(false);
  const [editingCampaign, setEditingCampaign] = useState<CampaignRow | null>(null);
  const [showTemplateModal, setShowTemplateModal] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<TemplateRow | null>(null);
  const [showTemplateVariableHelp, setShowTemplateVariableHelp] = useState(false);
  const [showSegmentModal, setShowSegmentModal] = useState(false);
  const [editingSegment, setEditingSegment] = useState<SegmentRow | null>(null);
  const [showDirectModal, setShowDirectModal] = useState(false);
  const [editingDirectCampaign, setEditingDirectCampaign] = useState<CampaignRow | null>(null);
  const [recipientProfile, setRecipientProfile] = useState<RecipientProfile>('all');
  const [recipientSearch, setRecipientSearch] = useState('');
  const [recipientAgeGroupFilter, setRecipientAgeGroupFilter] = useState('all');
  const [recipientUserTypeFilter, setRecipientUserTypeFilter] = useState('all');
  const campaignsData = campaigns?.data ?? [];
  const deliveriesData = deliveries?.data ?? [];
  const templatesData = templates?.data ?? [];
  const segmentsData = segments?.data ?? [];
  const availableRecipients = recipientOptions ?? [];
  const hasCampaigns = Object.prototype.hasOwnProperty.call(page.props, 'campaigns');
  const hasDeliveries = Object.prototype.hasOwnProperty.call(page.props, 'deliveries');
  const hasTemplates = Object.prototype.hasOwnProperty.call(page.props, 'templates');
  const hasSegments = Object.prototype.hasOwnProperty.call(page.props, 'segments');
  const hasFilterOptions = Object.prototype.hasOwnProperty.call(page.props, 'filterOptions');
  const hasRecipientOptions = Object.prototype.hasOwnProperty.call(page.props, 'recipientOptions');

  const templateVariableMap = useMemo(
    () => filterOptions.templateVariables.reduce<Record<string, string>>((accumulator, variable) => {
      accumulator[variable.key] = variable.description;
      return accumulator;
    }, {}),
    [filterOptions.templateVariables],
  );

  const [campaignFilters, setCampaignFilters] = useState({
    search: filters.search || '',
    channel: filters.channel || 'all',
    status: filters.status || 'all',
    author: filters.author || 'all',
  });

  const campaignForm = useForm({
    title: '',
    base_subject: '',
    description: '',
    segment_id: '',
    status: 'rascunho' as CampaignStatus,
    scheduled_at: '',
    create_in_app_alert: false,
    alert_title: '',
    alert_message: '',
    alert_link: '',
    alert_type: 'info' as 'info' | 'warning' | 'success' | 'error',
    notes: '',
    channels: BASE_CHANNEL_CONFIG,
  });

  const templateForm = useForm({
    name: '',
    channel: 'email' as Channel,
    category: defaultAlertCategory as AlertCategory,
    subject: '',
    body: '',
    variables_json: templateVariableMap,
    status: 'ativo' as 'ativo' | 'em_revisao' | 'inativo',
  });

  const segmentForm = useForm({
    name: '',
    type: 'dynamic' as 'dynamic' | 'manual' | 'system',
    description: '',
    is_active: true,
    source: DEFAULT_DYNAMIC_SOURCE_STRATEGY,
    dynamic_source_id: '',
    user_ids: '',
    age_group_ids: [] as string[],
    user_types: [] as string[],
    team_id: '',
    age_group_id: '',
    event_id: '',
  });

  const defaultDynamicSourceId = useMemo(
    () => filterOptions.dynamicSources.find((source) => source.strategy === DEFAULT_DYNAMIC_SOURCE_STRATEGY)?.id || '',
    [filterOptions.dynamicSources],
  );

  const selectedDynamicSource = useMemo(
    () => filterOptions.dynamicSources.find((source) => source.id === segmentForm.data.dynamic_source_id) || null,
    [filterOptions.dynamicSources, segmentForm.data.dynamic_source_id],
  );

  const getDynamicSourceByStrategy = (strategy?: string | null) => {
    if (!strategy) {
      return null;
    }

    return filterOptions.dynamicSources.find((source) => source.strategy === strategy) || null;
  };

  const directForm = useForm({
    title: '',
    alert_category: defaultAlertCategory as AlertCategory,
    alert_title: '',
    alert_message: '',
    alert_type: 'info' as 'info' | 'warning' | 'success' | 'error',
    scheduled_at: '',
    recipient_user_ids: [] as string[],
    channels: DIRECT_CHANNEL_CONFIG.map((item) => ({
      channel: item,
      is_enabled: defaultAlertChannels.includes(item),
      template_id: '',
      subject: '',
      message_body: '',
    })),
  });

  const createDirectChannelOverrides = () => DIRECT_CHANNEL_CONFIG.reduce<Record<string, { subjectCustomized: boolean; messageCustomized: boolean }>>((accumulator, channel) => {
    accumulator[channel] = { subjectCustomized: false, messageCustomized: false };
    return accumulator;
  }, {});

  const createDirectChannels = (
    baseSubject = '',
    baseMessage = '',
    allowedChannels: Array<'email' | 'sms' | 'alert_app'> = defaultAlertChannels,
  ) => DIRECT_CHANNEL_CONFIG.map((item) => ({
    channel: item,
    is_enabled: allowedChannels.includes(item),
    template_id: '',
    subject: allowedChannels.includes(item) ? baseSubject : '',
    message_body: allowedChannels.includes(item) ? baseMessage : '',
  }));

  const [directChannelOverrides, setDirectChannelOverrides] = useState(createDirectChannelOverrides);

  const createCampaignChannelOverrides = () => SEGMENT_CHANNEL_CONFIG.reduce<Record<string, { subjectCustomized: boolean; messageCustomized: boolean }>>((accumulator, channel) => {
    accumulator[channel] = { subjectCustomized: false, messageCustomized: false };
    return accumulator;
  }, {});

  const createCampaignChannels = (baseSubject = '', baseMessage = '') => SEGMENT_CHANNEL_CONFIG.map((item) => ({
    channel: item,
    is_enabled: false,
    template_id: '',
    subject: baseSubject,
    message_body: baseMessage,
  }));

  const [campaignChannelOverrides, setCampaignChannelOverrides] = useState(createCampaignChannelOverrides);

  const resetCampaignForm = () => {
    campaignForm.setData({
      title: '',
      base_subject: '',
      description: '',
      segment_id: '',
      status: 'rascunho',
      scheduled_at: '',
      create_in_app_alert: false,
      alert_title: '',
      alert_message: '',
      alert_link: '',
      alert_type: 'info',
      notes: '',
      channels: createCampaignChannels(),
    });
    setCampaignChannelOverrides(createCampaignChannelOverrides());
    setEditingCampaign(null);
  };

  const openCreateCampaign = () => {
    resetCampaignForm();
    setShowCampaignModal(true);
  };

  const getAlertCategoryFromNotes = (notes?: string | null) => {
    if (!notes) {
      return defaultAlertCategory;
    }

    const match = notes.match(/categoria:\s*([^|]+)/i);
    return match?.[1]?.trim() || defaultAlertCategory;
  };

  const isIndividualCampaign = (campaign: CampaignRow) => (campaign.notes || '').includes('Envio individual');

  const openEditDirectCampaign = (campaign: CampaignRow) => {
    const alertCategory = getAlertCategoryFromNotes(campaign.notes);
    const allowedChannels = alertCategories.find((category) => category.value === alertCategory)?.channels || DIRECT_CHANNEL_CONFIG;
    const mappedChannels = createDirectChannels(campaign.alert_title || '', campaign.alert_message || '', allowedChannels).map((base) => {
      const current = campaign.channels.find((item) => item.channel === base.channel);

      return {
        channel: base.channel,
        is_enabled: current?.is_enabled ?? false,
        template_id: current?.template_id ?? '',
        subject: current?.subject ?? (campaign.alert_title || ''),
        message_body: current?.message_body ?? (campaign.alert_message || ''),
      };
    });

    const rules = (campaign.segment?.rules_json || {}) as Record<string, unknown>;
    const recipientUserIds = Array.isArray(rules.user_ids)
      ? rules.user_ids.filter((value): value is string => typeof value === 'string')
      : [];

    directForm.setData({
      title: campaign.title,
      alert_category: alertCategory,
      alert_title: campaign.alert_title || '',
      alert_message: campaign.alert_message || campaign.description || '',
      alert_type: campaign.alert_type || 'info',
      scheduled_at: campaign.scheduled_at ? campaign.scheduled_at.slice(0, 16) : '',
      recipient_user_ids: recipientUserIds,
      channels: mappedChannels,
    });

    setDirectChannelOverrides(createDirectChannelOverrides());
    setRecipientProfile('all');
    setRecipientSearch('');
    setRecipientAgeGroupFilter('all');
    setRecipientUserTypeFilter('all');
    setEditingCampaign(null);
    setEditingDirectCampaign(campaign);
    setShowDirectModal(true);
  };

  const openEditCampaign = (campaign: CampaignRow) => {
    if (isIndividualCampaign(campaign)) {
      openEditDirectCampaign(campaign);
      return;
    }

    const baseSubject = campaign.alert_title
      || campaign.channels.find((item) => item.is_enabled && item.subject)?.subject
      || '';
    const baseMessage = campaign.alert_message
      || campaign.description
      || campaign.channels.find((item) => item.is_enabled && item.message_body)?.message_body
      || '';

    const mappedChannels = createCampaignChannels(baseSubject, baseMessage).map((baseChannel) => {
      const current = campaign.channels.find((item) => item.channel === baseChannel.channel);

      return {
        channel: baseChannel.channel,
        is_enabled: current?.is_enabled ?? false,
        template_id: '',
        subject: current?.subject ?? baseSubject,
        message_body: current?.message_body ?? baseMessage,
      };
    });

    campaignForm.setData({
      title: campaign.title,
      base_subject: baseSubject,
      description: baseMessage,
      segment_id: campaign.segment?.id || '',
      status: campaign.status,
      scheduled_at: campaign.scheduled_at ? campaign.scheduled_at.slice(0, 16) : '',
      create_in_app_alert: false,
      alert_title: baseSubject,
      alert_message: baseMessage,
      alert_link: '',
      alert_type: 'info',
      notes: '',
      channels: mappedChannels,
    });

    setCampaignChannelOverrides(createCampaignChannelOverrides());
    setEditingCampaign(campaign);
    setEditingDirectCampaign(null);
    setShowCampaignModal(true);
  };

  const syncBaseCampaignContentToChannels = (
    channels: typeof campaignForm.data.channels,
    baseSubject: string,
    baseMessage: string,
    overrides = campaignChannelOverrides,
  ) => channels.map((channelRow) => {
    if (!channelRow.is_enabled) {
      return channelRow;
    }

    const channelOverrides = overrides[channelRow.channel] ?? { subjectCustomized: false, messageCustomized: false };

    return {
      ...channelRow,
      subject: channelOverrides.subjectCustomized ? channelRow.subject : baseSubject,
      message_body: channelOverrides.messageCustomized ? channelRow.message_body : baseMessage,
    };
  });

  const updateCampaignBaseSubject = (value: string) => {
    campaignForm.setData({
      ...campaignForm.data,
      base_subject: value,
      alert_title: value,
      channels: syncBaseCampaignContentToChannels(campaignForm.data.channels, value, campaignForm.data.description),
    });
  };

  const updateCampaignBaseMessage = (value: string) => {
    campaignForm.setData({
      ...campaignForm.data,
      description: value,
      alert_message: value,
      channels: syncBaseCampaignContentToChannels(campaignForm.data.channels, campaignForm.data.base_subject, value),
    });
  };

  const updateCampaignChannel = (
    index: number,
    changes: Partial<(typeof campaignForm.data.channels)[number]>,
    overrides?: Partial<{ subjectCustomized: boolean; messageCustomized: boolean }>,
  ) => {
    const currentChannel = campaignForm.data.channels[index];
    const updatedChannels = [...campaignForm.data.channels];
    const updatedOverrides = { ...campaignChannelOverrides };
    const channelKey = currentChannel.channel;

    let nextChannel = { ...currentChannel, ...changes };

    if (changes.is_enabled === true && !currentChannel.is_enabled) {
      nextChannel = {
        ...nextChannel,
        subject: campaignForm.data.base_subject,
        message_body: campaignForm.data.description,
      };
      updatedOverrides[channelKey] = { subjectCustomized: false, messageCustomized: false };
    }

    if (changes.is_enabled === false && currentChannel.is_enabled) {
      updatedOverrides[channelKey] = { subjectCustomized: false, messageCustomized: false };
    }

    if (overrides) {
      updatedOverrides[channelKey] = {
        ...updatedOverrides[channelKey],
        ...overrides,
      };
    }

    updatedChannels[index] = nextChannel;
    setCampaignChannelOverrides(updatedOverrides);
    campaignForm.setData('channels', updatedChannels);
  };

  const saveCampaign = (submissionMode: 'schedule' | 'send') => {
    const payload = {
      title: campaignForm.data.title,
      description: campaignForm.data.description,
      segment_id: campaignForm.data.segment_id,
      submission_mode: submissionMode,
      scheduled_at: submissionMode === 'send' ? '' : campaignForm.data.scheduled_at,
      status: submissionMode === 'schedule' ? 'agendada' : 'rascunho',
      create_in_app_alert: false,
      alert_title: campaignForm.data.base_subject,
      alert_message: campaignForm.data.description,
      alert_link: '',
      alert_type: 'info',
      notes: campaignForm.data.notes,
      channels: campaignForm.data.channels,
    };

    if (editingCampaign) {
      router.put(route('comunicacao.campaigns.update', editingCampaign.id), payload, {
        preserveScroll: true,
        onSuccess: () => setShowCampaignModal(false),
      });
      return;
    }

    router.post(route('comunicacao.campaigns.store'), payload, {
      preserveScroll: true,
      onSuccess: () => setShowCampaignModal(false),
    });
  };

  const saveTemplate = () => {
    if (editingTemplate) {
      templateForm.put(route('comunicacao.templates.update', editingTemplate.id), {
        preserveScroll: true,
        onSuccess: () => setShowTemplateModal(false),
      });
      return;
    }

    templateForm.post(route('comunicacao.templates.store'), {
      preserveScroll: true,
      onSuccess: () => setShowTemplateModal(false),
    });
  };

  const saveSegment = () => {
    const userIds = segmentForm.data.user_ids
      .split(',')
      .map((value) => value.trim())
      .filter(Boolean);

    const resolvedSource = selectedDynamicSource || getDynamicSourceByStrategy(segmentForm.data.source);

    const rulesJson = {
      source: resolvedSource?.strategy || segmentForm.data.source || DEFAULT_DYNAMIC_SOURCE_STRATEGY,
      source_id: resolvedSource?.id || null,
      user_ids: userIds,
      age_group_ids: segmentForm.data.age_group_ids,
      user_types: segmentForm.data.user_types,
      team_id: segmentForm.data.team_id || null,
      age_group_id: segmentForm.data.age_group_id || null,
      event_id: segmentForm.data.event_id || null,
    };

    if (editingSegment) {
      router.put(route('comunicacao.segments.update', editingSegment.id), {
        name: segmentForm.data.name,
        type: segmentForm.data.type,
        description: segmentForm.data.description,
        is_active: segmentForm.data.is_active,
        rules_json: rulesJson,
      }, {
        preserveScroll: true,
        onSuccess: () => setShowSegmentModal(false),
      });
      return;
    }

    router.post(route('comunicacao.segments.store'), {
      name: segmentForm.data.name,
      type: segmentForm.data.type,
      description: segmentForm.data.description,
      is_active: segmentForm.data.is_active,
      rules_json: rulesJson,
    }, {
      preserveScroll: true,
      onSuccess: () => setShowSegmentModal(false),
    });
  };

  const filteredTemplateOptions = (channel: Channel, category?: AlertCategory) => {
    const byChannel = filterOptions.templates.filter((item) => item.channel === channel);
    if (!category) {
      return byChannel;
    }

    return byChannel.filter((item) => !item.category || item.category === category || item.category === 'geral');
  };

  const selectedAlertCategory = useMemo(
    () => alertCategories.find((category) => category.value === directForm.data.alert_category) || alertCategories[0] || null,
    [alertCategories, directForm.data.alert_category],
  );

  const allowedAlertChannels = selectedAlertCategory?.channels || DIRECT_CHANNEL_CONFIG;

  const openDirectSend = () => {
    directForm.setData({
      title: '',
      alert_category: defaultAlertCategory,
      alert_title: '',
      alert_message: '',
      alert_type: 'info',
      scheduled_at: '',
      recipient_user_ids: [],
      channels: createDirectChannels('', '', defaultAlertChannels),
    });
    setDirectChannelOverrides(createDirectChannelOverrides());
    setRecipientProfile('all');
    setRecipientSearch('');
    setRecipientAgeGroupFilter('all');
    setRecipientUserTypeFilter('all');
    setEditingDirectCampaign(null);
    setShowDirectModal(true);
  };

  const resetSegmentFormForCreate = () => {
    segmentForm.reset();
    segmentForm.setData({
      name: '',
      type: 'dynamic',
      description: '',
      is_active: true,
      source: DEFAULT_DYNAMIC_SOURCE_STRATEGY,
      dynamic_source_id: defaultDynamicSourceId,
      user_ids: '',
      age_group_ids: [],
      user_types: [],
      team_id: '',
      age_group_id: '',
      event_id: '',
    });
  };

  const populateSegmentForm = (segment: SegmentRow) => {
    const rules = (segment.rules_json || {}) as Record<string, unknown>;
    const sourceId = typeof rules.source_id === 'string' ? rules.source_id : '';
    const sourceStrategy = typeof rules.source === 'string' ? rules.source : DEFAULT_DYNAMIC_SOURCE_STRATEGY;
    const matchingSource = (sourceId && filterOptions.dynamicSources.find((source) => source.id === sourceId))
      || getDynamicSourceByStrategy(sourceStrategy);

    segmentForm.setData({
      name: segment.name,
      type: segment.type,
      description: segment.description || '',
      is_active: segment.is_active,
      source: sourceStrategy,
      dynamic_source_id: matchingSource?.id || '',
      user_ids: Array.isArray(rules.user_ids) ? rules.user_ids.join(', ') : '',
      age_group_ids: Array.isArray(rules.age_group_ids) ? rules.age_group_ids.filter((value): value is string => typeof value === 'string') : [],
      user_types: Array.isArray(rules.user_types) ? rules.user_types.filter((value): value is string => typeof value === 'string') : [],
      team_id: typeof rules.team_id === 'string' ? rules.team_id : '',
      age_group_id: typeof rules.age_group_id === 'string' ? rules.age_group_id : '',
      event_id: typeof rules.event_id === 'string' ? rules.event_id : '',
    });
  };

  const toggleSegmentArrayValue = (field: 'age_group_ids' | 'user_types', value: string, checked: boolean) => {
    const current = new Set(segmentForm.data[field]);

    if (checked) {
      current.add(value);
    } else {
      current.delete(value);
    }

    segmentForm.setData(field, Array.from(current));
  };

  const toggleRecipient = (userId: string, checked: boolean) => {
    const current = new Set(directForm.data.recipient_user_ids);
    if (checked) {
      current.add(userId);
    } else {
      current.delete(userId);
    }

    directForm.setData('recipient_user_ids', Array.from(current));
  };

  const isAthlete = (memberTypes?: string[] | null) => {
    if (!Array.isArray(memberTypes)) {
      return false;
    }

    return memberTypes.includes('atleta');
  };

  const filteredRecipients = useMemo(() => {
    if (!showDirectModal) {
      return [];
    }

    const searchNormalized = recipientSearch.trim().toLowerCase();

    return availableRecipients.filter((recipient) => {
      const ageGroupMatches =
        recipientAgeGroupFilter === 'all'
        || recipient.age_group_id === recipientAgeGroupFilter
        || recipient.escalao?.includes(recipientAgeGroupFilter);

      const userTypeMatches =
        recipientUserTypeFilter === 'all'
        || recipient.tipo_membro?.includes(recipientUserTypeFilter);

      const profileMatches =
        recipientProfile === 'all' ||
        (recipientProfile === 'athletes' && isAthlete(recipient.tipo_membro)) ||
        (recipientProfile === 'members' && !isAthlete(recipient.tipo_membro));

      if (!profileMatches || !ageGroupMatches || !userTypeMatches) {
        return false;
      }

      if (!searchNormalized) {
        return true;
      }

      const label = (recipient.nome_completo || recipient.name || '').toLowerCase();
      const email = (recipient.email || '').toLowerCase();
      const phone = (recipient.telemovel || recipient.contacto_telefonico || recipient.contacto || '').toLowerCase();

      const memberNumber = (recipient.numero_socio || '').toLowerCase();

      return label.includes(searchNormalized)
        || email.includes(searchNormalized)
        || phone.includes(searchNormalized)
        || memberNumber.includes(searchNormalized);
    });
  }, [availableRecipients, recipientAgeGroupFilter, recipientProfile, recipientSearch, recipientUserTypeFilter, showDirectModal]);

  const filteredRecipientIds = useMemo(
    () => filteredRecipients.map((recipient) => recipient.id),
    [filteredRecipients],
  );

  const enabledDirectChannels = useMemo(
    () => directForm.data.channels.filter((channel) => channel.is_enabled),
    [directForm.data.channels],
  );

  const hasAlertAppEnabled = useMemo(
    () => enabledDirectChannels.some((channel) => channel.channel === 'alert_app'),
    [enabledDirectChannels],
  );

  const syncBaseContentToChannels = (
    channels: typeof directForm.data.channels,
    baseSubject: string,
    baseMessage: string,
    overrides = directChannelOverrides,
  ) => channels.map((channelRow) => {
    if (!channelRow.is_enabled) {
      return channelRow;
    }

    const channelOverrides = overrides[channelRow.channel] ?? { subjectCustomized: false, messageCustomized: false };

    return {
      ...channelRow,
      subject: channelOverrides.subjectCustomized ? channelRow.subject : baseSubject,
      message_body: channelOverrides.messageCustomized ? channelRow.message_body : baseMessage,
    };
  });

  const updateDirectBaseSubject = (value: string) => {
    directForm.setData({
      ...directForm.data,
      alert_title: value,
      channels: syncBaseContentToChannels(directForm.data.channels, value, directForm.data.alert_message),
    });
  };

  const updateDirectBaseMessage = (value: string) => {
    directForm.setData({
      ...directForm.data,
      alert_message: value,
      channels: syncBaseContentToChannels(directForm.data.channels, directForm.data.alert_title, value),
    });
  };

  const updateDirectChannel = (
    index: number,
    changes: Partial<(typeof directForm.data.channels)[number]>,
    overrides?: Partial<{ subjectCustomized: boolean; messageCustomized: boolean }>,
  ) => {
    const currentChannel = directForm.data.channels[index];
    const updatedChannels = [...directForm.data.channels];
    const updatedOverrides = { ...directChannelOverrides };
    const channelKey = currentChannel.channel;

    let nextChannel = { ...currentChannel, ...changes };

    if (changes.is_enabled === true && !currentChannel.is_enabled) {
      nextChannel = {
        ...nextChannel,
        subject: directForm.data.alert_title,
        message_body: directForm.data.alert_message,
      };
      updatedOverrides[channelKey] = { subjectCustomized: false, messageCustomized: false };
    }

    if (changes.is_enabled === false && currentChannel.is_enabled) {
      updatedOverrides[channelKey] = { subjectCustomized: false, messageCustomized: false };
    }

    if (overrides) {
      updatedOverrides[channelKey] = {
        ...updatedOverrides[channelKey],
        ...overrides,
      };
    }

    updatedChannels[index] = nextChannel;
    setDirectChannelOverrides(updatedOverrides);
    directForm.setData('channels', updatedChannels);
  };

  const updateDirectCategory = (category: AlertCategory) => {
    const allowedChannels = alertCategories.find((item) => item.value === category)?.channels || DIRECT_CHANNEL_CONFIG;

    const updatedOverrides = { ...directChannelOverrides };
    const updatedChannels = directForm.data.channels.map((channelRow) => {
      if (!allowedChannels.includes(channelRow.channel as 'email' | 'sms' | 'alert_app')) {
        updatedOverrides[channelRow.channel] = { subjectCustomized: false, messageCustomized: false };
        return { ...channelRow, is_enabled: false, template_id: '' };
      }

      const templateStillAllowed = !channelRow.template_id || filteredTemplateOptions(channelRow.channel as Channel, category)
        .some((template) => template.id === channelRow.template_id);

      if (!channelRow.is_enabled) {
        updatedOverrides[channelRow.channel] = { subjectCustomized: false, messageCustomized: false };

        return {
          ...channelRow,
          is_enabled: true,
          template_id: templateStillAllowed ? channelRow.template_id : '',
          subject: directForm.data.alert_title,
          message_body: directForm.data.alert_message,
        };
      }

      return templateStillAllowed
        ? channelRow
        : { ...channelRow, template_id: '' };
    });

    setDirectChannelOverrides(updatedOverrides);
    directForm.setData({
      ...directForm.data,
      alert_category: category,
      channels: syncBaseContentToChannels(updatedChannels, directForm.data.alert_title, directForm.data.alert_message, updatedOverrides),
    });
  };

  const selectVisibleRecipients = () => {
    directForm.setData('recipient_user_ids', Array.from(new Set([...directForm.data.recipient_user_ids, ...filteredRecipientIds])));
  };

  const clearRecipientSelection = () => {
    directForm.setData('recipient_user_ids', []);
  };

  const validateDirectCommunication = () => {
    directForm.clearErrors();

    let hasErrors = false;

    if (directForm.data.recipient_user_ids.length === 0) {
      directForm.setError('recipient_user_ids', 'Seleciona pelo menos um destinatario.');
      hasErrors = true;
    }

    if (enabledDirectChannels.length === 0) {
      directForm.setError('channels', 'Ativa pelo menos um canal de envio.');
      hasErrors = true;
    }

    if (hasAlertAppEnabled && !directForm.data.alert_title.trim()) {
      directForm.setError('alert_title', 'Titulo do alerta e obrigatorio quando o canal Alerta App esta ativo.');
      hasErrors = true;
    }

    if (hasAlertAppEnabled && !directForm.data.alert_message.trim()) {
      directForm.setError('alert_message', 'Mensagem do alerta e obrigatoria quando o canal Alerta App esta ativo.');
      hasErrors = true;
    }

    directForm.data.channels.forEach((channelRow, index) => {
      if (!channelRow.is_enabled) {
        return;
      }

      if (!channelRow.template_id && !channelRow.message_body.trim()) {
        directForm.setError(`channels.${index}.message_body`, 'Define uma mensagem manual ou seleciona um template.');
        hasErrors = true;
      }
    });

    return !hasErrors;
  };

  const saveDirectCommunication = () => {
    if (!validateDirectCommunication()) {
      return;
    }

    const submissionMode = directForm.data.scheduled_at ? 'schedule' : 'send';
    const payload = {
      title: directForm.data.title.trim() || `Alerta Individual - ${alertCategoryLabel(alertCategories, directForm.data.alert_category)}`,
      description: directForm.data.alert_message,
      segment_id: editingDirectCampaign?.segment?.id || '',
      submission_mode: submissionMode,
      alert_category: directForm.data.alert_category,
      alert_title: directForm.data.alert_title,
      alert_message: directForm.data.alert_message,
      alert_type: directForm.data.alert_type,
      scheduled_at: directForm.data.scheduled_at,
      create_in_app_alert: enabledDirectChannels.some((channel) => channel.channel === 'alert_app'),
      notes: `Envio individual | categoria: ${directForm.data.alert_category} | modo: ${submissionMode === 'schedule' ? 'agendado' : 'imediato'}`,
      recipient_user_ids: directForm.data.recipient_user_ids,
      recipient_age_group_ids: [],
      recipient_user_types: [],
      channels: directForm.data.channels,
    };

    const onSuccess = () => {
      directForm.clearErrors();
      setEditingDirectCampaign(null);
      setShowDirectModal(false);
    };

    if (editingDirectCampaign) {
      router.put(route('comunicacao.campaigns.update', editingDirectCampaign.id), payload, {
        preserveScroll: true,
        onSuccess,
      });

      return;
    }

    directForm.post(route('comunicacao.campaigns.sendIndividual'), {
      preserveScroll: true,
      onSuccess,
    });
  };

  const deleteCampaign = (campaignId: string) => {
    if (!window.confirm('Apagar este envio?')) {
      return;
    }

    router.delete(route('comunicacao.campaigns.destroy', campaignId), { preserveScroll: true });
  };

  const deleteTemplate = (templateId: string) => {
    if (!window.confirm('Apagar este template?')) {
      return;
    }

    router.delete(route('comunicacao.templates.destroy', templateId), { preserveScroll: true });
  };

  const deleteSegment = (segmentId: string) => {
    if (!window.confirm('Apagar este segmento?')) {
      return;
    }

    router.delete(route('comunicacao.segments.destroy', segmentId), { preserveScroll: true });
  };

  const applyFilters = () => {
    router.get(
      route('comunicacao.index'),
      {
        search: campaignFilters.search || undefined,
        channel: campaignFilters.channel === 'all' ? undefined : campaignFilters.channel,
        status: campaignFilters.status === 'all' ? undefined : campaignFilters.status,
        author: campaignFilters.author === 'all' ? undefined : campaignFilters.author,
      },
      { preserveState: true, preserveScroll: true },
    );
  };

  const statusBadge = useMemo(
    () =>
      ({
        rascunho: 'outline',
        agendada: 'secondary',
        em_processamento: 'secondary',
        enviada: 'default',
        falhada: 'destructive',
        cancelada: 'destructive',
      }) as Record<CampaignStatus, 'outline' | 'secondary' | 'default' | 'destructive'>,
    [],
  );

  const directSubmitDisabled = directForm.processing || directForm.data.recipient_user_ids.length === 0 || enabledDirectChannels.length === 0;
  const directSubmitLabel = directForm.data.scheduled_at ? 'Agendar envio' : 'Enviar agora';

  const visibleDirectChannels = useMemo(
    () => directForm.data.channels.filter((channel) => allowedAlertChannels.includes(channel.channel as 'email' | 'sms' | 'alert_app')),
    [allowedAlertChannels, directForm.data.channels],
  );

  const enabledCampaignChannels = useMemo(
    () => campaignForm.data.channels.filter((channel) => channel.is_enabled),
    [campaignForm.data.channels],
  );

  const campaignSubmitMode = campaignForm.data.scheduled_at ? 'schedule' : 'send';
  const campaignSubmitLabel = campaignSubmitMode === 'schedule' ? 'Guardar agendamento' : 'Guardar e enviar';
  const campaignSubmitDisabled = campaignForm.processing || !campaignForm.data.segment_id || enabledCampaignChannels.length === 0;

  const campaignSections = useMemo(() => {
    if (activeTab !== 'envios') {
      return [];
    }

    const sections: Array<{
      key: string;
      title: string;
      description: string;
      statuses: CampaignStatus[];
      badgeVariant: 'outline' | 'secondary' | 'default' | 'destructive';
      items: typeof campaignsData;
    }> = [
      {
        key: 'agendados',
        title: 'Envios agendados',
        description: 'Envios prontos para execução futura.',
        statuses: ['agendada'] as CampaignStatus[],
        badgeVariant: 'secondary' as const,
      },
      {
        key: 'concluidos',
        title: 'Envios concluídos',
        description: 'Envios já processados com sucesso.',
        statuses: ['enviada'] as CampaignStatus[],
        badgeVariant: 'default' as const,
      },
      {
        key: 'falhados',
        title: 'Envios falhados',
        description: 'Envios com falha ou cancelados.',
        statuses: ['falhada', 'cancelada'] as CampaignStatus[],
        badgeVariant: 'destructive' as const,
      },
    ].map((section) => ({
      ...section,
      items: campaignsData.filter((campaign) => section.statuses.includes(campaign.status)),
    }));

    const coveredStatuses = new Set(sections.flatMap((section) => section.statuses));
    const remainingItems = campaignsData.filter((campaign) => !coveredStatuses.has(campaign.status));

    if (remainingItems.length > 0) {
      sections.push({
        key: 'outros',
        title: 'Outros envios',
        description: 'Rascunhos ou envios ainda em processamento.',
        statuses: ['rascunho', 'em_processamento'] as CampaignStatus[],
        badgeVariant: 'outline' as const,
        items: remainingItems,
      });
    }

    return sections;
  }, [activeTab, campaignsData]);

  useEffect(() => {
    const pendingByTab: Record<string, { ready: boolean; props: string[] }> = {
      envios: { ready: hasFilterOptions && hasCampaigns, props: ['filterOptions', 'campaigns'] },
      execucao: { ready: hasFilterOptions && hasDeliveries, props: ['filterOptions', 'deliveries'] },
      templates: { ready: hasFilterOptions && hasTemplates, props: ['filterOptions', 'templates'] },
      segmentos: { ready: hasFilterOptions && hasSegments, props: ['filterOptions', 'segments'] },
    };

    const pending = pendingByTab[activeTab];

    if (!pending || pending.ready) {
      setLoadingTab((current) => (current === activeTab ? null : current));
      return;
    }

    setLoadingTab(activeTab);
    router.reload({
      only: pending.props,
      onFinish: () => setLoadingTab((current) => (current === activeTab ? null : current)),
    });
  }, [activeTab, hasCampaigns, hasDeliveries, hasFilterOptions, hasSegments, hasTemplates]);

  useEffect(() => {
    const needsFilterOptions = showCampaignModal || showTemplateModal || showSegmentModal || showDirectModal;

    if (!needsFilterOptions || hasFilterOptions) {
      return;
    }

    router.reload({
      only: ['filterOptions'],
    });
  }, [hasFilterOptions, showCampaignModal, showDirectModal, showSegmentModal, showTemplateModal]);

  useEffect(() => {
    if (!showDirectModal || !hasFilterOptions || hasRecipientOptions || loadingRecipients) {
      return;
    }

    setLoadingRecipients(true);
    router.reload({
      only: ['recipientOptions'],
      onFinish: () => setLoadingRecipients(false),
    });
  }, [hasFilterOptions, hasRecipientOptions, loadingRecipients, showDirectModal]);

  const renderCampaignChannels = (campaign: CampaignRow) => {
    const enabledChannels = campaign.channels.filter((item) => item.is_enabled);

    if (enabledChannels.length === 0) {
      return <span className="text-xs text-muted-foreground">-</span>;
    }

    return (
      <div className="flex flex-wrap gap-1">
        {enabledChannels.map((channel) => {
          const delivery = campaign.deliveries
            .filter((item) => item.channel === channel.channel)
            .sort((left, right) => new Date(right.created_at || right.sent_at || 0).getTime() - new Date(left.created_at || left.sent_at || 0).getTime())[0];

          return (
            <span
              key={`${campaign.id}-${channel.channel}`}
              className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium ${deliveryStatusTone(campaign.status, delivery)}`}
              title={delivery?.error_message || `${channelLabel(channel.channel)}${delivery ? ` · ${delivery.status}` : ''}`}
            >
              {channelLabel(channel.channel)}
            </span>
          );
        })}
      </div>
    );
  };

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div>
          <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Comunicação</h1>
          <p className="text-muted-foreground text-xs mt-0.5">Campanhas, envios, templates, segmentos e alertas internos.</p>
        </div>
      }
    >
      <Head title="Comunicação" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
          <div className="w-full">
            <TabsList className="grid h-auto w-full shrink-0 grid-cols-2 gap-1 p-1 text-[11px] sm:h-9 sm:grid-cols-5 sm:text-xs">
              <TabsTrigger value="dashboard" className="flex h-8 min-w-0 items-center justify-center gap-1 whitespace-nowrap px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <ChartLineUp size={14} />
                <span>Dashboard</span>
              </TabsTrigger>
              <TabsTrigger value="envios" className="flex h-8 min-w-0 items-center justify-center gap-1 whitespace-nowrap px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <MegaphoneSimple size={14} />
                <span>Envios</span>
              </TabsTrigger>
              <TabsTrigger value="templates" className="flex h-8 min-w-0 items-center justify-center gap-1 whitespace-nowrap px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <PaperPlaneTilt size={14} />
                <span>Templates</span>
              </TabsTrigger>
              <TabsTrigger value="segmentos" className="flex h-8 min-w-0 items-center justify-center gap-1 whitespace-nowrap px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <Files size={14} />
                <span>Segmentos</span>
              </TabsTrigger>
              <TabsTrigger value="execucao" className="flex h-8 min-w-0 items-center justify-center gap-1 whitespace-nowrap px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <UsersThree size={14} />
                <span>Execução</span>
              </TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="dashboard" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'dashboard' ? (
            <>
            <div className="grid gap-2 grid-cols-2 lg:grid-cols-4">
              <Card className="p-3"><div className="text-xs text-muted-foreground">Campanhas agendadas</div><div className="text-xl font-semibold">{stats.scheduled_campaigns}</div></Card>
              <Card className="p-3"><div className="text-xs text-muted-foreground">Envios concluídos</div><div className="text-xl font-semibold">{stats.completed_deliveries}</div></Card>
              <Card className="p-3"><div className="text-xs text-muted-foreground">Falhas</div><div className="text-xl font-semibold">{stats.failed_deliveries}</div></Card>
              <Card className="p-3"><div className="text-xs text-muted-foreground">Templates ativos</div><div className="text-xl font-semibold">{stats.active_templates}</div></Card>
            </div>

            <div className="grid gap-3 lg:grid-cols-3">
              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Últimos 5 envios</CardTitle></CardHeader>
                <CardContent className="space-y-2">
                  {latestCampaigns.map((item) => (
                    <div key={item.id} className="flex items-center justify-between text-xs border rounded-md p-2">
                      <div>
                        <div className="font-medium">{item.codigo}</div>
                        <div className="text-muted-foreground">{item.title}</div>
                      </div>
                      <Badge variant={statusBadge[item.status]}>{item.status}</Badge>
                    </div>
                  ))}
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Resumo por canal</CardTitle></CardHeader>
                <CardContent className="space-y-2">
                  {channelSummary.map((item) => (
                    <div key={item.channel} className="text-xs border rounded-md p-2">
                      <div className="font-medium uppercase">{item.channel}</div>
                      <div className="text-muted-foreground">Total: {item.total} | Sucesso: {item.success_count} | Falhas: {item.failed_count}</div>
                    </div>
                  ))}
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-2"><CardTitle className="text-sm">Alertas internos</CardTitle></CardHeader>
                <CardContent className="space-y-2 text-xs">
                  <div className="flex justify-between"><span>Total</span><span className="font-medium">{alertsSummary.total}</span></div>
                  <div className="flex justify-between"><span>Por ler</span><span className="font-medium">{alertsSummary.unread}</span></div>
                  <div className="flex justify-between"><span>Lidos</span><span className="font-medium">{alertsSummary.read}</span></div>
                  <div className="pt-2 border-t text-muted-foreground">Métricas: enviados {stats.total_sent}, falhados {stats.total_failed}, pendentes {stats.total_pending}.</div>
                </CardContent>
              </Card>
            </div>
            </>
            ) : null}
          </TabsContent>

          <TabsContent value="envios" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'envios' ? (
            !hasCampaigns || loadingTab === 'envios' ? (
            <SectionLoadingState message="A carregar envios..." />
            ) : (
            <>
            <Card>
              <CardContent className="pt-3 pb-3">
                <div className="space-y-1.5">
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Input
                      className="w-full sm:col-span-2 xl:col-span-1"
                      placeholder="Pesquisar código/título"
                      value={campaignFilters.search}
                      onChange={(e) => setCampaignFilters((prev) => ({ ...prev, search: e.target.value }))}
                    />
                    <div className="w-full">
                      <Select value={campaignFilters.channel} onValueChange={(value) => setCampaignFilters((prev) => ({ ...prev, channel: value }))}>
                        <SelectTrigger className="w-full"><SelectValue placeholder="Canal" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">Todos os canais</SelectItem>
                          {CHANNELS.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="w-full">
                      <Select value={campaignFilters.status} onValueChange={(value) => setCampaignFilters((prev) => ({ ...prev, status: value }))}>
                        <SelectTrigger className="w-full"><SelectValue placeholder="Estado" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">Todos os estados</SelectItem>
                          <SelectItem value="rascunho">Rascunho</SelectItem>
                          <SelectItem value="agendada">Agendada</SelectItem>
                          <SelectItem value="em_processamento">Em processamento</SelectItem>
                          <SelectItem value="enviada">Enviada</SelectItem>
                          <SelectItem value="falhada">Falhada</SelectItem>
                          <SelectItem value="cancelada">Cancelada</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="w-full">
                      <Select value={campaignFilters.author} onValueChange={(value) => setCampaignFilters((prev) => ({ ...prev, author: value }))}>
                        <SelectTrigger className="w-full"><SelectValue placeholder="Autor" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">Todos os autores</SelectItem>
                          {filterOptions.authors.map((author) => (
                            <SelectItem key={author.id} value={author.id}>{author.nome_completo || author.name || author.id}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>

                  <div className="flex flex-col gap-1.5 sm:flex-row sm:flex-wrap xl:justify-end">
                    <Button variant="outline" size="sm" className="h-8 w-full text-xs sm:w-auto" onClick={applyFilters}>Filtrar</Button>
                    <Button variant="outline" size="sm" className="h-8 w-full text-xs sm:w-auto" onClick={openDirectSend}>Envio manual</Button>
                    <Button size="sm" className="h-8 w-full text-xs sm:w-auto" onClick={openCreateCampaign}><Plus size={14} className="mr-1" />Envio para segmentos</Button>
                  </div>
                </div>
              </CardContent>
            </Card>

            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
              {campaignSections.map((section) => (
                <Card key={section.key} className="p-2.5">
                  <div className="flex flex-col items-start gap-1.5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <div className="text-xs text-muted-foreground">{section.title}</div>
                      <div className="text-lg font-semibold leading-none mt-1">{section.items.length}</div>
                    </div>
                    <Badge variant={section.badgeVariant} className="max-w-full whitespace-normal text-left">{section.title}</Badge>
                  </div>
                </Card>
              ))}
            </div>

            {campaignSections.map((section) => (
              <Card key={section.key}>
                <CardHeader className="pb-1.5 pt-4 px-4">
                  <CardTitle className="text-sm">{section.title}</CardTitle>
                  <p className="text-xs text-muted-foreground">{section.description}</p>
                </CardHeader>
                <CardContent className="overflow-hidden">
                  {section.items.length === 0 ? (
                    <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                      Sem registos nesta secção.
                    </div>
                  ) : (
                    <>
                      <div className="space-y-2 xl:hidden">
                        {section.items.map((campaign) => (
                          <div key={campaign.id} className="rounded-md border p-3">
                            <div className="flex flex-col items-start gap-2 sm:flex-row sm:items-start sm:justify-between">
                              <div className="min-w-0">
                                <p className="break-words text-xs text-muted-foreground">{campaign.codigo}</p>
                                <p className="break-words text-sm font-medium">{campaign.title}</p>
                              </div>
                              <Badge variant={statusBadge[campaign.status]} className="max-w-full whitespace-normal">{campaign.status}</Badge>
                            </div>
                            <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                              <div className="break-words">
                                <span>Canais:</span>
                                <div className="mt-1">{renderCampaignChannels(campaign)}</div>
                              </div>
                              <p className="break-words">Audiência: {campaign.segment?.name || '-'}</p>
                              <p>Agendamento: {campaign.scheduled_at ? new Date(campaign.scheduled_at).toLocaleString('pt-PT') : '-'}</p>
                              <p className="break-words">Autor: {campaign.author?.nome_completo || campaign.author?.name || '-'}</p>
                            </div>
                            <div className="mt-3 flex justify-end gap-2">
                              <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => openEditCampaign(campaign)} title="Editar envio" aria-label="Editar envio">
                                <PencilSimple size={15} />
                              </Button>
                              <Button variant="outline" size="icon" className="h-8 w-8 text-red-700 hover:text-red-700" onClick={() => deleteCampaign(campaign.id)} title="Apagar envio" aria-label="Apagar envio">
                                <Trash size={15} />
                              </Button>
                            </div>
                          </div>
                        ))}
                      </div>

                      <div className="hidden xl:block">
                        <Table className="table-fixed">
                          <TableHeader>
                            <TableRow>
                              <TableHead className="w-[9%] whitespace-normal h-9 px-2 py-2">Código</TableHead>
                              <TableHead className="w-[19%] whitespace-normal h-9 px-2 py-2">Título</TableHead>
                              <TableHead className="w-[12%] whitespace-normal h-9 px-2 py-2">Canais</TableHead>
                              <TableHead className="w-[12%] whitespace-normal h-9 px-2 py-2">Audiência</TableHead>
                              <TableHead className="w-[14%] whitespace-normal h-9 px-2 py-2">Agendamento</TableHead>
                              <TableHead className="w-[14%] whitespace-normal h-9 px-2 py-2">Autor</TableHead>
                              <TableHead className="w-[8%] whitespace-normal h-9 px-2 py-2">Estado</TableHead>
                              <TableHead className="w-[12%] text-right whitespace-normal h-9 px-2 py-2">Ações</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {section.items.map((campaign) => (
                              <TableRow key={campaign.id}>
                                <TableCell className="font-medium whitespace-normal break-words text-xs px-2 py-2">{campaign.codigo}</TableCell>
                                <TableCell className="whitespace-normal break-words px-2 py-2 text-sm">{campaign.title}</TableCell>
                                <TableCell className="text-xs whitespace-normal break-words px-2 py-2">{renderCampaignChannels(campaign)}</TableCell>
                                <TableCell className="whitespace-normal break-words text-sm px-2 py-2">{campaign.segment?.name || '-'}</TableCell>
                                <TableCell className="text-xs whitespace-normal break-words px-2 py-2">{campaign.scheduled_at ? new Date(campaign.scheduled_at).toLocaleString('pt-PT') : '-'}</TableCell>
                                <TableCell className="whitespace-normal break-words text-sm px-2 py-2">{campaign.author?.nome_completo || campaign.author?.name || '-'}</TableCell>
                                <TableCell className="whitespace-normal px-2 py-2">
                                  <Badge variant={statusBadge[campaign.status]} className="max-w-full whitespace-normal text-left">{campaign.status}</Badge>
                                </TableCell>
                                <TableCell className="text-right whitespace-normal px-2 py-2">
                                  <div className="flex justify-end gap-1">
                                    <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => openEditCampaign(campaign)} title="Editar envio" aria-label="Editar envio">
                                      <PencilSimple size={13} />
                                    </Button>
                                    <Button variant="outline" size="icon" className="h-7 w-7 text-red-700 hover:text-red-700" onClick={() => deleteCampaign(campaign.id)} title="Apagar envio" aria-label="Apagar envio">
                                      <Trash size={13} />
                                    </Button>
                                  </div>
                                </TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      </div>
                    </>
                  )}
                </CardContent>
              </Card>
            ))}
            </>
            )
            ) : null}
          </TabsContent>

          <TabsContent value="execucao" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'execucao' ? (
            !hasDeliveries || loadingTab === 'execucao' ? (
            <SectionLoadingState message="A carregar histórico de execução..." />
            ) : (
            <Card>
              <CardHeader className="pb-2"><CardTitle className="text-sm">Histórico operacional de execução</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-2 xl:hidden">
                  {deliveriesData.map((delivery) => (
                    <div key={delivery.id} className="rounded-md border p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-sm font-medium break-words">{delivery.campaign?.codigo} - {delivery.campaign?.title}</p>
                          <p className="text-xs text-muted-foreground break-words">{delivery.segment?.name || '-'}</p>
                        </div>
                        <Badge variant={delivery.status === 'failed' ? 'destructive' : 'secondary'} className="max-w-full whitespace-normal text-left">{delivery.status}</Badge>
                      </div>
                      <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                        <p>Canal: <span className="uppercase">{delivery.channel}</span></p>
                        <p>Data/Hora: {delivery.sent_at ? new Date(delivery.sent_at).toLocaleString('pt-PT') : '-'}</p>
                        <p>Resultado: S:{delivery.success_count} F:{delivery.failed_count} P:{delivery.pending_count}</p>
                        <p className="break-words">Erro/Log: {delivery.error_message || delivery.result_summary || '-'}</p>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="hidden xl:block">
                  <Table className="table-fixed">
                    <TableHeader>
                      <TableRow>
                        <TableHead className="h-9 w-[22%] px-2 py-2 whitespace-normal">Campanha</TableHead>
                        <TableHead className="h-9 w-[8%] px-2 py-2 whitespace-normal">Canal</TableHead>
                        <TableHead className="h-9 w-[14%] px-2 py-2 whitespace-normal">Audiência</TableHead>
                        <TableHead className="h-9 w-[14%] px-2 py-2 whitespace-normal">Data/Hora</TableHead>
                        <TableHead className="h-9 w-[10%] px-2 py-2 whitespace-normal">Estado</TableHead>
                        <TableHead className="h-9 w-[12%] px-2 py-2 whitespace-normal">Resultado</TableHead>
                        <TableHead className="h-9 w-[20%] px-2 py-2 whitespace-normal">Logs/Erro</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {deliveriesData.map((delivery) => (
                        <TableRow key={delivery.id}>
                          <TableCell className="px-2 py-2 align-top text-sm whitespace-normal break-words">{delivery.campaign?.codigo} - {delivery.campaign?.title}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs uppercase whitespace-normal break-words">{delivery.channel}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-sm whitespace-normal break-words">{delivery.segment?.name || '-'}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs whitespace-normal break-words">{delivery.sent_at ? new Date(delivery.sent_at).toLocaleString('pt-PT') : '-'}</TableCell>
                          <TableCell className="px-2 py-2 align-top whitespace-normal"><Badge variant={delivery.status === 'failed' ? 'destructive' : 'secondary'} className="max-w-full whitespace-normal text-left">{delivery.status}</Badge></TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs whitespace-normal break-words">S:{delivery.success_count} F:{delivery.failed_count} P:{delivery.pending_count}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs text-muted-foreground whitespace-normal break-words">{delivery.error_message || delivery.result_summary || '-'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
            )
            ) : null}
          </TabsContent>

          <TabsContent value="templates" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'templates' ? (
            !hasTemplates || loadingTab === 'templates' ? (
            <SectionLoadingState message="A carregar templates..." />
            ) : (
            <>
            <div className="flex justify-end">
              <Button size="sm" className="h-8 text-xs" onClick={() => {
                setEditingTemplate(null);
                templateForm.setData({
                  name: '',
                  channel: 'email',
                  category: defaultAlertCategory,
                  subject: '',
                  body: '',
                  variables_json: templateVariableMap,
                  status: 'ativo',
                });
                setShowTemplateModal(true);
              }}>
                <Plus size={14} className="mr-1" />Novo template
              </Button>
            </div>
            <Card>
              <CardContent className="pt-4">
                <div className="space-y-2 xl:hidden">
                  {templatesData.map((template) => (
                    <div key={template.id} className="rounded-md border p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium">{template.name}</p>
                          <p className="text-xs text-muted-foreground">{alertCategoryLabel(alertCategories, template.category)} | <span className="uppercase">{template.channel}</span></p>
                        </div>
                        <Badge variant={template.status === 'inativo' ? 'destructive' : 'secondary'}>{template.status}</Badge>
                      </div>
                      <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                        <p>Assunto: {template.subject || '-'}</p>
                        <p>Variáveis: {Array.isArray(template.variables_json) ? template.variables_json.join(', ') : Object.keys(template.variables_json || {}).join(', ') || '-'}</p>
                      </div>
                      <div className="mt-3 flex justify-end gap-2">
                        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => {
                          templateForm.setData({
                            name: template.name,
                            channel: template.channel,
                            category: (template.category as AlertCategory) || 'geral',
                            subject: template.subject || '',
                            body: template.body,
                            variables_json: Array.isArray(template.variables_json) ? templateVariableMap : (template.variables_json || templateVariableMap),
                            status: template.status,
                          });
                          setEditingTemplate(template);
                          setShowTemplateModal(true);
                        }} title="Editar template" aria-label="Editar template">
                          <PencilSimple size={15} />
                        </Button>
                        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => router.post(route('comunicacao.templates.duplicate', template.id), {}, { preserveScroll: true })} title="Duplicar template" aria-label="Duplicar template">
                          <Files size={15} />
                        </Button>
                        <Button variant="outline" size="icon" className="h-8 w-8 text-red-700 hover:text-red-700" onClick={() => deleteTemplate(template.id)} title="Apagar template" aria-label="Apagar template">
                          <Trash size={15} />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="hidden xl:block">
                  <Table className="table-fixed">
                    <TableHeader>
                      <TableRow>
                        <TableHead className="h-9 w-[17%] px-2 py-2 whitespace-normal">Nome</TableHead>
                        <TableHead className="h-9 w-[10%] px-2 py-2 whitespace-normal">Canal</TableHead>
                        <TableHead className="h-9 w-[13%] px-2 py-2 whitespace-normal">Categoria</TableHead>
                        <TableHead className="h-9 w-[18%] px-2 py-2 whitespace-normal">Assunto</TableHead>
                        <TableHead className="h-9 w-[22%] px-2 py-2 whitespace-normal">Variáveis</TableHead>
                        <TableHead className="h-9 w-[8%] px-2 py-2 whitespace-normal">Estado</TableHead>
                        <TableHead className="h-9 w-[12%] px-2 py-2 text-right whitespace-normal">Ações</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {templatesData.map((template) => (
                        <TableRow key={template.id}>
                          <TableCell className="px-2 py-2 align-top text-sm font-medium whitespace-normal break-words">{template.name}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs uppercase whitespace-normal break-words">{template.channel}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-sm whitespace-normal break-words">{alertCategoryLabel(alertCategories, template.category)}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-sm whitespace-normal break-words">{template.subject || '-'}</TableCell>
                          <TableCell className="px-2 py-2 align-top text-xs leading-5 whitespace-normal break-words">{Array.isArray(template.variables_json) ? template.variables_json.join(', ') : Object.keys(template.variables_json || {}).join(', ') || '-'}</TableCell>
                          <TableCell className="px-2 py-2 align-top whitespace-normal">
                            <Badge variant={template.status === 'inativo' ? 'destructive' : 'secondary'} className="max-w-full whitespace-normal text-left">{template.status}</Badge>
                          </TableCell>
                          <TableCell className="px-2 py-2 align-top text-right whitespace-normal">
                            <div className="flex justify-end gap-1">
                              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => {
                                templateForm.setData({
                                  name: template.name,
                                  channel: template.channel,
                                  category: (template.category as AlertCategory) || 'geral',
                                  subject: template.subject || '',
                                  body: template.body,
                                  variables_json: Array.isArray(template.variables_json) ? templateVariableMap : (template.variables_json || templateVariableMap),
                                  status: template.status,
                                });
                                setEditingTemplate(template);
                                setShowTemplateModal(true);
                              }} title="Editar template" aria-label="Editar template">
                                <PencilSimple size={13} />
                              </Button>
                              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => router.post(route('comunicacao.templates.duplicate', template.id), {}, { preserveScroll: true })} title="Duplicar template" aria-label="Duplicar template">
                                <Files size={13} />
                              </Button>
                              <Button variant="outline" size="icon" className="h-7 w-7 text-red-700 hover:text-red-700" onClick={() => deleteTemplate(template.id)} title="Apagar template" aria-label="Apagar template">
                                <Trash size={13} />
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
            </>
            )
            ) : null}
          </TabsContent>

          <TabsContent value="segmentos" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'segmentos' ? (
            !hasSegments || loadingTab === 'segmentos' ? (
            <SectionLoadingState message="A carregar segmentos..." />
            ) : (
            <>
            <div className="flex justify-end">
              <Button size="sm" className="h-8 text-xs" onClick={() => { setEditingSegment(null); resetSegmentFormForCreate(); setShowSegmentModal(true); }}>
                <Plus size={14} className="mr-1" />Novo segmento
              </Button>
            </div>
            <Card>
              <CardContent className="pt-4">
                <div className="space-y-2 lg:hidden">
                  {segmentsData.map((segment) => (
                    <div key={segment.id} className="rounded-md border p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium">{segment.name}</p>
                          <p className="text-xs text-muted-foreground">Tipo: {segment.type}</p>
                        </div>
                        <Badge variant={segment.is_active ? 'secondary' : 'destructive'}>{segment.is_active ? 'Sim' : 'Não'}</Badge>
                      </div>
                      <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                        <p>Descrição: {segment.description || '-'}</p>
                        <p>Destinatários estimados: {segment.estimated_recipients || 0}</p>
                        <p>Escalões: {segment.resolved_age_group_labels?.join(', ') || '-'}</p>
                        <p>Tipos: {segment.resolved_user_types?.map(userTypeLabel).join(', ') || '-'}</p>
                        <p className="break-all">Regras: {segment.rules_json ? JSON.stringify(segment.rules_json) : '-'}</p>
                      </div>
                      <div className="mt-3 flex justify-end gap-2">
                        <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => {
                          populateSegmentForm(segment);
                          setEditingSegment(segment);
                          setShowSegmentModal(true);
                        }} title="Editar segmento" aria-label="Editar segmento">
                          <PencilSimple size={15} />
                        </Button>
                        <Button variant="outline" size="icon" className="h-8 w-8 text-red-700 hover:text-red-700" onClick={() => deleteSegment(segment.id)} title="Apagar segmento" aria-label="Apagar segmento">
                          <Trash size={15} />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="hidden lg:block">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Nome</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Descrição</TableHead>
                        <TableHead>Estimativa</TableHead>
                        <TableHead>Ativo</TableHead>
                        <TableHead>Regras</TableHead>
                        <TableHead className="text-right">Ações</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {segmentsData.map((segment) => (
                        <TableRow key={segment.id}>
                          <TableCell className="font-medium">{segment.name}</TableCell>
                          <TableCell>{segment.type}</TableCell>
                          <TableCell className="text-xs">{segment.description || '-'}</TableCell>
                          <TableCell>{segment.estimated_recipients || 0}</TableCell>
                          <TableCell><Badge variant={segment.is_active ? 'secondary' : 'destructive'}>{segment.is_active ? 'Sim' : 'Não'}</Badge></TableCell>
                          <TableCell className="text-xs">{segment.rules_json ? JSON.stringify(segment.rules_json) : '-'}</TableCell>
                          <TableCell className="text-right">
                            <div className="flex justify-end gap-1">
                              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => {
                                populateSegmentForm(segment);
                                setEditingSegment(segment);
                                setShowSegmentModal(true);
                              }} title="Editar segmento" aria-label="Editar segmento">
                                <PencilSimple size={13} />
                              </Button>
                              <Button variant="outline" size="icon" className="h-7 w-7 text-red-700 hover:text-red-700" onClick={() => deleteSegment(segment.id)} title="Apagar segmento" aria-label="Apagar segmento">
                                <Trash size={13} />
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
            </>
            )
            ) : null}
          </TabsContent>
        </Tabs>
      </div>

      <Dialog open={showCampaignModal} onOpenChange={setShowCampaignModal}>
        <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-4xl">
          <DialogHeader>
            <div className="flex items-center justify-between gap-2">
              <DialogTitle>Envio para Segmentos</DialogTitle>
              <Button type="button" variant="outline" size="sm" className="h-8 text-xs" onClick={() => setShowTemplateVariableHelp(true)}>
                <Info size={14} className="mr-1" />Ajuda variáveis
              </Button>
            </div>
          </DialogHeader>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Nome do envio</Label>
              <Input value={campaignForm.data.title} onChange={(e) => campaignForm.setData('title', e.target.value)} />
            </div>
            <div>
              <Label>Agendar para</Label>
              <Input type="datetime-local" value={campaignForm.data.scheduled_at} onChange={(e) => campaignForm.setData('scheduled_at', e.target.value)} />
            </div>
            <div>
              <Label>Segmento</Label>
              <Select value={campaignForm.data.segment_id} onValueChange={(value) => campaignForm.setData('segment_id', value)}>
                <SelectTrigger><SelectValue placeholder="Selecionar segmento" /></SelectTrigger>
                <SelectContent>
                  {filterOptions.segments.map((segment) => <SelectItem key={segment.id} value={segment.id}>{segment.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Assunto</Label>
              <Input value={campaignForm.data.base_subject} onChange={(e) => updateCampaignBaseSubject(e.target.value)} />
            </div>
            <div className="sm:col-span-2">
              <div className="mb-1 flex items-center justify-between gap-2">
                <Label>Mensagem</Label>
                <p className="text-[11px] text-muted-foreground">Usa variáveis como {'{{nome}}'}, {'{{mes}}'}, {'{{valor}}'} e {'{{evento_nome}}'} no assunto e na mensagem.</p>
              </div>
              <Textarea value={campaignForm.data.description} onChange={(e) => updateCampaignBaseMessage(e.target.value)} rows={4} />
            </div>
            <div className="sm:col-span-2 border rounded-md p-3 space-y-2">
              <div className="text-xs font-medium">Canais</div>
              {campaignForm.data.channels.map((channelRow, index) => (
                <div key={channelRow.channel} className="border rounded-md p-2 space-y-2">
                  <div className="flex items-center justify-between">
                    <div>
                      <div className="text-xs uppercase font-medium">{channelRow.channel}</div>
                      <div className="text-[11px] text-muted-foreground">{channelLabel(channelRow.channel as Channel)}</div>
                    </div>
                    <Switch checked={channelRow.is_enabled} onCheckedChange={(checked) => updateCampaignChannel(index, { is_enabled: checked })} />
                  </div>
                  {channelRow.is_enabled && (
                    <div className="space-y-3">
                      <div className="grid gap-3 lg:grid-cols-2">
                        <div className="space-y-1">
                          <Label className="text-[11px] text-muted-foreground">Assunto do canal</Label>
                          <Input placeholder="Assunto" value={channelRow.subject || ''} onChange={(e) => {
                            updateCampaignChannel(index, { subject: e.target.value }, { subjectCustomized: e.target.value !== campaignForm.data.base_subject });
                          }} />
                        </div>
                        <div className="space-y-1">
                          <Label className="text-[11px] text-muted-foreground">Mensagem do canal</Label>
                          <Textarea placeholder="Mensagem" value={channelRow.message_body || ''} onChange={(e) => {
                            updateCampaignChannel(index, { message_body: e.target.value }, { messageCustomized: e.target.value !== campaignForm.data.description });
                          }} rows={3} className="min-h-[88px]" />
                        </div>
                      </div>
                      <div>
                        <p className="text-[11px] text-muted-foreground">Este canal herda o assunto e a mensagem acima. Podes ajustar os campos apenas se precisares de uma versão diferente.</p>
                      </div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCampaignModal(false)}>Cancelar</Button>
            <Button onClick={() => saveCampaign(campaignSubmitMode)} disabled={campaignSubmitDisabled}>{campaignForm.processing ? 'A processar...' : campaignSubmitLabel}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showDirectModal} onOpenChange={setShowDirectModal}>
        <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-4xl">
          <DialogHeader><DialogTitle>Envio individual para atletas/membros</DialogTitle></DialogHeader>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Categoria do alerta</Label>
              <Select value={directForm.data.alert_category} onValueChange={updateDirectCategory}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {alertCategories.map((category) => (
                    <SelectItem key={category.value} value={category.value}>{category.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Nome da comunicação</Label>
              <Input value={directForm.data.title} onChange={(e) => directForm.setData('title', e.target.value)} />
              <p className="mt-1 text-[11px] text-muted-foreground">Se deixares em branco, o sistema gera um titulo automatico com base na categoria.</p>
            </div>
            <div>
              <Label>Agendar para</Label>
              <Input type="datetime-local" value={directForm.data.scheduled_at} onChange={(e) => directForm.setData('scheduled_at', e.target.value)} />
            </div>
            <div>
              <Label>Assunto</Label>
              <Input value={directForm.data.alert_title} onChange={(e) => updateDirectBaseSubject(e.target.value)} />
              <InputError message={directForm.errors.alert_title} className="mt-1 text-xs" />
            </div>
            <div>
              <Label>Tipo visual</Label>
              <Select value={directForm.data.alert_type} onValueChange={(value: 'info' | 'warning' | 'success' | 'error') => directForm.setData('alert_type', value)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="info">Info</SelectItem>
                  <SelectItem value="warning">Warning</SelectItem>
                  <SelectItem value="success">Success</SelectItem>
                  <SelectItem value="error">Error</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="sm:col-span-2">
              <Label>Mensagem</Label>
              <Textarea value={directForm.data.alert_message} onChange={(e) => updateDirectBaseMessage(e.target.value)} rows={2} />
              <InputError message={directForm.errors.alert_message} className="mt-1 text-xs" />
            </div>

            <div className="sm:col-span-2 rounded-md border p-3 space-y-2">
              <div className="grid gap-2 sm:grid-cols-4">
                <div>
                  <Label>Perfil</Label>
                  <Select value={recipientProfile} onValueChange={(value: RecipientProfile) => setRecipientProfile(value)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="athletes">Atletas</SelectItem>
                      <SelectItem value="members">Membros</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Escalão</Label>
                  <Select value={recipientAgeGroupFilter} onValueChange={setRecipientAgeGroupFilter}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {filterOptions.ageGroups.map((ageGroup) => (
                        <SelectItem key={ageGroup.id} value={ageGroup.id}>{ageGroup.nome}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Tipo de utilizador</Label>
                  <Select value={recipientUserTypeFilter} onValueChange={setRecipientUserTypeFilter}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {filterOptions.userTypes.map((userType) => (
                        <SelectItem key={userType.value} value={userType.value}>{userType.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Pesquisar destinatário</Label>
                  <Input placeholder="Nome, email ou telefone" value={recipientSearch} onChange={(e) => setRecipientSearch(e.target.value)} />
                </div>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                <div className="text-muted-foreground">Selecionados: {directForm.data.recipient_user_ids.length} | Visiveis: {filteredRecipients.length}</div>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" className="h-7 text-xs" type="button" onClick={selectVisibleRecipients}>Selecionar visiveis</Button>
                  <Button variant="outline" size="sm" className="h-7 text-xs" type="button" onClick={clearRecipientSelection}>Limpar</Button>
                </div>
              </div>
              <ScrollArea className="h-44 rounded-md border p-2">
                <div className="space-y-2">
                  {filteredRecipients.length === 0 && (
                    <div className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                      Nenhum destinatario corresponde aos filtros atuais.
                    </div>
                  )}
                  {filteredRecipients.map((recipient) => {
                    const fullName = recipient.nome_completo || recipient.name || recipient.id;
                    const phone = recipient.telemovel || recipient.contacto_telefonico || recipient.contacto || '-';
                    const checked = directForm.data.recipient_user_ids.includes(recipient.id);

                    return (
                      <label key={recipient.id} className="flex items-start gap-2 rounded-md border p-2 text-xs cursor-pointer">
                        <Checkbox checked={checked} onCheckedChange={(value) => toggleRecipient(recipient.id, value === true)} />
                        <div className="min-w-0">
                          <div className="font-medium truncate">{fullName}</div>
                          <div className="text-muted-foreground truncate">{recipient.email || '-'} | {phone}</div>
                          <div className="text-muted-foreground truncate">{recipient.numero_socio || '-'} | {(recipient.tipo_membro || []).map(userTypeLabel).join(', ') || '-'}</div>
                        </div>
                      </label>
                    );
                  })}
                </div>
              </ScrollArea>
              <InputError message={directForm.errors.recipient_user_ids} className="text-xs" />
            </div>

            <div className="sm:col-span-2 rounded-md border p-3 space-y-2">
              <div className="flex items-center justify-between gap-2">
                <div className="text-xs font-medium">Canais de envio</div>
                <div className="text-[11px] text-muted-foreground">Canais ativos: {enabledDirectChannels.length}</div>
              </div>
              <InputError message={directForm.errors.channels} className="text-xs" />
              {visibleDirectChannels.map((channelRow) => {
                const index = directForm.data.channels.findIndex((item) => item.channel === channelRow.channel);

                return (
                <div key={channelRow.channel} className="border rounded-md p-2 space-y-2">
                  <div className="flex items-center justify-between">
                    <div>
                      <div className="text-xs uppercase font-medium">{channelRow.channel}</div>
                      <div className="text-[11px] text-muted-foreground">{channelLabel(channelRow.channel as Channel)}</div>
                    </div>
                    <Switch
                      checked={channelRow.is_enabled}
                      onCheckedChange={(checked) => {
                        updateDirectChannel(index, { is_enabled: checked });
                      }}
                    />
                  </div>
                  {channelRow.is_enabled && (
                    <div className="grid gap-2 sm:grid-cols-3">
                      <Select value={channelRow.template_id || 'none'} onValueChange={(value) => {
                        updateDirectChannel(index, { template_id: value === 'none' ? '' : value });
                      }}>
                        <SelectTrigger><SelectValue placeholder="Template" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">Sem template</SelectItem>
                          {filteredTemplateOptions(channelRow.channel as Channel, directForm.data.alert_category).map((template) => (
                            <SelectItem key={template.id} value={template.id}>{template.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <Input placeholder="Assunto do canal" value={channelRow.subject || ''} onChange={(e) => {
                        updateDirectChannel(index, { subject: e.target.value }, { subjectCustomized: e.target.value !== directForm.data.alert_title });
                      }} />
                      <Input placeholder="Mensagem do canal" value={channelRow.message_body || ''} onChange={(e) => {
                        updateDirectChannel(index, { message_body: e.target.value }, { messageCustomized: e.target.value !== directForm.data.alert_message });
                      }} />
                      <div className="sm:col-span-3 space-y-1">
                        <p className="text-[11px] text-muted-foreground">Este canal herda o assunto e a mensagem base. Podes ajustar os campos abaixo ou escolher um template para personalizar {channelLabel(channelRow.channel as Channel).toLowerCase()}.</p>
                        <InputError message={directForm.errors[`channels.${index}.template_id` as keyof typeof directForm.errors]} className="text-xs" />
                        <InputError message={directForm.errors[`channels.${index}.message_body` as keyof typeof directForm.errors]} className="text-xs" />
                      </div>
                    </div>
                  )}
                </div>
              )})}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDirectModal(false)} disabled={directForm.processing}>Cancelar</Button>
            <Button onClick={saveDirectCommunication} disabled={directSubmitDisabled}>{directForm.processing ? 'A processar...' : directSubmitLabel}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showTemplateModal} onOpenChange={setShowTemplateModal}>
        <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <div className="flex items-center justify-between gap-2">
              <DialogTitle>{editingTemplate ? 'Editar template' : 'Novo template'}</DialogTitle>
              <Button type="button" variant="outline" size="sm" className="h-8 text-xs" onClick={() => setShowTemplateVariableHelp(true)}>Ajuda variáveis</Button>
            </div>
          </DialogHeader>
          <div className="space-y-2">
            <Label>Nome</Label>
            <Input value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} />
            <Label>Canal</Label>
            <Select value={templateForm.data.channel} onValueChange={(value: Channel) => templateForm.setData('channel', value)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{CHANNELS.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}</SelectContent>
            </Select>
            <Label>Categoria</Label>
            <Select value={templateForm.data.category} onValueChange={(value: AlertCategory) => templateForm.setData('category', value)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {alertCategories.map((category) => (
                  <SelectItem key={category.value} value={category.value}>{category.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Label>Estado</Label>
            <Select value={templateForm.data.status} onValueChange={(value: 'ativo' | 'em_revisao' | 'inativo') => templateForm.setData('status', value)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="ativo">Ativo</SelectItem>
                <SelectItem value="em_revisao">Em revisão</SelectItem>
                <SelectItem value="inativo">Inativo</SelectItem>
              </SelectContent>
            </Select>
            <Label>Assunto</Label>
            <Input value={templateForm.data.subject} onChange={(e) => templateForm.setData('subject', e.target.value)} />
            <p className="text-[11px] text-muted-foreground">Usa variáveis como {'{{nome}}'}, {'{{mes}}'}, {'{{valor}}'} e {'{{evento_nome}}'} dentro do assunto ou do conteúdo.</p>
            <Label>Corpo / Conteúdo</Label>
            <Textarea value={templateForm.data.body} onChange={(e) => templateForm.setData('body', e.target.value)} rows={5} />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowTemplateModal(false)}>Cancelar</Button>
            <Button onClick={saveTemplate}>Guardar template</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showSegmentModal} onOpenChange={setShowSegmentModal}>
        <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader><DialogTitle>{editingSegment ? 'Editar segmento' : 'Novo segmento'}</DialogTitle></DialogHeader>
          <div className="space-y-2">
            <Label>Nome</Label>
            <Input value={segmentForm.data.name} onChange={(e) => segmentForm.setData('name', e.target.value)} />
            <Label>Tipo</Label>
            <Select value={segmentForm.data.type} onValueChange={(value: 'dynamic' | 'manual' | 'system') => segmentForm.setData('type', value)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="dynamic">Dinâmico</SelectItem>
                <SelectItem value="manual">Manual</SelectItem>
                <SelectItem value="system">Sistema</SelectItem>
              </SelectContent>
            </Select>
            {segmentForm.data.type === 'dynamic' && (
              <>
                <Label>Fonte dinâmica</Label>
                <Select
                  value={segmentForm.data.dynamic_source_id || 'none'}
                  onValueChange={(value) => {
                    const dynamicSourceId = value === 'none' ? '' : value;
                    const source = filterOptions.dynamicSources.find((item) => item.id === dynamicSourceId);
                    segmentForm.setData({
                      ...segmentForm.data,
                      dynamic_source_id: dynamicSourceId,
                      source: source?.strategy || DEFAULT_DYNAMIC_SOURCE_STRATEGY,
                    });
                  }}
                >
                  <SelectTrigger><SelectValue placeholder="Selecionar fonte dinâmica" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sem fonte</SelectItem>
                    {filterOptions.dynamicSources.map((source) => (
                      <SelectItem key={source.id} value={source.id}>{source.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {selectedDynamicSource?.description && (
                  <p className="text-xs text-muted-foreground">{selectedDynamicSource.description}</p>
                )}
              </>
            )}
            {segmentForm.data.type === 'manual' && (
              <>
                <Label>Escalões incluídos</Label>
                <div className="grid gap-2 sm:grid-cols-2">
                  {filterOptions.ageGroups.map((ageGroup) => {
                    const checked = segmentForm.data.age_group_ids.includes(ageGroup.id);

                    return (
                      <label key={ageGroup.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <Checkbox checked={checked} onCheckedChange={(value) => toggleSegmentArrayValue('age_group_ids', ageGroup.id, value === true)} />
                        <span>{ageGroup.nome}</span>
                      </label>
                    );
                  })}
                </div>
                <Label>Tipos de utilizador incluídos</Label>
                <div className="grid gap-2 sm:grid-cols-2">
                  {filterOptions.userTypes.map((userType) => {
                    const checked = segmentForm.data.user_types.includes(userType.value);

                    return (
                      <label key={userType.value} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <Checkbox checked={checked} onCheckedChange={(value) => toggleSegmentArrayValue('user_types', userType.value, value === true)} />
                        <span>{userType.label}</span>
                      </label>
                    );
                  })}
                </div>
                <Label>IDs utilizadores específicos (opcional)</Label>
                <Input value={segmentForm.data.user_ids} onChange={(e) => segmentForm.setData('user_ids', e.target.value)} />
              </>
            )}
            <Label>Descrição</Label>
            <Textarea value={segmentForm.data.description} onChange={(e) => segmentForm.setData('description', e.target.value)} rows={3} />
            <div className="flex items-center gap-2">
              <Switch checked={segmentForm.data.is_active} onCheckedChange={(checked) => segmentForm.setData('is_active', checked)} />
              <span className="text-xs">Ativo</span>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowSegmentModal(false)}>Cancelar</Button>
            <Button onClick={saveSegment}>Guardar segmento</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={showTemplateVariableHelp} onOpenChange={setShowTemplateVariableHelp}>
        <DialogContent className="w-[95vw] max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader><DialogTitle>Variáveis dinâmicas disponíveis</DialogTitle></DialogHeader>
          <div className="space-y-2 text-sm">
            <p className="text-muted-foreground">Usa o formato {'{{variavel}}'} no assunto, na mensagem do envio ou no conteúdo dos templates. O sistema substitui cada variável pelo valor real do destinatário no momento do envio.</p>
            <div className="space-y-2">
              {filterOptions.templateVariables.map((variable) => (
                <div key={variable.key} className="rounded-md border p-3">
                  <div className="font-medium">{`{{${variable.key}}}`}</div>
                  <div className="text-xs text-muted-foreground">{variable.label} | {variable.description}</div>
                </div>
              ))}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowTemplateVariableHelp(false)}>Fechar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AuthenticatedLayout>
  );
}
