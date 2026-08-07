import { FormEvent, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowSquareOut, DownloadSimple, Eye, EyeSlash, FileText, GlobeHemisphereWest, Plus, Timer } from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

type PageStatus = 'legacy' | 'draft' | 'published' | 'scheduled' | 'hidden';

type WebsitePage = {
    id: string;
    slug: string;
    title: string;
    navigation_label?: string | null;
    status: PageStatus;
    is_system: boolean;
    show_in_navigation: boolean;
    sort_order: number;
    blocks_count: number;
    published_at?: string | null;
    scheduled_for?: string | null;
    public_url: string;
    edit_url: string;
};

const statusLabels: Record<PageStatus, string> = {
    legacy: 'Site atual',
    draft: 'Rascunho',
    published: 'Publicada',
    scheduled: 'Agendada',
    hidden: 'Oculta',
};

const statusClasses: Record<PageStatus, string> = {
    legacy: 'border-slate-200 bg-slate-50 text-slate-700',
    draft: 'border-amber-200 bg-amber-50 text-amber-800',
    published: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    scheduled: 'border-blue-200 bg-blue-50 text-blue-800',
    hidden: 'border-slate-300 bg-slate-100 text-slate-700',
};

export default function WebsitePagesIndex({ pages, summary }: { pages: WebsitePage[]; summary: { total: number; published: number; draft: number; hidden: number } }) {
    const [creating, setCreating] = useState(false);
    const form = useForm({
        title: '',
        slug: '',
        navigation_label: '',
        show_in_navigation: true,
        sort_order: 80,
        meta_title: '',
        meta_description: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/website/paginas', { onSuccess: () => form.reset() });
    };

    const importWebsite = () => {
        if (!window.confirm('Importar todas as páginas do website atual para o editor? Os rascunhos existentes serão substituídos e guardados no histórico. O website público não será alterado.')) return;
        form.post('/website/paginas/importar-website-atual', { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Website</p><h1 className="text-2xl font-semibold">Estrutura do website</h1></div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm"><Link href="/website">Dashboard</Link></Button>
                        <Button asChild variant="outline" size="sm"><a href="/" target="_blank" rel="noreferrer">Abrir website <ArrowSquareOut className="ml-2" /></a></Button>
                        <Button variant="outline" size="sm" onClick={importWebsite} disabled={form.processing}><DownloadSimple className="mr-2" /> Importar website atual</Button>
                        <Button size="sm" onClick={() => setCreating((value) => !value)}><Plus className="mr-2" /> Nova página</Button>
                    </div>
                </div>
            }
        >
            <Head title="Estrutura do website" />
            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { label: 'Total de páginas', value: summary.total, icon: FileText },
                        { label: 'Publicadas/agendadas', value: summary.published, icon: GlobeHemisphereWest },
                        { label: 'Por publicar', value: summary.draft, icon: Timer },
                        { label: 'Ocultas', value: summary.hidden, icon: EyeSlash },
                    ].map(({ label, value, icon: Icon }) => <Card key={label}><CardContent className="flex items-center justify-between p-4"><div><p className="text-sm text-muted-foreground">{label}</p><p className="mt-1 text-3xl font-semibold">{value}</p></div><span className="rounded-xl bg-primary/10 p-3 text-primary"><Icon size={24} /></span></CardContent></Card>)}
                </div>

                {creating && (
                    <Card>
                        <CardHeader><CardTitle>Criar página</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                                <div className="space-y-2"><Label htmlFor="title">Título</Label><Input id="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required />{form.errors.title && <p className="text-xs text-destructive">{form.errors.title}</p>}</div>
                                <div className="space-y-2"><Label htmlFor="slug">Endereço</Label><div className="flex items-center"><span className="rounded-l-md border border-r-0 bg-muted px-3 py-2 text-sm text-muted-foreground">/</span><Input id="slug" className="rounded-l-none" value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-'))} placeholder="nova-pagina" required /></div>{form.errors.slug && <p className="text-xs text-destructive">{form.errors.slug}</p>}</div>
                                <div className="space-y-2"><Label htmlFor="navigation_label">Nome no menu</Label><Input id="navigation_label" value={form.data.navigation_label} onChange={(event) => form.setData('navigation_label', event.target.value)} placeholder="Usa o título se ficar vazio" /></div>
                                <div className="space-y-2"><Label htmlFor="sort_order">Ordem no menu</Label><Input id="sort_order" type="number" min={0} max={999} value={form.data.sort_order} onChange={(event) => form.setData('sort_order', Number(event.target.value))} /></div>
                                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.show_in_navigation} onChange={(event) => form.setData('show_in_navigation', event.target.checked)} /> Mostrar no menu principal</label>
                                <div className="flex justify-end gap-2 lg:col-span-2"><Button type="button" variant="outline" onClick={() => setCreating(false)}>Cancelar</Button><Button type="submit" disabled={form.processing}>Criar rascunho</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader><CardTitle>Estrutura do website</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {pages.map((page) => (
                                <div key={page.id} className="flex flex-col gap-3 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2"><h2 className="font-medium">{page.title}</h2><Badge variant="outline" className={statusClasses[page.status]}>{statusLabels[page.status]}</Badge>{page.is_system && <Badge variant="secondary">Essencial</Badge>}{page.show_in_navigation && <Badge variant="secondary">Menu</Badge>}</div>
                                        <p className="mt-1 text-sm text-muted-foreground">{page.public_url} · {page.blocks_count} {page.blocks_count === 1 ? 'bloco' : 'blocos'}</p>
                                        {page.status === 'scheduled' && page.scheduled_for && <p className="mt-1 text-xs text-blue-700">Publicação: {new Date(page.scheduled_for).toLocaleString('pt-PT')}</p>}
                                    </div>
                                    <div className="flex shrink-0 flex-wrap gap-2"><Button asChild variant="outline" size="sm"><a href={page.public_url} target="_blank" rel="noreferrer"><Eye className="mr-2" /> Ver</a></Button><Button asChild size="sm"><Link href={page.edit_url}>Editar</Link></Button></div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
