import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleScrollableContentClass, moduleViewportClass } from '@/lib/module-layout';
import { Card } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import {
    ChartLine,
    EnvelopeSimple,
    MegaphoneSimple,
    Pencil,
    Plus,
    ShareNetwork,
    Trash,
} from '@phosphor-icons/react';

type CampaignType = 'email' | 'social_media' | 'event' | 'other';
type CampaignStatus = 'planned' | 'active' | 'completed' | 'cancelled';

interface Campaign {
    id: string;
    name: string;
    description?: string | null;
    type: CampaignType;
    start_date: string;
    end_date?: string | null;
    status: CampaignStatus;
    budget?: number | string | null;
    estimated_reach?: number | null;
    notes?: string | null;
}

interface Stats {
    total_campaigns: number;
    active_campaigns: number;
    budget_total: number;
    planned_campaigns: number;
    completed_campaigns: number;
}

interface Props {
    campaigns: {
        data: Campaign[];
    };
    stats: Stats;
    filters?: {
        type?: string;
        status?: string;
        search?: string;
    };
}

interface CampaignFormData {
    name: string;
    description: string;
    type: CampaignType;
    start_date: string;
    end_date: string;
    status: CampaignStatus;
    budget: string;
    estimated_reach: string;
    notes: string;
}

const emptyForm = (): CampaignFormData => ({
    name: '',
    description: '',
    type: 'email',
    start_date: '',
    end_date: '',
    status: 'planned',
    budget: '',
    estimated_reach: '',
    notes: '',
});

const statusLabels: Record<CampaignStatus, string> = {
    planned: 'Planeada',
    active: 'Ativa',
    completed: 'Concluída',
    cancelled: 'Cancelada',
};

