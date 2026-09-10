import { ModuleTabsList } from '@/Components/layout/ModuleTabsList';
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Badge } from '@/Components/ui/badge';
import { Textarea } from '@/Components/ui/textarea';
import {
  ArrowsClockwise,
  CurrencyCircleDollar,
  Eye,
  Handshake,
  Package,
  PencilSimple,
  Plus,
  ShieldCheck,
  Trash,
  WarningCircle,
  XCircle,
} from '@phosphor-icons/react';

type SponsorshipType = 'money' | 'goods' | 'mixed';
type SponsorshipStatus = 'ativo' | 'pendente' | 'fechado' | 'cancelado';
type Periodicity = 'pontual' | 'mensal' | 'trimestral' | 'anual';
type IntegrationStatus = 'pending' | 'generated' | 'failed';
type IntegrationType = 'financial' | 'stock';

type LookupSupplier = {
  id: string;
  nome: string;
};

type LookupSponsor = {
  id: string;
  nome: string;
  tipo: 'principal' | 'secundario' | 'apoio';
  estado: 'ativo' | 'inativo' | 'expirado';
  email?: string | null;
  contacto?: string | null;
  website?: string | null;
  valor_anual?: number | null;
  data_inicio: string;
  data_fim?: string | null;
};

type LookupCostCenter = {
  id: string;
  codigo: string;
  nome: string;
};

type LookupProduct = {
  id: string;
  codigo: string;
  nome: string;
  categoria?: string | null;
  area_armazenamento?: string | null;
};

type MoneyItem = {
  id?: string;
  description: string;
  amount: number | string;
  expected_date?: string | null;
  financial_movement_id?: string | null;
  integration_status?: IntegrationStatus;
  integration_message?: string | null;
};

type GoodsItem = {
  id?: string;
  item_name: string;
  item_id?: string | null;
  category?: string | null;
  quantity: number | string;
  unit_value?: number | string | null;
  total_value?: number | string | null;
  stock_entry_id?: string | null;
  integration_status?: IntegrationStatus;
  integration_message?: string | null;
};

type SponsorshipSummary = {
  id: string;
  codigo: string;
  sponsor_name: string;
  sponsor_id?: string | null;
  supplier_id?: string | null;
  type: SponsorshipType;
  title: string;
  description?: string | null;
  periodicity: Periodicity;
  start_date: string;
  end_date?: string | null;
  cost_center_id?: string | null;
  status: SponsorshipStatus;
  notes?: string | null;
  integration_status?: IntegrationStatus;
  money_total?: number;
  goods_total?: number;
  sponsor?: LookupSponsor | null;
  supplier?: LookupSupplier | null;
  costCenter?: LookupCostCenter | null;
  moneyItems: MoneyItem[];
  goodsItems: GoodsItem[];
};

type IntegrationRecord = {
  id: string;
  sponsorship_id: string;
  integration_type: IntegrationType;
  source_type: 'money_item' | 'goods_item' | 'mixed';
  source_id?: string | null;
  target_module: 'financeiro' | 'logistica' | string;
  target_table?: string | null;
  target_record_id?: string | null;
  status: IntegrationStatus;
  message?: string | null;
  executed_at?: string | null;
  created_at: string;
  sponsorship?: {
    id: string;
    codigo: string;
    sponsor_name: string;
    title: string;
  } | null;
};

type DashboardData = {
  active_total: number;
  monetary_total: number;
  goods_total: number;
  integrations: {
    pending: number;
    failed: number;
    generated: number;
  };
  by_type: {
    money: number;
    goods: number;
    mixed: number;
  };
  latest: SponsorshipSummary[];
  integration_block: {
    financial_movements: number;
    stock_entries: number;
  };
  alerts: IntegrationRecord[];
};

type Props = {
  tab?: 'dashboard' | 'patrocinios' | 'integracoes';
  filters: {
    search?: string;
    type?: string;
    status?: string;
    cost_center_id?: string;
    period_from?: string;
    period_to?: string;
  };
  dashboard: DashboardData;
  sponsorships: SponsorshipSummary[];
  integrations: IntegrationRecord[];
  lookups: {
    sponsors: LookupSponsor[];
    suppliers: LookupSupplier[];
    costCenters: LookupCostCenter[];
    products: LookupProduct[];
  };
};

type FormData = {
  sponsor_id: string;
  supplier_id: string;
  type: SponsorshipType;
  title: string;
  description: string;
  periodicity: Periodicity;
  start_date: string;
  end_date: string;
  cost_center_id: string;
  status: SponsorshipStatus;
  notes: string;
  money_items: MoneyItem[];
  goods_items: GoodsItem[];
};

function moneyItemsOf(sponsorship?: Partial<SponsorshipSummary> | null): MoneyItem[] {
  return Array.isArray(sponsorship?.moneyItems) ? sponsorship.moneyItems : [];
}

function goodsItemsOf(sponsorship?: Partial<SponsorshipSummary> | null): GoodsItem[] {
  return Array.isArray(sponsorship?.goodsItems) ? sponsorship.goodsItems : [];
}

const typeLabels: Record<SponsorshipType, string> = {
  money: 'Dinheiro',
  goods: 'Géneros',
  mixed: 'Misto',
};

const periodicityLabels: Record<Periodicity, string> = {
  pontual: 'Pontual',
  mensal: 'Mensal',
  trimestral: 'Trimestral',
  anual: 'Anual',
};

const statusLabels: Record<SponsorshipStatus, string> = {
  ativo: 'Ativo',
  pendente: 'Pendente',
  fechado: 'Fechado',
  cancelado: 'Cancelado',
};

const integrationStatusLabels: Record<IntegrationStatus, string> = {
  pending: 'Pendente',
  generated: 'Gerada',
  failed: 'Falhada',
};

const integrationTypeLabels: Record<IntegrationType, string> = {
  financial: 'Financeiro',
  stock: 'Stock',
};

const typeBadgeClasses: Record<SponsorshipType, string> = {
  money: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  goods: 'border-sky-200 bg-sky-50 text-sky-700',
  mixed: 'border-amber-200 bg-amber-50 text-amber-700',
};

const statusBadgeClasses: Record<SponsorshipStatus, string> = {
  ativo: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  pendente: 'border-amber-200 bg-amber-50 text-amber-700',
  fechado: 'border-slate-200 bg-slate-100 text-slate-700',
  cancelado: 'border-rose-200 bg-rose-50 text-rose-700',
};

const integrationBadgeClasses: Record<IntegrationStatus, string> = {
  pending: 'border-amber-200 bg-amber-50 text-amber-700',
  generated: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  failed: 'border-rose-200 bg-rose-50 text-rose-700',
};

function euro(value: number | string | null | undefined): string {
  return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(Number(value || 0));
}

function formatDate(value?: string | null): string {
  if (!value) return '-';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('pt-PT').format(date);
}

function formatDateInput(value?: string | null): string {
  return value ? value.slice(0, 10) : '';
}

function toNumber(value: number | string | null | undefined): number {
  return Number(value || 0);
}

function emptyMoneyItem(): MoneyItem {
  return {
    description: '',
    amount: '',
    expected_date: '',
  };
}

