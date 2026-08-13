import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, GearSix, PencilSimple, Plus, Trash } from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';

interface ConfigRow {
    id: string;
    codigo: string;
    nome: string;
    ativo: boolean;
    archived?: boolean;
    used?: boolean;
    code_locked?: boolean;
    [key: string]: unknown;
}

interface LegacyInjuryReason {
    id: string;
    codigo: string;
    nome: string;
    descricao?: string | null;
    gravidade: string;
    ativo: boolean;
}

interface Props {
    athlete_statuses?: ConfigRow[];
    training_types?: ConfigRow[];
    training_zones?: ConfigRow[];
    absence_reasons?: ConfigRow[];
    pool_types?: ConfigRow[];
    race_types?: ConfigRow[];
    limitation_types?: ConfigRow[];
    cais_metrics?: ConfigRow[];
    legacy_injury_reasons?: LegacyInjuryReason[];
}

type FieldType = 'text' | 'number' | 'boolean' | 'textarea' | 'color';

interface FieldDefinition {
    key: string;
    label: string;
    type: FieldType;
    help?: string;
}

interface CatalogDefinition {
    key: string;
    label: string;
    description: string;
    fields: FieldDefinition[];
}

const BASE_FIELDS: FieldDefinition[] = [
    { key: 'codigo', label: 'Código técnico', type: 'text', help: 'Fica bloqueado depois de existir histórico associado.' },
    { key: 'nome', label: 'Nome', type: 'text' },
    { key: 'ordem', label: 'Ordem', type: 'number' },
    { key: 'ativo', label: 'Ativo', type: 'boolean' },
];

const CATALOGS: CatalogDefinition[] = [
    {
        key: 'athlete_statuses',
        label: 'Estados / Presença',
        description: 'Estados operacionais de participação. O comportamento é configurado por propriedades, não pelo texto do código.',
        fields: [
            ...BASE_FIELDS,
            { key: 'descricao', label: 'Descrição', type: 'textarea' },
            { key: 'cor', label: 'Cor', type: 'color' },
            { key: 'counts_as_present', label: 'Conta como presente', type: 'boolean' },
            { key: 'requires_reason', label: 'Exige motivo/justificação', type: 'boolean' },
            { key: 'allows_training', label: 'Permite treino', type: 'boolean' },
            { key: 'allows_competition', label: 'Permite competição', type: 'boolean' },
        ],
    },
    {
        key: 'training_types',
        label: 'Tipos de treino',
        description: 'Classificação técnica dos treinos. Recovery e alta intensidade passam a ser propriedades explícitas.',
        fields: [
            ...BASE_FIELDS,
            { key: 'descricao', label: 'Descrição', type: 'textarea' },
            { key: 'cor', label: 'Cor', type: 'color' },
            { key: 'is_recovery', label: 'Recuperação / descarga', type: 'boolean' },
            { key: 'is_high_intensity', label: 'Alta intensidade', type: 'boolean' },
        ],
    },
    {
        key: 'training_zones',
        label: 'Intensidades / Zonas',
        description: 'Zonas de intensidade com limites opcionais e significado explícito. Os limites não definem sozinhos o comportamento.',
        fields: [
            ...BASE_FIELDS,
            { key: 'descricao', label: 'Descrição', type: 'textarea' },
            { key: 'percentagem_min', label: 'Percentagem mínima', type: 'number' },
            { key: 'percentagem_max', label: 'Percentagem máxima', type: 'number' },
            { key: 'cor', label: 'Cor', type: 'color' },
            { key: 'is_recovery', label: 'Zona de recuperação', type: 'boolean' },
            { key: 'is_high_intensity', label: 'Zona de alta intensidade', type: 'boolean' },
        ],
    },
    {
        key: 'absence_reasons',
        label: 'Motivos de ausência',
        description: 'Motivos usados no registo operacional de ausências, com requisitos de justificação e contexto de saúde configuráveis.',
        fields: [
            ...BASE_FIELDS,
            { key: 'descricao', label: 'Descrição', type: 'textarea' },
            { key: 'requer_justificacao', label: 'Exige justificação', type: 'boolean' },
            { key: 'health_related', label: 'Relacionado com saúde', type: 'boolean' },
        ],
    },
    {
        key: 'pool_types',
        label: 'Tipos de piscina / meio',
        description: 'Tipologia técnica do meio aquático. Os locais e piscinas concretos pertencem à Estrutura Desportiva.',
        fields: [
            ...BASE_FIELDS,
            { key: 'comprimento_m', label: 'Comprimento (m)', type: 'number' },
            { key: 'is_open_water', label: 'Águas abertas', type: 'boolean' },
        ],
    },
    {
        key: 'race_types',
        label: 'Tipos de prova',
        description: 'Catálogo técnico atualmente existente. Será relacionado com modalidades canónicas na F2 sem perder os dados atuais.',
        fields: [
            ...BASE_FIELDS,
            { key: 'distancia', label: 'Distância', type: 'number' },
            { key: 'unidade', label: 'Unidade (m/km)', type: 'text' },
            { key: 'modalidade', label: 'Modalidade atual', type: 'text' },
        ],
    },
    {
        key: 'limitation_types',
        label: 'Limitações operacionais',
        description: 'O treinador vê instruções operacionais necessárias à prática segura, sem transformar diagnósticos clínicos em estados técnicos.',
        fields: [
            ...BASE_FIELDS,
            { key: 'descricao', label: 'Descrição', type: 'textarea' },
            { key: 'instrucao_padrao', label: 'Instrução operacional padrão', type: 'textarea' },
            { key: 'allows_training', label: 'Permite treino', type: 'boolean' },
            { key: 'allows_competition', label: 'Permite competição', type: 'boolean' },
            { key: 'requires_end_date', label: 'Exige data de fim', type: 'boolean' },
        ],
    },
    {
        key: 'cais_metrics',
        label: 'Métricas do Cais',
        description: 'Campos operacionais disponíveis no + Registo. Comportamento e Material podem ser marcados como ações rápidas e usam exatamente o mesmo estado do registo completo.',
        fields: [
            ...BASE_FIELDS,
            { key: 'input_type', label: 'Tipo (text / number / choice)', type: 'text', help: 'Use text, number ou choice.' },
            { key: 'unit', label: 'Unidade', type: 'text', help: 'Ex.: bpm. Pode ficar vazio.' },
            { key: 'options', label: 'Opções', type: 'textarea', help: 'Para choice, indique as opções separadas por vírgula ou linha.' },
            { key: 'quick_action', label: 'Ação rápida no Cais', type: 'boolean' },
        ],
    },
];