export default function MarketingIndex({ campaigns, stats }: Props) {
    const [showDialog, setShowDialog] = useState(false);
    const [editingCampaign, setEditingCampaign] = useState<Campaign | null>(null);
    const [formData, setFormData] = useState<CampaignFormData>(emptyForm());

    const resetForm = () => setFormData(emptyForm());

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setShowDialog(false);
                setEditingCampaign(null);
                resetForm();
            },
        };

        if (editingCampaign) {
            router.put(`/campanhas-marketing/${editingCampaign.id}`, formData, options);
            return;
        }

        router.post('/campanhas-marketing', formData, options);
    };

    const handleEdit = (campaign: Campaign) => {
        setEditingCampaign(campaign);
        setFormData({
            name: campaign.name,
            description: campaign.description ?? '',
            type: campaign.type,
            start_date: campaign.start_date?.slice(0, 10) ?? '',
            end_date: campaign.end_date?.slice(0, 10) ?? '',
            status: campaign.status,
            budget: campaign.budget == null ? '' : String(campaign.budget),
            estimated_reach: campaign.estimated_reach == null ? '' : String(campaign.estimated_reach),
            notes: campaign.notes ?? '',
        });
        setShowDialog(true);
    };

    const handleDelete = (id: string) => {
        if (window.confirm('Tem a certeza que deseja eliminar esta campanha?')) {
            router.delete(`/campanhas-marketing/${id}`, { preserveScroll: true });
        }
    };

    const getTypeIcon = (type: CampaignType) => {
        switch (type) {
            case 'email':
                return <EnvelopeSimple className="text-purple-600" size={18} weight="bold" />;
            case 'social_media':
                return <ShareNetwork className="text-green-600" size={18} weight="bold" />;
            case 'event':
                return <MegaphoneSimple className="text-blue-600" size={18} weight="bold" />;
            default:
                return <ChartLine className="text-orange-600" size={18} weight="bold" />;
        }
    };

    const getTypeBgColor = (type: CampaignType) => {
        switch (type) {
            case 'email':
                return 'bg-purple-50';
            case 'social_media':
                return 'bg-green-50';
            case 'event':
                return 'bg-blue-50';
            default:
                return 'bg-orange-50';
        }
    };

    const getStatusBadge = (status: CampaignStatus) => {
        const colors: Record<CampaignStatus, string> = {
            planned: 'bg-gray-100 text-gray-800',
            active: 'bg-green-100 text-green-800',
            completed: 'bg-blue-100 text-blue-800',
            cancelled: 'bg-red-100 text-red-800',
        };

        return colors[status];
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h1 className="text-lg font-semibold tracking-tight sm:text-xl">Marketing</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Campanhas e ações de divulgação do clube
                    </p>
                </div>
            }
        >
            <Head title="Marketing" />

            <div className={moduleViewportClass}>
                <div className={`${moduleScrollableContentClass} container mx-auto max-w-7xl space-y-2 px-2 py-3 sm:space-y-3 sm:px-4 sm:py-4`}>
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                        <Card className="p-2.5 sm:p-3">
                            <div className="flex flex-col items-center gap-1.5 text-center">
                                <div className="rounded-lg bg-blue-50 p-1.5">
                                    <MegaphoneSimple className="text-blue-600" size={18} weight="bold" />
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold sm:text-sm">Total Campanhas</h3>
                                    <p className="mt-0.5 text-lg font-bold">{stats.total_campaigns}</p>
                                </div>
                            </div>
                        </Card>

                        <Card className="p-2.5 sm:p-3">
                            <div className="flex flex-col items-center gap-1.5 text-center">
                                <div className="rounded-lg bg-green-50 p-1.5">
                                    <ShareNetwork className="text-green-600" size={18} weight="bold" />
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold sm:text-sm">Campanhas Ativas</h3>
                                    <p className="mt-0.5 text-lg font-bold">{stats.active_campaigns}</p>
                                </div>
                            </div>
                        </Card>

                        <Card className="p-2.5 sm:p-3">
                            <div className="flex flex-col items-center gap-1.5 text-center">
                                <div className="rounded-lg bg-purple-50 p-1.5">
                                    <EnvelopeSimple className="text-purple-600" size={18} weight="bold" />
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold sm:text-sm">Orçamento Total</h3>
                                    <p className="mt-0.5 text-lg font-bold">€{Number(stats.budget_total || 0).toFixed(2)}</p>
                                </div>
                            </div>
                        </Card>

                        <Card className="p-2.5 sm:p-3">
                            <div className="flex flex-col items-center gap-1.5 text-center">
                                <div className="rounded-lg bg-orange-50 p-1.5">
                                    <ChartLine className="text-orange-600" size={18} weight="bold" />
                                </div>
                                <div>
                                    <h3 className="text-xs font-semibold sm:text-sm">Concluídas</h3>
                                    <p className="mt-0.5 text-lg font-bold">{stats.completed_campaigns}</p>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-semibold sm:text-lg">Campanhas</h2>
                        <Button
                            size="sm"
                            onClick={() => {
                                setEditingCampaign(null);
                                resetForm();
                                setShowDialog(true);
                            }}
                        >
                            <Plus size={16} className="mr-1" />
                            Nova Campanha
                        </Button>
                    </div>

                    <div className="grid gap-2 sm:gap-3 md:grid-cols-2 lg:grid-cols-3">
                        {campaigns.data?.length ? (
                            campaigns.data.map((campaign) => (
                                <Card key={campaign.id} className="p-3 sm:p-4">
                                    <div className="mb-2 flex items-start justify-between">
                                        <div className={`rounded-lg p-1.5 ${getTypeBgColor(campaign.type)}`}>
                                            {getTypeIcon(campaign.type)}
                                        </div>
                                        <div className="flex gap-1">
                                            <Button variant="ghost" size="sm" onClick={() => handleEdit(campaign)}>
                                                <Pencil size={14} />
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => handleDelete(campaign.id)}>
                                                <Trash size={14} />
                                            </Button>
                                        </div>
                                    </div>

                                    <h3 className="mb-1 text-sm font-semibold">{campaign.name}</h3>
                                    {campaign.description && (
                                        <p className="mb-2 line-clamp-2 text-xs text-muted-foreground">
                                            {campaign.description}
                                        </p>
                                    )}

                                    <div className="flex items-center justify-between text-xs">
                                        <span className={`rounded-full px-2 py-0.5 ${getStatusBadge(campaign.status)}`}>
                                            {statusLabels[campaign.status]}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {new Date(campaign.start_date).toLocaleDateString('pt-PT')}
                                        </span>
                                    </div>

                                    {campaign.budget != null && Number(campaign.budget) > 0 && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Orçamento: €{Number(campaign.budget).toFixed(2)}
                                        </p>
                                    )}
                                </Card>
                            ))
                        ) : (
                            <Card className="col-span-full p-4 sm:p-5">
                                <div className="text-center text-muted-foreground">
                                    <MegaphoneSimple className="mx-auto mb-2" size={36} weight="thin" />
                                    <p className="text-sm font-medium">Nenhuma campanha encontrada</p>
                                    <p className="mt-0.5 text-xs">Crie a primeira campanha de marketing</p>
                                </div>
                            </Card>
                        )}
                    </div>

                    <Dialog open={showDialog} onOpenChange={setShowDialog}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{editingCampaign ? 'Editar Campanha' : 'Nova Campanha'}</DialogTitle>
                            </DialogHeader>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <Label htmlFor="name">Nome da Campanha</Label>
                                    <Input
                                        id="name"
                                        value={formData.name}
                                        onChange={(event) => setFormData({ ...formData, name: event.target.value })}
                                        required
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="description">Descrição</Label>
                                    <Textarea
                                        id="description"
                                        value={formData.description}
                                        onChange={(event) => setFormData({ ...formData, description: event.target.value })}
                                        rows={3}
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="type">Tipo</Label>
                                        <select
                                            id="type"
                                            value={formData.type}
                                            onChange={(event) => setFormData({ ...formData, type: event.target.value as CampaignType })}
                                            className="w-full rounded-md border px-3 py-2"
                                            required
                                        >
                                            <option value="email">Email</option>
                                            <option value="social_media">Redes Sociais</option>
                                            <option value="event">Evento</option>
                                            <option value="other">Outro</option>
                                        </select>
                                    </div>

                                    <div>
                                        <Label htmlFor="status">Estado</Label>
                                        <select
                                            id="status"
                                            value={formData.status}
                                            onChange={(event) => setFormData({ ...formData, status: event.target.value as CampaignStatus })}
                                            className="w-full rounded-md border px-3 py-2"
                                            required
                                        >
                                            <option value="planned">Planeada</option>
                                            <option value="active">Ativa</option>
                                            <option value="completed">Concluída</option>
                                            <option value="cancelled">Cancelada</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="start_date">Data Início</Label>
                                        <Input
                                            id="start_date"
                                            type="date"
                                            value={formData.start_date}
                                            onChange={(event) => setFormData({ ...formData, start_date: event.target.value })}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="end_date">Data Fim</Label>
                                        <Input
                                            id="end_date"
                                            type="date"
                                            value={formData.end_date}
                                            onChange={(event) => setFormData({ ...formData, end_date: event.target.value })}
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="budget">Orçamento (€)</Label>
                                        <Input
                                            id="budget"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={formData.budget}
                                            onChange={(event) => setFormData({ ...formData, budget: event.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="estimated_reach">Alcance Estimado</Label>
                                        <Input
                                            id="estimated_reach"
                                            type="number"
                                            min="0"
                                            value={formData.estimated_reach}
                                            onChange={(event) => setFormData({ ...formData, estimated_reach: event.target.value })}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="notes">Notas</Label>
                                    <Textarea
                                        id="notes"
                                        value={formData.notes}
                                        onChange={(event) => setFormData({ ...formData, notes: event.target.value })}
                                        rows={2}
                                    />
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setShowDialog(false)}>
                                        Cancelar
                                    </Button>
                                    <Button type="submit">{editingCampaign ? 'Atualizar' : 'Criar'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