function emptyGoodsItem(): GoodsItem {
  return {
    item_name: '',
    item_id: '',
    category: '',
    quantity: '',
    unit_value: '',
    total_value: '',
  };
}

function emptyFormData(): FormData {
  return {
    sponsor_id: '',
    supplier_id: '',
    type: 'money',
    title: '',
    description: '',
    periodicity: 'pontual',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '',
    cost_center_id: '',
    status: 'pendente',
    notes: '',
    money_items: [emptyMoneyItem()],
    goods_items: [emptyGoodsItem()],
  };
}

function mapSponsorshipToFormData(sponsorship: SponsorshipSummary): FormData {
  const moneyItems = moneyItemsOf(sponsorship);
  const goodsItems = goodsItemsOf(sponsorship);

  return {
    sponsor_id: sponsorship.sponsor_id || sponsorship.sponsor?.id || '',
    supplier_id: sponsorship.supplier_id || '',
    type: sponsorship.type,
    title: sponsorship.title,
    description: sponsorship.description || '',
    periodicity: sponsorship.periodicity,
    start_date: formatDateInput(sponsorship.start_date),
    end_date: formatDateInput(sponsorship.end_date),
    cost_center_id: sponsorship.cost_center_id || '',
    status: sponsorship.status,
    notes: sponsorship.notes || '',
    money_items: moneyItems.length > 0
      ? moneyItems.map((item) => ({
          id: item.id,
          description: item.description || '',
          amount: item.amount || '',
          expected_date: formatDateInput(item.expected_date),
          financial_movement_id: item.financial_movement_id || '',
          integration_status: item.integration_status,
          integration_message: item.integration_message || '',
        }))
      : [emptyMoneyItem()],
    goods_items: goodsItems.length > 0
      ? goodsItems.map((item) => ({
          id: item.id,
          item_name: item.item_name || '',
          item_id: item.item_id || '',
          category: item.category || '',
          quantity: item.quantity || '',
          unit_value: item.unit_value || '',
          total_value: item.total_value || '',
          stock_entry_id: item.stock_entry_id || '',
          integration_status: item.integration_status,
          integration_message: item.integration_message || '',
        }))
      : [emptyGoodsItem()],
  };
}

function cleanQuery<T extends Record<string, string | null | undefined>>(query: T): Record<string, string> {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  ) as Record<string, string>;
}

function isLockedMoneyItem(item: MoneyItem): boolean {
  return item.integration_status === 'generated' || Boolean(item.financial_movement_id);
}

function isLockedGoodsItem(item: GoodsItem): boolean {
  return item.integration_status === 'generated' || Boolean(item.stock_entry_id);
}

function computeGoodsTotal(item: GoodsItem): string {
  const quantity = toNumber(item.quantity);
  const unitValue = toNumber(item.unit_value);

  if (!quantity || !unitValue) {
    return item.total_value ? String(item.total_value) : '';
  }

  return (quantity * unitValue).toFixed(2);
}

function summarizeValue(sponsorship: SponsorshipSummary): string {
  const moneyTotal = toNumber(sponsorship.money_total);
  const goodsCount = goodsItemsOf(sponsorship).length;

  if (sponsorship.type === 'money') {
    return euro(moneyTotal);
  }

  if (sponsorship.type === 'goods') {
    return `${goodsCount} artigo(s)`;
  }

  return `${euro(moneyTotal)} + ${goodsCount} artigo(s)`;
}

function integrationDestination(integration: IntegrationRecord): string {
  if (integration.target_module === 'financeiro') {
    return 'Financeiro > Movimentos';
  }

  if (integration.target_module === 'logistica') {
    return 'Logística > Stock';
  }

  return integration.target_module;
}