const emptyValueFor = (field: FieldDefinition): unknown => {
    if (field.type === 'boolean') return field.key === 'ativo' || field.key.startsWith('allows_');
    if (field.type === 'number') return '';
    if (field.type === 'color') return '#6B7280';
    return '';
};

function ConfigurationDialog({ catalog, row, open, onOpenChange }: { catalog: CatalogDefinition; row: ConfigRow | null; open: boolean; onOpenChange: (open: boolean) => void }) {
    const initial = useMemo(() => Object.fromEntries(catalog.fields.map((field) => [field.key, row?.[field.key] ?? emptyValueFor(field)])), [catalog, row]);
    const [data, setData] = useState<Record<string, unknown>>(initial);

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (row) {
            router.put(route('desportivo.configuracao.update', { catalog: catalog.key, id: row.id }), data, options);
            return;
        }
        router.post(route('desportivo.configuracao.store', { catalog: catalog.key }), data, options);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>{row ? 'Editar' : 'Nova configuração'} — {catalog.label}</DialogTitle></DialogHeader>
                <div className="grid gap-4 py-2 sm:grid-cols-2">
                    {catalog.fields.map((field) => {
                        const value = data[field.key];
                        const codeLocked = field.key === 'codigo' && Boolean(row?.code_locked);
                        if (field.type === 'boolean') {
                            return (
                                <div key={field.key} className="flex items-center justify-between gap-4 rounded-lg border p-3 sm:col-span-2">
                                    <div><Label>{field.label}</Label>{field.help && <p className="mt-1 text-xs text-muted-foreground">{field.help}</p>}</div>
                                    <Switch checked={Boolean(value)} onCheckedChange={(checked) => setData((current) => ({ ...current, [field.key]: checked }))} />
                                </div>
                            );
                        }
                        return (
                            <div key={field.key} className={field.type === 'textarea' ? 'sm:col-span-2' : ''}>
                                <Label htmlFor={`${catalog.key}-${field.key}`}>{field.label}</Label>
                                {field.type === 'textarea' ? (
                                    <Textarea id={`${catalog.key}-${field.key}`} value={String(value ?? '')} onChange={(event) => setData((current) => ({ ...current, [field.key]: event.target.value }))} />
                                ) : (
                                    <Input id={`${catalog.key}-${field.key}`} type={field.type === 'number' ? 'number' : field.type === 'color' ? 'color' : 'text'} value={String(value ?? '')} disabled={codeLocked} onChange={(event) => setData((current) => ({ ...current, [field.key]: field.type === 'number' && event.target.value !== '' ? Number(event.target.value) : event.target.value }))} />
                                )}
                                {field.help && <p className="mt-1 text-xs text-muted-foreground">{field.help}</p>}
                                {codeLocked && <p className="mt-1 text-xs text-muted-foreground">Código bloqueado porque já existe histórico associado.</p>}
                            </div>
                        );
                    })}
                </div>
                <DialogFooter><Button variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button><Button onClick={submit}>Guardar</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CatalogPanel({ catalog, rows }: { catalog: CatalogDefinition; rows: ConfigRow[] }) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<ConfigRow | null>(null);
    const openCreate = () => { setEditing(null); setDialogOpen(true); };
    const openEdit = (row: ConfigRow) => { setEditing(row); setDialogOpen(true); };
    const remove = (row: ConfigRow) => {
        const action = row.used ? 'arquivar' : 'eliminar definitivamente';
        if (!window.confirm(`Pretende ${action} “${row.nome}”?`)) return;
        router.delete(route('desportivo.configuracao.destroy', { catalog: catalog.key, id: row.id }), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div><CardTitle className="text-base">{catalog.label}</CardTitle><CardDescription className="mt-1 max-w-3xl">{catalog.description}</CardDescription></div>
                <Button size="sm" className="gap-2" onClick={openCreate}><Plus size={15} /> Novo</Button>
            </CardHeader>
            <CardContent>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th className="px-3 py-2 font-medium">Código</th><th className="px-3 py-2 font-medium">Nome</th><th className="px-3 py-2 font-medium">Estado</th><th className="px-3 py-2 font-medium">Histórico</th><th className="px-3 py-2 text-right font-medium">Ações</th></tr></thead>
                        <tbody>
                            {rows.length === 0 ? <tr><td colSpan={5} className="px-3 py-8 text-center text-muted-foreground">Sem registos.</td></tr> : rows.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-3 py-2 font-mono text-xs">{row.codigo}</td><td className="px-3 py-2 font-medium">{row.nome}</td>
                                    <td className="px-3 py-2"><Badge variant={row.archived || !row.ativo ? 'secondary' : 'default'}>{row.archived ? 'Arquivado' : row.ativo ? 'Ativo' : 'Inativo'}</Badge></td>
                                    <td className="px-3 py-2 text-muted-foreground">{row.used ? 'Em uso' : 'Sem referências'}</td>
                                    <td className="px-3 py-2"><div className="flex justify-end gap-1"><Button variant="ghost" size="sm" onClick={() => openEdit(row)} aria-label={`Editar ${row.nome}`}><PencilSimple size={15} /></Button><Button variant="ghost" size="sm" onClick={() => remove(row)} aria-label={`${row.used ? 'Arquivar' : 'Eliminar'} ${row.nome}`}><Trash size={15} /></Button></div></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CardContent>
            {dialogOpen && <ConfigurationDialog key={`${catalog.key}-${editing?.id ?? 'new'}`} catalog={catalog} row={editing} open={dialogOpen} onOpenChange={setDialogOpen} />}
        </Card>
    );
}

export default function SportsConfigurationIndex({
    athlete_statuses = [], training_types = [], training_zones = [], absence_reasons = [], pool_types = [], race_types = [], limitation_types = [], cais_metrics = [], legacy_injury_reasons = [],
}: Props) {
    const data: Record<string, ConfigRow[]> = { athlete_statuses, training_types, training_zones, absence_reasons, pool_types, race_types, limitation_types, cais_metrics };

    return (
        <AuthenticatedLayout fullWidth header={
            <div className="flex w-full items-center justify-between gap-4">
                <div><h1 className="flex items-center gap-2 text-lg font-semibold tracking-tight sm:text-xl"><GearSix size={20} /> Configuração Desportiva</h1><p className="mt-0.5 text-xs text-muted-foreground">Catálogos técnicos e regras operacionais do módulo Desportivo</p></div>
                <Button variant="outline" size="sm" className="gap-2" onClick={() => router.get(route('desportivo.index'))}><ArrowLeft size={15} /> Desportivo</Button>
            </div>
        }>
            <Head title="Configuração Desportiva" />
            <div className="mx-auto w-full max-w-[1600px] space-y-4 p-3 sm:p-4">
                <Card className="border-blue-200 bg-blue-50/50"><CardContent className="py-3 text-sm text-muted-foreground">Os códigos técnicos podem ser alterados enquanto não tiverem histórico. Depois de usados ficam imutáveis; ao remover uma definição usada, o sistema arquiva-a em vez de destruir referências antigas.</CardContent></Card>
                <Tabs defaultValue="athlete_statuses" className="space-y-4">
                    <TabsList className="h-auto w-full justify-start gap-1 overflow-x-auto p-1">{CATALOGS.map((catalog) => <TabsTrigger key={catalog.key} value={catalog.key} className="whitespace-nowrap text-xs">{catalog.label}</TabsTrigger>)}</TabsList>
                    {CATALOGS.map((catalog) => (
                        <TabsContent key={catalog.key} value={catalog.key}>
                            <CatalogPanel catalog={catalog} rows={data[catalog.key] ?? []} />
                            {catalog.key === 'limitation_types' && legacy_injury_reasons.length > 0 && (
                                <Card className="mt-4"><CardHeader><CardTitle className="text-sm">Catálogo clínico legacy — apenas leitura</CardTitle><CardDescription>Estes motivos de lesão são preservados para compatibilidade histórica. Não são apresentados ao treinador como limitações operacionais.</CardDescription></CardHeader><CardContent className="space-y-2">{legacy_injury_reasons.map((item) => <div key={item.id} className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"><div><span className="font-medium">{item.nome}</span><span className="ml-2 font-mono text-xs text-muted-foreground">{item.codigo}</span></div><Badge variant="secondary">{item.gravidade}</Badge></div>)}</CardContent></Card>
                            )}
                        </TabsContent>
                    ))}
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}
