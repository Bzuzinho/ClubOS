import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

type Id = string;
type Series = { repeticoes: number; distancia_m: number; exercicio: string; intervalo: string; saida: string; timing_mode: 'none' | 'each_rep' | 'whole_series'; observacoes: string };
type Block = { nome: string; rondas: number; notas: string; series: Series[] };
type Plan = { id: Id; nome: string; descricao?: string | null; estado: string; archived: boolean; current_version?: { id: Id; version: number; volume_planeado_m: number; blocks: Array<{ name?: string; nome?: string; rounds?: number; rondas?: number; series: Series[] }> } | null };
type Props = { libraryPlans: Plan[] };
const newLine = (): Series => ({ repeticoes: 1, distancia_m: 100, exercicio: '', intervalo: '', saida: '', timing_mode: 'none', observacoes: '' });
const newBlock = (): Block => ({ nome: 'Série principal', rondas: 1, notas: '', series: [newLine()] });

export default function TrainingLibrary({ libraryPlans = [] }: Props) {
    const [editing, setEditing] = useState(false);
    const [plan, setPlan] = useState<Plan | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [blocks, setBlocks] = useState<Block[]>([newBlock()]);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const open = (selected: Plan | null = null) => {
        setPlan(selected);
        setName(selected?.nome ?? '');
        setDescription(selected?.descricao ?? '');
        setBlocks(selected?.current_version?.blocks?.length
            ? selected.current_version.blocks.map(b => ({
                nome: b.nome ?? b.name ?? 'Série',
                rondas: b.rondas ?? b.rounds ?? 1,
                notas: '',
                series: b.series.map(s => ({
                    repeticoes: s.repeticoes ?? 1, distancia_m: s.distancia_m ?? 100,
                    exercicio: s.exercicio ?? '', intervalo: s.intervalo ?? '',
                    saida: s.saida ?? '', timing_mode: s.timing_mode ?? 'none',
                    observacoes: s.observacoes ?? '',
                })),
            })) : [newBlock()]);
        setErrors({});
        setEditing(true);
    };
    const setBlock = (index: number, changes: Partial<Block>) =>
        setBlocks(old => old.map((b, i) => i === index ? { ...b, ...changes } : b));
    const setLine = (blockIndex: number, lineIndex: number, changes: Partial<Series>) =>
        setBlocks(old => old.map((b, i) => i === blockIndex ? {
            ...b, series: b.series.map((s, j) => j === lineIndex ? { ...s, ...changes } : s),
        } : b));
    const submit = () => {
        if (!name.trim()) { setErrors({ nome: 'Indica o nome do treino.' }); return; }
        setSaving(true);
        const url = plan ? route('desportivo.biblioteca.planos.revise', plan.id) : route('desportivo.biblioteca.planos.store');
        router.visit(url, {
            method: plan ? 'put' : 'post',
            data: { nome: name, descricao: description, blocks },
            preserveScroll: true,
            onError: e => setErrors(e),
            onSuccess: () => setEditing(false),
            onFinish: () => setSaving(false),
        });
    };
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight">Biblioteca de treinos</h2>}>
            <Head title="Biblioteca de treinos" />
            <div className="space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div><h1 className="text-xl font-semibold">Biblioteca</h1><p className="text-sm text-muted-foreground">Modelos reutilizáveis para o planeamento das sessões.</p></div>
                    <Button onClick={() => open()}>Novo treino</Button>
                </div>
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {libraryPlans.filter(p => !p.archived).map(item => (
                        <Card key={item.id}>
                            <CardHeader><CardTitle className="text-base">{item.nome}</CardTitle></CardHeader>
                            <CardContent className="space-y-3">
                                <p className="text-sm text-muted-foreground">{item.descricao || 'Sem descrição'}</p>
                                <p className="text-sm">{item.current_version?.volume_planeado_m ?? 0} m · Versão {item.current_version?.version ?? '—'} · {item.estado}</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => open(item)}>Editar / nova versão</Button>
                                    {item.current_version?.id && <Button size="sm" onClick={() => router.visit(route('desportivo.planeamento', { training_plan_version_id: item.current_version!.id }))}>Planear sessão</Button>}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                {!libraryPlans.some(p => !p.archived) && <p className="text-sm text-muted-foreground">Ainda não existem treinos na Biblioteca. Cria o primeiro modelo.</p>}
            </div>
            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="max-h-[90vh] max-w-4xl overflow-y-auto">
                    <DialogHeader><DialogTitle>{plan ? 'Nova versão do treino' : 'Novo treino da Biblioteca'}</DialogTitle></DialogHeader>
                    <div className="space-y-4">
                        <div><Label>Nome do treino</Label><Input value={name} onChange={e => setName(e.target.value)} />{errors.nome && <p className="text-sm text-destructive">{errors.nome}</p>}</div>
                        <div><Label>Descrição</Label><Input value={description} onChange={e => setDescription(e.target.value)} /></div>
                        {blocks.map((block, bi) => (
                            <Card key={bi}><CardContent className="space-y-3 p-4">
                                <div className="flex flex-wrap gap-2">
                                    <div className="min-w-0 flex-1"><Label>Bloco</Label><Input value={block.nome} onChange={e => setBlock(bi, { nome: e.target.value })} /></div>
                                    <div className="w-24"><Label>Rondas</Label><Input type="number" min={1} value={block.rondas} onChange={e => setBlock(bi, { rondas: Number(e.target.value) })} /></div>
                                </div>
                                {block.series.map((line, li) => (
                                    <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-3" key={li}>
                                        <div><Label>Repetições</Label><Input type="number" min={1} value={line.repeticoes} onChange={e => setLine(bi, li, { repeticoes: Number(e.target.value) })} /></div>
                                        <div><Label>Distância (m)</Label><Input type="number" min={1} value={line.distancia_m} onChange={e => setLine(bi, li, { distancia_m: Number(e.target.value) })} /></div>
                                        <div><Label>Exercício / estilo</Label><Input value={line.exercicio} onChange={e => setLine(bi, li, { exercicio: e.target.value })} /></div>
                                        <div><Label>Intervalo / descanso</Label><Input value={line.intervalo} onChange={e => setLine(bi, li, { intervalo: e.target.value })} placeholder="0:30" /></div>
                                        <div><Label>Saída</Label><Input value={line.saida} onChange={e => setLine(bi, li, { saida: e.target.value })} placeholder="2:00" /></div>
                                        <div><Label>Registo de tempo</Label><select className="flex h-10 w-full rounded-md border bg-background px-3" value={line.timing_mode} onChange={e => setLine(bi, li, { timing_mode: e.target.value as Series['timing_mode'] })}><option value="none">Sem registo</option><option value="each_rep">Por repetição</option><option value="whole_series">Série completa</option></select></div>
                                        <Button type="button" variant="outline" size="sm" onClick={() => setBlock(bi, { series: block.series.filter((_, i) => i !== li) })} disabled={block.series.length === 1}>Retirar linha</Button>
                                    </div>
                                ))}
                                <div className="flex flex-wrap gap-2"><Button type="button" variant="outline" onClick={() => setBlock(bi, { series: [...block.series, newLine()] })}>Adicionar linha</Button><Button type="button" variant="outline" disabled={blocks.length === 1} onClick={() => setBlocks(old => old.filter((_, i) => i !== bi))}>Retirar bloco</Button></div>
                            </CardContent></Card>
                        ))}
                        <Button variant="outline" onClick={() => setBlocks(old => [...old, newBlock()])}>Adicionar bloco</Button>
                        {Object.entries(errors).filter(([key]) => key !== 'nome').map(([key, value]) => <p key={key} className="text-sm text-destructive">{value}</p>)}
                        <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEditing(false)}>Cancelar</Button><Button disabled={saving} onClick={submit}>{saving ? 'A guardar…' : 'Guardar treino'}</Button></div>
                    </div>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