export default function SponsorshipsIndex({
  tab = 'dashboard',
  filters,
  dashboard,
  sponsorships,
  integrations,
  lookups,
}: Props) {
  const [activeTab, setActiveTab] = useState<Props['tab']>(tab);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [dialogMode, setDialogMode] = useState<'create' | 'edit' | 'view'>('create');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [formData, setFormData] = useState<FormData>(emptyFormData());
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [selectedIntegrationSponsorId, setSelectedIntegrationSponsorId] = useState<string | null>(null);
  const [listFilters, setListFilters] = useState({
    search: filters.search || '',
    type: filters.type || 'all',
    status: filters.status || 'all',
    cost_center_id: filters.cost_center_id || 'all',
    period_from: filters.period_from || '',
    period_to: filters.period_to || '',
  });
  const [integrationFilters, setIntegrationFilters] = useState({
    search: '',
    status: 'all',
    integration_type: 'all',
    target_module: 'all',
  });

  useEffect(() => {
    setActiveTab(tab);
  }, [tab]);

  useEffect(() => {
    setListFilters({
      search: filters.search || '',
      type: filters.type || 'all',
      status: filters.status || 'all',
      cost_center_id: filters.cost_center_id || 'all',
      period_from: filters.period_from || '',
      period_to: filters.period_to || '',
    });
  }, [filters]);

  const selectedIntegrationSponsorship = useMemo(
    () => sponsorships.find((sponsorship) => sponsorship.id === selectedIntegrationSponsorId) || null,
    [selectedIntegrationSponsorId, sponsorships],
  );

  const filteredIntegrations = useMemo(() => {
    const search = integrationFilters.search.trim().toLowerCase();

    return integrations.filter((integration) => {
      const sponsor = integration.sponsorship;
      const matchesSponsor = !selectedIntegrationSponsorId || integration.sponsorship_id === selectedIntegrationSponsorId;
      const matchesStatus = integrationFilters.status === 'all' || integration.status === integrationFilters.status;
      const matchesType = integrationFilters.integration_type === 'all' || integration.integration_type === integrationFilters.integration_type;
      const matchesTarget = integrationFilters.target_module === 'all' || integration.target_module === integrationFilters.target_module;
      const matchesSearch = !search
        || sponsor?.codigo.toLowerCase().includes(search)
        || sponsor?.sponsor_name.toLowerCase().includes(search)
        || sponsor?.title.toLowerCase().includes(search)
        || (integration.message || '').toLowerCase().includes(search)
        || String(integration.target_record_id || '').toLowerCase().includes(search);

      return matchesSponsor && matchesStatus && matchesType && matchesTarget && matchesSearch;
    });
  }, [integrationFilters, integrations, selectedIntegrationSponsorId]);

  const openCreateDialog = () => {
    setDialogMode('create');
    setEditingId(null);
    setFormErrors({});
    setFormData(emptyFormData());
    setDialogOpen(true);
  };

  const openViewDialog = (sponsorship: SponsorshipSummary) => {
    setDialogMode('view');
    setEditingId(sponsorship.id);
    setFormErrors({});
    setFormData(mapSponsorshipToFormData(sponsorship));
    setDialogOpen(true);
  };

  const openEditDialog = (sponsorship: SponsorshipSummary) => {
    setDialogMode('edit');
    setEditingId(sponsorship.id);
    setFormErrors({});
    setFormData(mapSponsorshipToFormData(sponsorship));
    setDialogOpen(true);
  };

  const closeDialog = (open: boolean) => {
    setDialogOpen(open);

    if (!open) {
      setFormErrors({});
      setSubmitting(false);
    }
  };

  const setField = <K extends keyof FormData>(field: K, value: FormData[K]) => {
    setFormData((current) => ({ ...current, [field]: value }));
  };

  const updateMoneyItem = (index: number, field: keyof MoneyItem, value: string) => {
    setFormData((current) => {
      const nextItems = [...current.money_items];
      nextItems[index] = { ...nextItems[index], [field]: value };

      return { ...current, money_items: nextItems };
    });
  };

  const addMoneyItem = () => {
    setFormData((current) => ({ ...current, money_items: [...current.money_items, emptyMoneyItem()] }));
  };

  const removeMoneyItem = (index: number) => {
    setFormData((current) => ({
      ...current,
      money_items: current.money_items.length > 1 ? current.money_items.filter((_, itemIndex) => itemIndex !== index) : [emptyMoneyItem()],
    }));
  };

  const updateGoodsItem = (index: number, field: keyof GoodsItem, value: string) => {
    setFormData((current) => {
      const nextItems = [...current.goods_items];
      const nextItem = { ...nextItems[index], [field]: value };

      if (field === 'quantity' || field === 'unit_value') {
        nextItem.total_value = computeGoodsTotal(nextItem);
      }

      nextItems[index] = nextItem;

      return { ...current, goods_items: nextItems };
    });
  };

  const changeGoodsProduct = (index: number, productId: string) => {
    const product = lookups.products.find((item) => item.id === productId);

    setFormData((current) => {
      const nextItems = [...current.goods_items];
      const currentItem = nextItems[index];

      nextItems[index] = {
        ...currentItem,
        item_id: productId,
        item_name: product?.nome || currentItem.item_name,
        category: product?.categoria || currentItem.category,
        total_value: computeGoodsTotal({
          ...currentItem,
          item_id: productId,
          item_name: product?.nome || currentItem.item_name,
          category: product?.categoria || currentItem.category,
        }),
      };

      return { ...current, goods_items: nextItems };
    });
  };

  const addGoodsItem = () => {
    setFormData((current) => ({ ...current, goods_items: [...current.goods_items, emptyGoodsItem()] }));
  };

  const removeGoodsItem = (index: number) => {
    setFormData((current) => ({
      ...current,
      goods_items: current.goods_items.length > 1 ? current.goods_items.filter((_, itemIndex) => itemIndex !== index) : [emptyGoodsItem()],
    }));
  };

  const submitFilters = () => {
    router.get(
      '/patrocinios',
      cleanQuery({
        tab: 'patrocinios',
        search: listFilters.search,
        type: listFilters.type === 'all' ? '' : listFilters.type,
        status: listFilters.status === 'all' ? '' : listFilters.status,
        cost_center_id: listFilters.cost_center_id === 'all' ? '' : listFilters.cost_center_id,
        period_from: listFilters.period_from,
        period_to: listFilters.period_to,
      }),
      { preserveScroll: true, preserveState: true, replace: true },
    );
  };

  const clearFilters = () => {
    setListFilters({
      search: '',
      type: 'all',
      status: 'all',
      cost_center_id: 'all',
      period_from: '',
      period_to: '',
    });

    router.get('/patrocinios', { tab: 'patrocinios' }, { preserveScroll: true, preserveState: true, replace: true });
  };

  const handleTabChange = (value: string) => {
    const nextTab = value as Props['tab'];
    setActiveTab(nextTab);

    router.get(
      '/patrocinios',
      cleanQuery({
        tab: nextTab,
        search: listFilters.search,
        type: listFilters.type === 'all' ? '' : listFilters.type,
        status: listFilters.status === 'all' ? '' : listFilters.status,
        cost_center_id: listFilters.cost_center_id === 'all' ? '' : listFilters.cost_center_id,
        period_from: listFilters.period_from,
        period_to: listFilters.period_to,
      }),
      { preserveScroll: true, preserveState: true, replace: true },
    );
  };

  const openIntegrationsTab = (sponsorshipId: string) => {
    setSelectedIntegrationSponsorId(sponsorshipId);
    handleTabChange('integracoes');
  };

  const normalizePayload = () => {
    return {
      sponsor_id: formData.sponsor_id,
      supplier_id: formData.supplier_id || null,
      type: formData.type,
      title: formData.title,
      description: formData.description || null,
      periodicity: formData.periodicity,
      start_date: formData.start_date,
      end_date: formData.end_date || null,
      cost_center_id: formData.cost_center_id || null,
      status: formData.status,
      notes: formData.notes || null,
      money_items: (formData.type === 'money' || formData.type === 'mixed')
        ? formData.money_items.map((item) => ({
            id: item.id || undefined,
            description: item.description,
            amount: item.amount === '' ? null : Number(item.amount),
            expected_date: item.expected_date || null,
          }))
        : [],
      goods_items: (formData.type === 'goods' || formData.type === 'mixed')
        ? formData.goods_items.map((item) => ({
            id: item.id || undefined,
            item_name: item.item_name,
            item_id: item.item_id || null,
            category: item.category || null,
            quantity: item.quantity === '' ? null : Number(item.quantity),
            unit_value: item.unit_value === '' ? null : Number(item.unit_value),
            total_value: computeGoodsTotal(item) === '' ? null : Number(computeGoodsTotal(item)),
          }))
        : [],
    };
  };

  const submitForm = () => {
    if (dialogMode === 'view') {
      setDialogOpen(false);
      return;
    }

    setSubmitting(true);
    setFormErrors({});

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        setSubmitting(false);
        setDialogOpen(false);
        setEditingId(null);
        setFormData(emptyFormData());
      },
      onError: (errors: Record<string, string>) => {
        setSubmitting(false);
        setFormErrors(errors);
      },
      onFinish: () => {
        setSubmitting(false);
      },
    };

    if (dialogMode === 'edit' && editingId) {
      router.put(`/patrocinios/${editingId}`, normalizePayload(), options);
      return;
    }

    router.post('/patrocinios', normalizePayload(), options);
  };

  const closeSponsorship = (sponsorshipId: string) => {
    if (!window.confirm('Pretende fechar este patrocínio?')) {
      return;
    }

    router.post(`/patrocinios/${sponsorshipId}/fechar`, {}, { preserveScroll: true });
  };

  const cancelSponsorship = (sponsorshipId: string) => {
    if (!window.confirm('Pretende cancelar este patrocínio?')) {
      return;
    }

    router.post(`/patrocinios/${sponsorshipId}/cancelar`, {}, { preserveScroll: true });
  };

  const deleteSponsorship = (sponsorshipId: string) => {
    if (!window.confirm('Pretende apagar este patrocínio? Esta ação remove também as integrações e os registos gerados no Financeiro e na Logística.')) {
      return;
    }

    router.delete(`/patrocinios/${sponsorshipId}`, { preserveScroll: true });
  };

  const retryIntegrations = (sponsorshipId: string) => {
    router.post(`/patrocinios/${sponsorshipId}/integracoes/retry`, {}, { preserveScroll: true });
  };

  const isReadOnly = dialogMode === 'view';

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div>
          <h1 className="text-lg font-semibold tracking-tight sm:text-xl">Patrocínios</h1>
          <p className="mt-0.5 text-xs text-muted-foreground">Contratos, apoios e integrações automáticas com Financeiro e Logística</p>
        </div>
      }
    >
      <Head title="Patrocínios" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={handleTabChange} className={moduleTabsClass}>
          <ModuleTabsList label="Áreas de Patrocínios">
            <TabsTrigger value="dashboard" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] sm:h-7 sm:text-xs">
              <Handshake size={14} />
              <span>Dashboard</span>
            </TabsTrigger>
            <TabsTrigger value="patrocinios" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] sm:h-7 sm:text-xs">
              <ShieldCheck size={14} />
              <span>Patrocínios</span>
            </TabsTrigger>
            <TabsTrigger value="integracoes" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] sm:h-7 sm:text-xs">
              <ArrowsClockwise size={14} />
              <span>Integração</span>
            </TabsTrigger>
          </ModuleTabsList>

          <TabsContent value="dashboard" className={`${moduleTabbedContentClass} space-y-3`}>
            <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
              <Card className="gap-0 py-0">
                <CardContent className="flex items-start justify-between p-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Patrocínios ativos</p>
                    <p className="mt-1 text-2xl font-semibold">{dashboard.active_total}</p>
                  </div>
                  <div className="rounded-md bg-emerald-50 p-2 text-emerald-700">
                    <Handshake size={18} />
                  </div>
                </CardContent>
              </Card>

              <Card className="gap-0 py-0">
                <CardContent className="flex items-start justify-between p-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Total monetário</p>
                    <p className="mt-1 text-2xl font-semibold">{euro(dashboard.monetary_total)}</p>
                  </div>
                  <div className="rounded-md bg-emerald-50 p-2 text-emerald-700">
                    <CurrencyCircleDollar size={18} />
                  </div>
                </CardContent>
              </Card>

              <Card className="gap-0 py-0">
                <CardContent className="flex items-start justify-between p-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Patrocínios em géneros</p>
                    <p className="mt-1 text-2xl font-semibold">{dashboard.goods_total}</p>
                  </div>
                  <div className="rounded-md bg-sky-50 p-2 text-sky-700">
                    <Package size={18} />
                  </div>
                </CardContent>
              </Card>

              <Card className="gap-0 py-0">
                <CardContent className="flex items-start justify-between p-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Integrações pendentes</p>
                    <p className="mt-1 text-2xl font-semibold">{dashboard.integrations.pending}</p>
                  </div>
                  <div className="rounded-md bg-amber-50 p-2 text-amber-700">
                    <WarningCircle size={18} />
                  </div>
                </CardContent>
              </Card>

              <Card className="gap-0 py-0">
                <CardContent className="flex items-start justify-between p-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Integrações falhadas</p>
                    <p className="mt-1 text-2xl font-semibold">{dashboard.integrations.failed}</p>
                  </div>
                  <div className="rounded-md bg-rose-50 p-2 text-rose-700">
                    <XCircle size={18} />
                  </div>
                </CardContent>
              </Card>
            </div>

            <div className="grid gap-3 xl:grid-cols-[1.5fr_1fr]">
              <Card className="gap-0 py-0">
                <CardHeader className="border-b px-4 py-3">
                  <CardTitle className="text-sm">Últimos patrocínios criados</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="min-w-0">
                    <Table responsive>
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-[120px]">Código</TableHead>
                          <TableHead>Patrocinador</TableHead>
                          <TableHead>Título</TableHead>
                          <TableHead>Tipo</TableHead>
                          <TableHead>Estado</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {dashboard.latest.map((sponsorship) => (
                          <TableRow key={sponsorship.id} className="text-xs">
                            <TableCell label="Código" className="font-medium">{sponsorship.codigo}</TableCell>
                            <TableCell label="Patrocinador">
                              <div className="font-medium">{sponsorship.sponsor?.nome || sponsorship.sponsor_name}</div>
                              <div className="text-muted-foreground">{sponsorship.supplier?.nome || 'Sem fornecedor associado'}</div>
                            </TableCell>
                            <TableCell label="Título">{sponsorship.title}</TableCell>
                            <TableCell label="Tipo">
                              <Badge variant="outline" className={typeBadgeClasses[sponsorship.type]}>
                                {typeLabels[sponsorship.type]}
                              </Badge>
                            </TableCell>
                            <TableCell label="Estado">
                              <Badge variant="outline" className={statusBadgeClasses[sponsorship.status]}>
                                {statusLabels[sponsorship.status]}
                              </Badge>
                            </TableCell>
                          </TableRow>
                        ))}

                        {dashboard.latest.length === 0 && (
                          <TableRow>
                            <TableCell colSpan={5} className="py-8 text-center text-sm text-muted-foreground">
                              Ainda não existem patrocínios registados.
                            </TableCell>
                          </TableRow>
                        )}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>

              <div className="space-y-3">
                <Card className="gap-0 py-0">
                  <CardHeader className="border-b px-4 py-3">
                    <CardTitle className="text-sm">Resumo por tipo</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-2 p-4">
                    <div className="flex items-center justify-between rounded-md border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-sm">
                      <span>Dinheiro</span>
                      <span className="font-semibold">{dashboard.by_type.money}</span>
                    </div>
                    <div className="flex items-center justify-between rounded-md border border-sky-100 bg-sky-50/60 px-3 py-2 text-sm">
                      <span>Géneros</span>
                      <span className="font-semibold">{dashboard.by_type.goods}</span>
                    </div>
                    <div className="flex items-center justify-between rounded-md border border-amber-100 bg-amber-50/60 px-3 py-2 text-sm">
                      <span>Misto</span>
                      <span className="font-semibold">{dashboard.by_type.mixed}</span>
                    </div>
                  </CardContent>
                </Card>

                <Card className="gap-0 py-0">
                  <CardHeader className="border-b px-4 py-3">
                    <CardTitle className="text-sm">Bloco de integração</CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-2 p-4 text-sm">
                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                      <span>Movimentos no Financeiro</span>
                      <span className="font-semibold">{dashboard.integration_block.financial_movements}</span>
                    </div>
                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                      <span>Entradas em Stock</span>
                      <span className="font-semibold">{dashboard.integration_block.stock_entries}</span>
                    </div>
                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                      <span>Geradas</span>
                      <span className="font-semibold text-emerald-700">{dashboard.integrations.generated}</span>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </div>

            <Card className="gap-0 py-0">
              <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-sm">Alertas de integração</CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <div className="min-w-0">
                  <Table responsive>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Data</TableHead>
                        <TableHead>Patrocínio</TableHead>
                        <TableHead>Destino</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Mensagem</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {dashboard.alerts.map((integration) => (
                        <TableRow key={integration.id} className="text-xs">
                          <TableCell label="Data">{formatDate(integration.executed_at || integration.created_at)}</TableCell>
                          <TableCell label="Patrocínio">
                            <div className="font-medium">{integration.sponsorship?.codigo}</div>
                            <div className="text-muted-foreground">{integration.sponsorship?.sponsor_name}</div>
                          </TableCell>
                          <TableCell label="Destino">{integrationDestination(integration)}</TableCell>
                          <TableCell label="Estado">
                            <Badge variant="outline" className={integrationBadgeClasses[integration.status]}>
                              {integrationStatusLabels[integration.status]}
                            </Badge>
                          </TableCell>
                          <TableCell label="Mensagem" className="whitespace-normal">{integration.message || '-'}</TableCell>
                        </TableRow>
                      ))}

                      {dashboard.alerts.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={5} className="py-8 text-center text-sm text-muted-foreground">
                            Sem alertas pendentes ou falhados.
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="patrocinios" className={`${moduleTabbedContentClass} space-y-3`}>
            <Card className="gap-0 py-0">
              <CardContent className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-3 lg:items-end">
                <div>
                  <Label className="text-xs">Pesquisa</Label>
                  <Input
                    className="mt-1 h-9 bg-white"
                    value={listFilters.search}
                    onChange={(event) => setListFilters((current) => ({ ...current, search: event.target.value }))}
                    placeholder="Código, patrocinador, título ou descrição"
                  />
                </div>

                <div>
                  <Label className="text-xs">Tipo</Label>
                  <Select value={listFilters.type} onValueChange={(value) => setListFilters((current) => ({ ...current, type: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="money">Dinheiro</SelectItem>
                      <SelectItem value="goods">Géneros</SelectItem>
                      <SelectItem value="mixed">Misto</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label className="text-xs">Estado</Label>
                  <Select value={listFilters.status} onValueChange={(value) => setListFilters((current) => ({ ...current, status: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="ativo">Ativo</SelectItem>
                      <SelectItem value="pendente">Pendente</SelectItem>
                      <SelectItem value="fechado">Fechado</SelectItem>
                      <SelectItem value="cancelado">Cancelado</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label className="text-xs">Centro de custo</Label>
                  <Select value={listFilters.cost_center_id} onValueChange={(value) => setListFilters((current) => ({ ...current, cost_center_id: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {lookups.costCenters.map((costCenter) => (
                        <SelectItem key={costCenter.id} value={costCenter.id}>
                          {costCenter.codigo} · {costCenter.nome}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label className="text-xs">De</Label>
                  <Input
                    className="mt-1 h-9 bg-white"
                    type="date"
                    value={listFilters.period_from}
                    onChange={(event) => setListFilters((current) => ({ ...current, period_from: event.target.value }))}
                  />
                </div>

                <div>
                  <Label className="text-xs">Até</Label>
                  <Input
                    className="mt-1 h-9 bg-white"
                    type="date"
                    value={listFilters.period_to}
                    onChange={(event) => setListFilters((current) => ({ ...current, period_to: event.target.value }))}
                  />
                </div>

                <div className="flex gap-2 lg:justify-end">
                  <Button type="button" variant="outline" className="h-9" onClick={clearFilters}>
                    Limpar
                  </Button>
                  <Button type="button" className="h-9" onClick={submitFilters}>
                    Filtrar
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card className="gap-0 py-0">
              <CardHeader className="flex flex-col gap-2 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <CardTitle className="text-sm">Tabela principal de patrocínios</CardTitle>
                  <p className="mt-1 text-xs text-muted-foreground">{sponsorships.length} registo(s) devolvido(s) pelos filtros atuais.</p>
                </div>

                <Button type="button" className="h-8 gap-1.5 text-xs" onClick={openCreateDialog}>
                  <Plus size={14} />
                  Novo patrocínio
                </Button>
              </CardHeader>
              <CardContent className="p-0">
                <div className="space-y-3 p-3 lg:hidden">
                  {sponsorships.map((sponsorship) => {
                    const canTransition = sponsorship.status !== 'cancelado' && sponsorship.status !== 'fechado';

                    return (
                      <div key={sponsorship.id} className="space-y-3 rounded-lg border p-3 text-xs">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="font-semibold text-foreground">{sponsorship.codigo}</p>
                            <p className="truncate font-medium">{sponsorship.sponsor?.nome || sponsorship.sponsor_name}</p>
                            <p className="truncate text-muted-foreground">{sponsorship.title}</p>
                          </div>
                          <Badge variant="outline" className={typeBadgeClasses[sponsorship.type]}>
                            {typeLabels[sponsorship.type]}
                          </Badge>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                          <div className="rounded-md bg-slate-50 px-2.5 py-2">
                            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Resumo</p>
                            <p className="mt-1 font-medium">{summarizeValue(sponsorship)}</p>
                            <p className="text-muted-foreground">{goodsItemsOf(sponsorship).length} artigo(s) / {moneyItemsOf(sponsorship).length} linha(s)</p>
                          </div>
                          <div className="rounded-md bg-slate-50 px-2.5 py-2">
                            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Vigência</p>
                            <p className="mt-1 font-medium">{periodicityLabels[sponsorship.periodicity]}</p>
                            <p className="text-muted-foreground">{formatDate(sponsorship.start_date)} até {formatDate(sponsorship.end_date)}</p>
                          </div>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                          <div>
                            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Fornecedor / Centro</p>
                            <p>{sponsorship.supplier?.nome || 'Sem fornecedor associado'}</p>
                            <p className="text-muted-foreground">{sponsorship.costCenter ? `${sponsorship.costCenter.codigo} · ${sponsorship.costCenter.nome}` : 'Sem centro de custo'}</p>
                          </div>
                          <div className="flex flex-wrap items-start gap-2">
                            <Badge variant="outline" className={statusBadgeClasses[sponsorship.status]}>
                              {statusLabels[sponsorship.status]}
                            </Badge>
                            <Badge variant="outline" className={integrationBadgeClasses[sponsorship.integration_status || 'pending']}>
                              {integrationStatusLabels[sponsorship.integration_status || 'pending']}
                            </Badge>
                          </div>
                        </div>

                        <div className="flex flex-wrap gap-1.5">
                          <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openViewDialog(sponsorship)}>
                            <Eye size={14} />
                          </Button>
                          <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openEditDialog(sponsorship)}>
                            <PencilSimple size={14} />
                          </Button>
                          <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs text-rose-700" onClick={() => deleteSponsorship(sponsorship.id)}>
                            <Trash size={14} />
                          </Button>
                          {canTransition && (
                            <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => closeSponsorship(sponsorship.id)}>
                              Fechar
                            </Button>
                          )}
                          {canTransition && (
                            <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs text-rose-700" onClick={() => cancelSponsorship(sponsorship.id)}>
                              Cancelar
                            </Button>
                          )}
                          <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openIntegrationsTab(sponsorship.id)}>
                            Integrações
                          </Button>
                        </div>
                      </div>
                    );
                  })}

                  {sponsorships.length === 0 && (
                    <div className="py-10 text-center text-sm text-muted-foreground">
                      Nenhum patrocínio encontrado para os filtros selecionados.
                    </div>
                  )}
                </div>

                <div className="hidden lg:block">
                  <Table className="table-fixed">
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-[110px]">Código</TableHead>
                        <TableHead className="w-[22%]">Patrocínio</TableHead>
                        <TableHead className="w-[24%]">Contrato</TableHead>
                        <TableHead className="w-[16%]">Vigência</TableHead>
                        <TableHead className="w-[12%]">Valor</TableHead>
                        <TableHead className="w-[14%]">Estado</TableHead>
                        <TableHead className="w-[240px] text-right">Ações</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {sponsorships.map((sponsorship) => {
                        const canTransition = sponsorship.status !== 'cancelado' && sponsorship.status !== 'fechado';

                        return (
                          <TableRow key={sponsorship.id} className="text-xs">
                            <TableCell className="font-medium">{sponsorship.codigo}</TableCell>
                            <TableCell>
                              <div className="truncate font-medium">{sponsorship.sponsor?.nome || sponsorship.sponsor_name}</div>
                              <div className="truncate text-muted-foreground">{sponsorship.supplier?.nome || 'Sem fornecedor associado'}</div>
                              <div className="truncate text-muted-foreground">{sponsorship.costCenter ? `${sponsorship.costCenter.codigo} · ${sponsorship.costCenter.nome}` : 'Sem centro de custo'}</div>
                            </TableCell>
                            <TableCell>
                              <div className="truncate font-medium">{sponsorship.title}</div>
                              <div className="mt-1 flex flex-wrap gap-1">
                                <Badge variant="outline" className={typeBadgeClasses[sponsorship.type]}>
                                  {typeLabels[sponsorship.type]}
                                </Badge>
                              </div>
                              <div className="mt-1 truncate text-muted-foreground">{goodsItemsOf(sponsorship).length} artigo(s) / {moneyItemsOf(sponsorship).length} linha(s) monetária(s)</div>
                            </TableCell>
                            <TableCell>
                              <div className="font-medium">{periodicityLabels[sponsorship.periodicity]}</div>
                              <div className="text-muted-foreground">{formatDate(sponsorship.start_date)}</div>
                              <div className="truncate text-muted-foreground">até {formatDate(sponsorship.end_date)}</div>
                            </TableCell>
                            <TableCell className="font-medium">{summarizeValue(sponsorship)}</TableCell>
                            <TableCell>
                              <div className="flex flex-col items-start gap-1">
                                <Badge variant="outline" className={statusBadgeClasses[sponsorship.status]}>
                                  {statusLabels[sponsorship.status]}
                                </Badge>
                                <Badge variant="outline" className={integrationBadgeClasses[sponsorship.integration_status || 'pending']}>
                                  {integrationStatusLabels[sponsorship.integration_status || 'pending']}
                                </Badge>
                              </div>
                            </TableCell>
                            <TableCell>
                              <div className="flex flex-wrap justify-end gap-1.5">
                                <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openViewDialog(sponsorship)}>
                                  <Eye size={14} />
                                </Button>
                                <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openEditDialog(sponsorship)}>
                                  <PencilSimple size={14} />
                                </Button>
                                <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs text-rose-700" onClick={() => deleteSponsorship(sponsorship.id)}>
                                  <Trash size={14} />
                                </Button>
                                {canTransition && (
                                  <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => closeSponsorship(sponsorship.id)}>
                                    Fechar
                                  </Button>
                                )}
                                {canTransition && (
                                  <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs text-rose-700" onClick={() => cancelSponsorship(sponsorship.id)}>
                                    Cancelar
                                  </Button>
                                )}
                                <Button type="button" variant="outline" size="sm" className="h-8 px-2 text-xs" onClick={() => openIntegrationsTab(sponsorship.id)}>
                                  Integrações
                                </Button>
                              </div>
                            </TableCell>
                          </TableRow>
                        );
                      })}

                      {sponsorships.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                            Nenhum patrocínio encontrado para os filtros selecionados.
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="integracoes" className={`${moduleTabbedContentClass} space-y-3`}>
            {selectedIntegrationSponsorship && (
              <Card className="gap-0 py-0">
                <CardContent className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                  <div>
                    <p className="text-xs text-muted-foreground">Patrocínio em foco</p>
                    <h3 className="text-sm font-semibold">{selectedIntegrationSponsorship.codigo} · {selectedIntegrationSponsorship.sponsor?.nome || selectedIntegrationSponsorship.sponsor_name}</h3>
                    <p className="text-xs text-muted-foreground">{selectedIntegrationSponsorship.title}</p>
                  </div>

                  <div className="flex gap-2">
                    <Button type="button" variant="outline" className="h-8 text-xs" onClick={() => setSelectedIntegrationSponsorId(null)}>
                      Ver todos
                    </Button>
                    <Button type="button" className="h-8 gap-1.5 text-xs" onClick={() => retryIntegrations(selectedIntegrationSponsorship.id)}>
                      <ArrowsClockwise size={14} />
                      Reprocessar integrações
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}

            <Card className="gap-0 py-0">
              <CardContent className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-3 lg:items-end">
                <div>
                  <Label className="text-xs">Pesquisa</Label>
                  <Input
                    className="mt-1 h-9 bg-white"
                    value={integrationFilters.search}
                    onChange={(event) => setIntegrationFilters((current) => ({ ...current, search: event.target.value }))}
                    placeholder="Código, patrocinador, destino ou erro"
                  />
                </div>

                <div>
                  <Label className="text-xs">Estado</Label>
                  <Select value={integrationFilters.status} onValueChange={(value) => setIntegrationFilters((current) => ({ ...current, status: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="pending">Pendente</SelectItem>
                      <SelectItem value="generated">Gerada</SelectItem>
                      <SelectItem value="failed">Falhada</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label className="text-xs">Tipo</Label>
                  <Select value={integrationFilters.integration_type} onValueChange={(value) => setIntegrationFilters((current) => ({ ...current, integration_type: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="financial">Financeiro</SelectItem>
                      <SelectItem value="stock">Stock</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label className="text-xs">Destino</Label>
                  <Select value={integrationFilters.target_module} onValueChange={(value) => setIntegrationFilters((current) => ({ ...current, target_module: value }))}>
                    <SelectTrigger className="mt-1 h-9 bg-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="financeiro">Financeiro</SelectItem>
                      <SelectItem value="logistica">Logística</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="flex justify-end">
                  <Button
                    type="button"
                    variant="outline"
                    className="h-9"
                    onClick={() => {
                      setSelectedIntegrationSponsorId(null);
                      setIntegrationFilters({ search: '', status: 'all', integration_type: 'all', target_module: 'all' });
                    }}
                  >
                    Limpar
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card className="gap-0 py-0">
              <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-sm">Histórico de integrações</CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <div className="min-w-0">
                  <Table responsive>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Data</TableHead>
                        <TableHead>Patrocínio</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Destino</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Registo destino</TableHead>
                        <TableHead>Observações / Erro</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {filteredIntegrations.map((integration) => (
                        <TableRow key={integration.id} className="text-xs">
                          <TableCell label="Data">
                            <div>{formatDate(integration.executed_at || integration.created_at)}</div>
                            <div className="text-muted-foreground">{integration.source_type}</div>
                          </TableCell>
                          <TableCell label="Patrocínio">
                            <div className="font-medium">{integration.sponsorship?.codigo || '-'}</div>
                            <div className="text-muted-foreground">{integration.sponsorship?.sponsor_name || '-'}</div>
                          </TableCell>
                          <TableCell label="Tipo">{integrationTypeLabels[integration.integration_type]}</TableCell>
                          <TableCell label="Destino">{integrationDestination(integration)}</TableCell>
                          <TableCell label="Estado">
                            <Badge variant="outline" className={integrationBadgeClasses[integration.status]}>
                              {integrationStatusLabels[integration.status]}
                            </Badge>
                          </TableCell>
                          <TableCell label="Registo destino">{integration.target_record_id || '-'}</TableCell>
                          <TableCell label="Observações / Erro" className="whitespace-normal text-muted-foreground">{integration.message || '-'}</TableCell>
                        </TableRow>
                      ))}

                      {filteredIntegrations.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                            Sem registos de integração para os filtros selecionados.
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>

      <Dialog open={dialogOpen} onOpenChange={closeDialog}>
        <DialogContent className="!top-0 !left-0 !translate-x-0 !translate-y-0 !h-[100dvh] !w-screen !max-w-none !gap-0 !rounded-none !border-0 !p-0 overflow-hidden sm:!top-[50%] sm:!left-[50%] sm:!h-auto sm:!max-h-[96vh] sm:!w-[min(98vw,1400px)] sm:!translate-x-[-50%] sm:!translate-y-[-50%] sm:!rounded-lg sm:!border">
          <DialogHeader className="border-b px-3 py-3 sm:px-5 sm:py-4 lg:px-6">
            <DialogTitle>
              {dialogMode === 'create' && 'Novo patrocínio'}
              {dialogMode === 'edit' && 'Editar patrocínio'}
              {dialogMode === 'view' && 'Consultar patrocínio'}
            </DialogTitle>
          </DialogHeader>

          <div className="max-h-[calc(100dvh-61px)] overflow-y-auto px-0 py-0 sm:max-h-[calc(96vh-73px)]">
            <div className="space-y-5">
            <Card className="gap-0 overflow-hidden rounded-none border-x-0 border-t-0 border-b border-slate-200 py-0 sm:mx-3 sm:mt-3 sm:rounded-xl sm:border">
              <CardHeader className="border-b bg-slate-50/80 px-3 py-3 sm:px-4">
                <CardTitle className="text-sm">Dados do contrato</CardTitle>
              </CardHeader>
              <CardContent className="p-3 sm:p-4 lg:p-5">
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-12">
              <div className="min-w-0 lg:col-span-2 2xl:col-span-5">
                <Label className="text-xs">Entidade patrocinadora</Label>
                <Select value={formData.sponsor_id || 'none'} onValueChange={(value) => setField('sponsor_id', value === 'none' ? '' : value)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue placeholder="Selecionar patrocinador" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Selecionar patrocinador</SelectItem>
                    {lookups.sponsors.map((sponsor) => (
                      <SelectItem key={sponsor.id} value={sponsor.id}>{sponsor.nome}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {formErrors.sponsor_id && <p className="mt-1 text-xs text-rose-600">{formErrors.sponsor_id}</p>}
                {!isReadOnly && formData.sponsor_id && (() => {
                  const selectedSponsor = lookups.sponsors.find((sponsor) => sponsor.id === formData.sponsor_id);

                  if (!selectedSponsor) {
                    return null;
                  }

                  return (
                    <p className="mt-1 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-muted-foreground">
                      {selectedSponsor.tipo} · {selectedSponsor.estado}
                      {selectedSponsor.contacto ? ` · ${selectedSponsor.contacto}` : ''}
                      {selectedSponsor.email ? ` · ${selectedSponsor.email}` : ''}
                    </p>
                  );
                })()}
              </div>

              <div className="min-w-0 2xl:col-span-4">
                <Label className="text-xs">Fornecedor associado</Label>
                <Select value={formData.supplier_id || 'none'} onValueChange={(value) => setField('supplier_id', value === 'none' ? '' : value)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sem fornecedor associado</SelectItem>
                    {lookups.suppliers.map((supplier) => (
                      <SelectItem key={supplier.id} value={supplier.id}>{supplier.nome}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Tipo</Label>
                <Select value={formData.type} onValueChange={(value) => setField('type', value as SponsorshipType)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="money">Dinheiro</SelectItem>
                    <SelectItem value="goods">Géneros</SelectItem>
                    <SelectItem value="mixed">Misto</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Estado</Label>
                <Select value={formData.status} onValueChange={(value) => setField('status', value as SponsorshipStatus)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pendente">Pendente</SelectItem>
                    <SelectItem value="ativo">Ativo</SelectItem>
                    <SelectItem value="fechado">Fechado</SelectItem>
                    <SelectItem value="cancelado">Cancelado</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-0 lg:col-span-2 2xl:col-span-6">
                <Label className="text-xs">Título / contrato</Label>
                <Input
                  className="mt-1 h-9 w-full bg-white"
                  value={formData.title}
                  onChange={(event) => setField('title', event.target.value)}
                  disabled={isReadOnly}
                />
                {formErrors.title && <p className="mt-1 text-xs text-rose-600">{formErrors.title}</p>}
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Periodicidade</Label>
                <Select value={formData.periodicity} onValueChange={(value) => setField('periodicity', value as Periodicity)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="pontual">Pontual</SelectItem>
                    <SelectItem value="mensal">Mensal</SelectItem>
                    <SelectItem value="trimestral">Trimestral</SelectItem>
                    <SelectItem value="anual">Anual</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Centro de custo</Label>
                <Select value={formData.cost_center_id || 'none'} onValueChange={(value) => setField('cost_center_id', value === 'none' ? '' : value)} disabled={isReadOnly}>
                  <SelectTrigger className="mt-1 h-9 w-full bg-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sem centro de custo</SelectItem>
                    {lookups.costCenters.map((costCenter) => (
                      <SelectItem key={costCenter.id} value={costCenter.id}>{costCenter.codigo} · {costCenter.nome}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Data de início</Label>
                <Input
                  className="mt-1 h-9 w-full bg-white"
                  type="date"
                  value={formData.start_date}
                  onChange={(event) => setField('start_date', event.target.value)}
                  disabled={isReadOnly}
                />
              </div>

              <div className="min-w-0 2xl:col-span-3">
                <Label className="text-xs">Data de fim</Label>
                <Input
                  className="mt-1 h-9 w-full bg-white"
                  type="date"
                  value={formData.end_date}
                  onChange={(event) => setField('end_date', event.target.value)}
                  disabled={isReadOnly}
                />
              </div>

              <div className="min-w-0 lg:col-span-2 2xl:col-span-6">
                <Label className="text-xs">Descrição</Label>
                <Textarea
                  className="mt-1 min-h-[96px] w-full bg-white"
                  value={formData.description}
                  onChange={(event) => setField('description', event.target.value)}
                  disabled={isReadOnly}
                />
              </div>

              <div className="min-w-0 lg:col-span-2 2xl:col-span-6">
                <Label className="text-xs">Notas</Label>
                <Textarea
                  className="mt-1 min-h-[96px] w-full bg-white"
                  value={formData.notes}
                  onChange={(event) => setField('notes', event.target.value)}
                  disabled={isReadOnly}
                />
              </div>
            </div>
              </CardContent>
            </Card>

            {(formData.type === 'money' || formData.type === 'mixed') && (
              <Card className="gap-0 overflow-hidden rounded-none border-x-0 border-y border-slate-200 py-0 sm:mx-3 sm:rounded-xl sm:border">
                <CardHeader className="flex flex-col gap-2 border-b px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                  <CardTitle className="text-sm">Componente monetária</CardTitle>
                  {!isReadOnly && (
                    <Button type="button" variant="outline" className="h-8 text-xs" onClick={addMoneyItem}>
                      <Plus size={14} className="mr-1" />
                      Adicionar linha
                    </Button>
                  )}
                </CardHeader>
                <CardContent className="space-y-3 p-3 sm:p-4 lg:p-5">
                  {formData.money_items.map((item, index) => {
                    const locked = isLockedMoneyItem(item);

                    return (
                      <div key={item.id || `money-${index}`} className="rounded-md border p-3">
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-[minmax(0,1.5fr)_170px_170px_auto] 2xl:items-end">
                          <div className="min-w-0 lg:col-span-2 2xl:col-span-1">
                            <Label className="text-xs">Descrição</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              value={item.description}
                              onChange={(event) => updateMoneyItem(index, 'description', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Montante</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              type="number"
                              min="0"
                              step="0.01"
                              value={item.amount}
                              onChange={(event) => updateMoneyItem(index, 'amount', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Data prevista</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              type="date"
                              value={formatDateInput(item.expected_date)}
                              onChange={(event) => updateMoneyItem(index, 'expected_date', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="flex flex-wrap gap-2 lg:col-span-2 2xl:col-span-1 2xl:justify-end">
                            {item.integration_status && (
                              <Badge variant="outline" className={integrationBadgeClasses[item.integration_status]}>
                                {integrationStatusLabels[item.integration_status]}
                              </Badge>
                            )}
                            {!isReadOnly && (
                              <Button type="button" variant="outline" className="h-9 text-xs" disabled={locked} onClick={() => removeMoneyItem(index)}>
                                Remover
                              </Button>
                            )}
                          </div>
                        </div>

                        {item.integration_message && <p className="mt-2 text-xs text-muted-foreground">{item.integration_message}</p>}
                      </div>
                    );
                  })}

                  {formErrors.money_items && <p className="text-xs text-rose-600">{formErrors.money_items}</p>}
                </CardContent>
              </Card>
            )}

            {(formData.type === 'goods' || formData.type === 'mixed') && (
              <Card className="gap-0 overflow-hidden rounded-none border-x-0 border-y border-slate-200 py-0 sm:mx-3 sm:rounded-xl sm:border">
                <CardHeader className="flex flex-col gap-2 border-b px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                  <CardTitle className="text-sm">Componente em géneros</CardTitle>
                  {!isReadOnly && (
                    <Button type="button" variant="outline" className="h-8 text-xs" onClick={addGoodsItem}>
                      <Plus size={14} className="mr-1" />
                      Adicionar artigo
                    </Button>
                  )}
                </CardHeader>
                <CardContent className="space-y-3 p-3 sm:p-4 lg:p-5">
                  {formData.goods_items.map((item, index) => {
                    const locked = isLockedGoodsItem(item);
                    const selectedProduct = lookups.products.find((product) => product.id === item.item_id);

                    return (
                      <div key={item.id || `goods-${index}`} className="rounded-md border p-3">
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3 2xl:items-end">
                          <div className="min-w-0 lg:col-span-2 2xl:col-span-1">
                            <Label className="text-xs">Artigo existente</Label>
                            <Select value={item.item_id || 'none'} onValueChange={(value) => changeGoodsProduct(index, value === 'none' ? '' : value)} disabled={isReadOnly || locked}>
                              <SelectTrigger className="mt-1 h-9 w-full bg-white">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="none">Sem associação</SelectItem>
                                {lookups.products.map((product) => (
                                  <SelectItem key={product.id} value={product.id}>{product.codigo} · {product.nome}</SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>

                          <div className="min-w-0 lg:col-span-2 2xl:col-span-1">
                            <Label className="text-xs">Nome do artigo</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              value={item.item_name}
                              onChange={(event) => updateGoodsItem(index, 'item_name', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Categoria</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              value={item.category || ''}
                              onChange={(event) => updateGoodsItem(index, 'category', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Quantidade</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              type="number"
                              min="0"
                              step="1"
                              value={item.quantity}
                              onChange={(event) => updateGoodsItem(index, 'quantity', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Valor unitário estimado</Label>
                            <Input
                              className="mt-1 h-9 w-full bg-white"
                              type="number"
                              min="0"
                              step="0.01"
                              value={item.unit_value || ''}
                              onChange={(event) => updateGoodsItem(index, 'unit_value', event.target.value)}
                              disabled={isReadOnly || locked}
                            />
                          </div>

                          <div className="min-w-0">
                            <Label className="text-xs">Total estimado</Label>
                            <Input className="mt-1 h-9 w-full bg-muted/40" value={computeGoodsTotal(item)} readOnly />
                          </div>

                          <div className="flex flex-wrap gap-2 lg:col-span-2 2xl:col-span-1 2xl:justify-end">
                            {item.integration_status && (
                              <Badge variant="outline" className={integrationBadgeClasses[item.integration_status]}>
                                {integrationStatusLabels[item.integration_status]}
                              </Badge>
                            )}
                            {!isReadOnly && (
                              <Button type="button" variant="outline" className="h-9 text-xs" disabled={locked} onClick={() => removeGoodsItem(index)}>
                                Remover
                              </Button>
                            )}
                          </div>
                        </div>

                        {selectedProduct?.area_armazenamento && <p className="mt-2 text-xs text-muted-foreground">Área de armazenamento atual: {selectedProduct.area_armazenamento}</p>}
                        {item.integration_message && <p className="mt-2 text-xs text-muted-foreground">{item.integration_message}</p>}
                      </div>
                    );
                  })}

                  {formErrors.goods_items && <p className="text-xs text-rose-600">{formErrors.goods_items}</p>}
                </CardContent>
              </Card>
            )}

            <div className="flex flex-col-reverse gap-2 border-t px-3 py-3 sm:mx-3 sm:mb-3 sm:rounded-b-xl sm:px-0 sm:pb-0 sm:pt-4 sm:flex-row sm:justify-end sm:border-t-0">
              <Button type="button" variant="outline" className="w-full sm:w-auto" onClick={() => setDialogOpen(false)}>
                {dialogMode === 'view' ? 'Fechar' : 'Cancelar'}
              </Button>
              {!isReadOnly && (
                <Button type="button" className="w-full sm:w-auto" onClick={submitForm} disabled={submitting}>
                  {submitting ? 'A guardar...' : dialogMode === 'edit' ? 'Guardar alterações' : 'Criar patrocínio'}
                </Button>
              )}
            </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </AuthenticatedLayout>
  );
}