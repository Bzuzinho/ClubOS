import { FormEvent, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowSquareOut, ArrowUp, ClockCounterClockwise, Copy, Eye, FloppyDisk, Image, Plus, Trash, UploadSimple } from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Components/ui/accordion';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';

type ContentPrimitive = string | number | boolean | null;
type Item = Record<string, string | number | null>;
type ContentValue = ContentPrimitive | ContentPrimitive[] | Item[];
type BlockContent = Record<string, ContentValue>;
type Block = { id?: string; block_key: string; type: string; is_visible: boolean; content: BlockContent };
type MediaItem = { id: string; url: string; alt_text: string; original_name: string; width?: number | null; height?: number | null; size: number; in_use: boolean };
type Version = { id: string; version: number; action: string; created_at: string; created_by?: string | null };
type Page = {
    id: string;
    slug: string;
    title: string;
    navigation_label?: string | null;
    status: 'legacy' | 'draft' | 'published' | 'scheduled' | 'hidden';
    is_system: boolean;
    show_in_navigation: boolean;
    sort_order: number;
    meta_title?: string | null;
    meta_description?: string | null;
    public_url: string;
    blocks: Block[];
    versions: Version[];
    has_published_version: boolean;
    scheduled_for?: string | null;
};

type BlockType = { value: string; label: string };
type PageFormData = {
    title: string;
    slug: string;
    navigation_label: string;
    show_in_navigation: boolean;
    sort_order: number;
    meta_title: string;
    meta_description: string;
    operation: string;
    scheduled_for: string;
    blocks: Block[];
};

const actionLabels: Record<string, string> = { created: 'Criação', imported: 'Importação', save_draft: 'Rascunho', publish: 'Publicação', schedule: 'Agendamento', hide: 'Ocultação', restored: 'Recuperação' };

function stringValue(value: unknown): string { return typeof value === 'string' ? value : ''; }
function numberValue(value: unknown, fallback = 0): number { const parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
function stringItems(value: unknown): string[] { return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []; }
function objectItems(value: unknown): Item[] { return Array.isArray(value) ? value.filter((item): item is Item => Boolean(item) && typeof item === 'object') : []; }
function newKey(type: string): string { return `${type}-${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now()}`; }

function emptyContent(type: string): BlockContent {
    switch (type) {
        case 'hero': return { eyebrow: 'BSCN', title: 'Título principal', text: 'Texto de introdução', image: '/site-assets/bscn-club-bright.webp', image_position: 'center', primary_label: '', primary_url: '', secondary_label: '', secondary_url: '' };
        case 'cards': return { eyebrow: 'Destaques', title: 'Título da secção', intro: '', columns: 3, items: [{ label: '', title: 'Novo card', text: 'Descrição', url: '' }] };
        case 'image_text': return { eyebrow: '', title: 'Título da secção', text: '', image: '/site-assets/bscn-club-bright.webp', image_alt: '', image_position: 'center', image_side: 'left', items: [], button_label: '', button_url: '' };
        case 'stats': return { items: [{ value: '1', label: 'Indicador' }] };
        case 'cta': return { eyebrow: '', title: 'Próximo passo', text: '', button_label: 'Saber mais', button_url: '/', secondary_label: '', secondary_url: '' };
        case 'news_feed': return { eyebrow: 'Atualidade', title: 'Notícias', intro: '', limit: 6 };
        case 'events_feed': return { eyebrow: 'Agenda', title: 'Próximos eventos', intro: '', limit: 12 };
        case 'contact_form': return { eyebrow: 'Pedido de contacto', title: 'Fala connosco', text: '', steps: ['Envio do pedido', 'Contacto do clube'] };
        case 'registration_form': return { eyebrow: 'Inscrição', title: 'Registo de atleta', text: '', steps: ['Dados do atleta', 'Validação pelo clube'] };
        default: return { eyebrow: '', title: 'Título da secção', text: 'Conteúdo da secção.' };
    }
}

function Field({ label, value, onChange, placeholder, type = 'text' }: { label: string; value: string | number; onChange: (value: string) => void; placeholder?: string; type?: string }) {
    return <div className="space-y-1.5"><Label>{label}</Label><Input type={type} value={value} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} /></div>;
}

function TextField({ label, value, onChange, rows = 4 }: { label: string; value: string; onChange: (value: string) => void; rows?: number }) {
    return <div className="space-y-1.5"><Label>{label}</Label><Textarea value={value} rows={rows} onChange={(event) => onChange(event.target.value)} /></div>;
}

function ImageFields({ content, media, update }: { content: BlockContent; media: MediaItem[]; update: (key: string, value: ContentValue) => void }) {
    const selected = stringValue(content.image);
    const choose = (url: string) => {
        update('image', url);
        const item = media.find((candidate) => candidate.url === url);
        if (item) update('image_alt', item.alt_text);
    };

    return (
        <div className="grid gap-3 md:grid-cols-2">
            <div className="space-y-1.5"><Label>Imagem</Label><select className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={selected} onChange={(event) => choose(event.target.value)}><option value="">Sem imagem</option>{selected && !media.some((item) => item.url === selected) && <option value={selected}>Imagem atual</option>}{media.map((item) => <option key={item.id} value={item.url}>{item.original_name}</option>)}</select></div>
            <Field label="Posição da imagem" value={stringValue(content.image_position) || 'center'} onChange={(value) => update('image_position', value)} placeholder="center 50%" />
            <div className="md:col-span-2"><Field label="Texto alternativo" value={stringValue(content.image_alt)} onChange={(value) => update('image_alt', value)} placeholder="Descreve a imagem para acessibilidade" /></div>
        </div>
    );
}

function ItemsEditor({ type, content, update }: { type: string; content: BlockContent; update: (key: string, value: ContentValue) => void }) {
    const isStats = type === 'stats';
    const entries = objectItems(content.items);
    const setEntry = (index: number, key: string, value: string) => update('items', entries.map((entry, current) => current === index ? { ...entry, [key]: value } : entry));
    const remove = (index: number) => update('items', entries.filter((_, current) => current !== index));
    const add = () => update('items', [...entries, isStats ? { value: '1', label: 'Indicador' } : { label: '', title: 'Novo card', text: '', url: '' }]);

    return (
        <div className="space-y-3">
            {entries.map((entry, index) => (
                <div key={index} className="rounded-lg border bg-muted/20 p-3">
                    <div className="mb-3 flex items-center justify-between"><p className="text-xs font-semibold uppercase text-muted-foreground">{isStats ? 'Indicador' : 'Card'} {index + 1}</p><Button type="button" variant="ghost" size="sm" onClick={() => remove(index)}><Trash /></Button></div>
                    <div className="grid gap-3 md:grid-cols-2">
                        {isStats ? <><Field label="Valor" value={entry.value || ''} onChange={(value) => setEntry(index, 'value', value)} /><Field label="Legenda" value={entry.label || ''} onChange={(value) => setEntry(index, 'label', value)} /></> : <><Field label="Etiqueta" value={entry.label || ''} onChange={(value) => setEntry(index, 'label', value)} /><Field label="Título" value={entry.title || ''} onChange={(value) => setEntry(index, 'title', value)} /><div className="md:col-span-2"><TextField label="Texto" value={stringValue(entry.text)} onChange={(value) => setEntry(index, 'text', value)} rows={2} /></div><div className="md:col-span-2"><Field label="Ligação (opcional)" value={entry.url || ''} onChange={(value) => setEntry(index, 'url', value)} placeholder="/pagina ou https://..." /></div></>}
                    </div>
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}><Plus className="mr-2" /> {isStats ? 'Adicionar indicador' : 'Adicionar card'}</Button>
        </div>
    );
}

function StringListEditor({ content, update, label }: { content: BlockContent; update: (key: string, value: ContentValue) => void; label: string }) {
    const entries = stringItems(content.items ?? content.steps);
    const key = content.steps ? 'steps' : 'items';
    return <div className="space-y-2"><Label>{label}</Label>{entries.map((entry, index) => <div className="flex gap-2" key={index}><Input value={entry} onChange={(event) => update(key, entries.map((item, current) => current === index ? event.target.value : item))} /><Button type="button" variant="outline" size="icon" onClick={() => update(key, entries.filter((_, current) => current !== index))}><Trash /></Button></div>)}<Button type="button" variant="outline" size="sm" onClick={() => update(key, [...entries, 'Novo item'])}><Plus className="mr-2" /> Adicionar</Button></div>;
}

function BlockFields({ block, media, update }: { block: Block; media: MediaItem[]; update: (key: string, value: ContentValue) => void }) {
    const content = block.content;
    const headingFields = <div className="grid gap-3 md:grid-cols-2"><Field label="Antetítulo" value={stringValue(content.eyebrow)} onChange={(value) => update('eyebrow', value)} /><Field label="Título" value={stringValue(content.title)} onChange={(value) => update('title', value)} /></div>;

    switch (block.type) {
        case 'hero': return <div className="space-y-4">{headingFields}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><div className="grid gap-3 md:grid-cols-2"><Field label="Botão principal" value={stringValue(content.primary_label)} onChange={(value) => update('primary_label', value)} /><Field label="Ligação principal" value={stringValue(content.primary_url)} onChange={(value) => update('primary_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div></div>;
        case 'rich_text': return <div className="space-y-4">{headingFields}<TextField label="Texto (separa parágrafos com uma linha vazia)" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={8} /></div>;
        case 'cards': return <div className="space-y-4">{headingFields}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><Field label="Colunas" type="number" value={numberValue(content.columns, 3)} onChange={(value) => update('columns', Number(value))} /><ItemsEditor type={block.type} content={content} update={update} /></div>;
        case 'image_text': return <div className="space-y-4">{headingFields}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><div className="grid gap-3 md:grid-cols-2"><div className="space-y-1.5"><Label>Lado da imagem</Label><Select value={stringValue(content.image_side) || 'left'} onValueChange={(value) => update('image_side', value)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="left">Esquerda</SelectItem><SelectItem value="right">Direita</SelectItem></SelectContent></Select></div><Field label="Texto do botão" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação do botão" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /></div><StringListEditor content={content} update={update} label="Lista de pontos" /></div>;
        case 'stats': return <ItemsEditor type={block.type} content={content} update={update} />;
        case 'cta': return <div className="space-y-4">{headingFields}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><div className="grid gap-3 md:grid-cols-2"><Field label="Botão principal" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação principal" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div></div>;
        case 'news_feed': case 'events_feed': return <div className="space-y-4">{headingFields}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><Field label="Número máximo" type="number" value={numberValue(content.limit, 6)} onChange={(value) => update('limit', Number(value))} /></div>;
        case 'contact_form': case 'registration_form': return <div className="space-y-4">{headingFields}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><StringListEditor content={content} update={update} label="Etapas apresentadas" /></div>;
        default: return null;
    }
}

function MediaCard({ item }: { item: MediaItem }) {
    const [alt, setAlt] = useState(item.alt_text);
    const save = () => router.patch(`/website-redes/media/${item.id}`, { alt_text: alt }, { preserveScroll: true });
    const remove = () => { if (window.confirm('Eliminar esta imagem da biblioteca?')) router.delete(`/website-redes/media/${item.id}`, { preserveScroll: true }); };
    return <div className="overflow-hidden rounded-lg border"><div className="aspect-video bg-muted"><img className="h-full w-full object-cover" src={item.url} alt={item.alt_text} /></div><div className="space-y-2 p-3"><p className="truncate text-xs font-medium">{item.original_name}</p><Input value={alt} onChange={(event) => setAlt(event.target.value)} aria-label="Texto alternativo" /><div className="flex justify-between gap-2"><Button type="button" size="sm" variant="outline" disabled={alt === item.alt_text || !alt.trim()} onClick={save}>Guardar texto</Button><Button type="button" size="sm" variant="ghost" disabled={item.in_use} title={item.in_use ? 'Imagem em utilização' : 'Eliminar'} onClick={remove}><Trash /></Button></div></div></div>;
}

export default function WebsitePageEdit({ page, media, blockTypes }: { page: Page; media: MediaItem[]; blockTypes: BlockType[] }) {
    const form = useForm<PageFormData>({
        title: page.title,
        slug: page.slug,
        navigation_label: page.navigation_label || '',
        show_in_navigation: page.show_in_navigation,
        sort_order: page.sort_order,
        meta_title: page.meta_title || '',
        meta_description: page.meta_description || '',
        operation: 'save_draft',
        scheduled_for: '',
        blocks: page.blocks,
    });
    const upload = useForm<{ image: File | null; alt_text: string }>({ image: null, alt_text: '' });
    const [newBlockType, setNewBlockType] = useState('rich_text');
    const [scheduledFor, setScheduledFor] = useState(page.scheduled_for ? page.scheduled_for.slice(0, 16) : '');

    const typeLabels = useMemo(() => Object.fromEntries(blockTypes.map((item) => [item.value, item.label])), [blockTypes]);
    const updateContent = (index: number, key: string, value: ContentValue) => form.setData((data) => ({
        ...data,
        blocks: data.blocks.map((block, current) => current === index ? { ...block, content: { ...block.content, [key]: value } } : block),
    }));
    const moveBlock = (index: number, direction: -1 | 1) => { const target = index + direction; if (target < 0 || target >= form.data.blocks.length) return; const blocks = [...form.data.blocks]; [blocks[index], blocks[target]] = [blocks[target], blocks[index]]; form.setData('blocks', blocks); };
    const removeBlock = (index: number) => { if (window.confirm('Remover este bloco do rascunho?')) form.setData('blocks', form.data.blocks.filter((_, current) => current !== index)); };
    const duplicateBlock = (index: number) => { const source = form.data.blocks[index]; const copy = { ...source, id: undefined, block_key: newKey(source.type), content: JSON.parse(JSON.stringify(source.content)) }; const blocks = [...form.data.blocks]; blocks.splice(index + 1, 0, copy); form.setData('blocks', blocks); };
    const addBlock = () => form.setData('blocks', [...form.data.blocks, { block_key: newKey(newBlockType), type: newBlockType, is_visible: true, content: emptyContent(newBlockType) }]);

    const submit = (operation: 'save_draft' | 'publish' | 'schedule' | 'hide') => {
        if (operation === 'publish' && !window.confirm('Publicar este rascunho no website?')) return;
        if (operation === 'hide' && !window.confirm('Ocultar esta página do website público?')) return;
        form.transform((data) => ({ ...data, operation, scheduled_for: operation === 'schedule' ? scheduledFor : '' }));
        form.patch(`/website-redes/paginas/${page.id}`, { preserveScroll: true });
    };

    const uploadImage = (event: FormEvent) => { event.preventDefault(); upload.post('/website-redes/media', { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() }); };
    const deletePage = () => { if (window.confirm('Eliminar esta página? Poderá ser recuperada apenas por intervenção técnica.')) router.delete(`/website-redes/paginas/${page.id}`); };

    return (
        <AuthenticatedLayout fullWidth header={<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"><div className="flex items-center gap-3"><Button asChild variant="ghost" size="icon"><Link href="/website-redes/paginas"><ArrowLeft /></Link></Button><div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Editor de páginas</p><h1 className="text-2xl font-semibold">{page.title}</h1></div></div><div className="flex flex-wrap gap-2"><Button asChild variant="outline" size="sm"><a href={`/website-redes/paginas/${page.id}/previsualizar`} target="_blank" rel="noreferrer"><Eye className="mr-2" /> Pré-visualizar</a></Button><Button variant="outline" size="sm" onClick={() => submit('save_draft')} disabled={form.processing}><FloppyDisk className="mr-2" /> Guardar</Button><Button size="sm" onClick={() => submit('publish')} disabled={form.processing}>Publicar</Button></div></div>}>
            <Head title={`${page.title} — Editor`} />
            <Tabs defaultValue="content" className="space-y-4">
                <TabsList className="max-w-full overflow-x-auto"><TabsTrigger value="content">Conteúdo</TabsTrigger><TabsTrigger value="settings">Página e SEO</TabsTrigger><TabsTrigger value="media">Imagens</TabsTrigger><TabsTrigger value="history">Histórico</TabsTrigger></TabsList>

                <TabsContent value="content" className="space-y-4">
                    {form.errors.blocks && <p className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{form.errors.blocks}</p>}
                    {form.data.blocks.length === 0 ? <Card><CardContent className="flex flex-col items-center gap-4 p-10 text-center"><FileTextIcon /><div><h2 className="font-semibold">Importar o conteúdo atual</h2><p className="mt-1 text-sm text-muted-foreground">Cria um rascunho editável sem alterar o website público.</p></div><Button onClick={() => router.post(`/website-redes/paginas/${page.id}/importar`)}>Importar para o editor</Button></CardContent></Card> : <Accordion type="multiple" className="space-y-3" defaultValue={form.data.blocks.slice(0, 1).map((block) => block.block_key)}>{form.data.blocks.map((block, index) => <AccordionItem value={block.block_key} key={block.block_key} className="rounded-lg border bg-card px-4 shadow-sm"><div className="flex items-start gap-3"><AccordionTrigger className="min-w-0 flex-1 no-underline hover:no-underline"><span className="flex min-w-0 items-center gap-2"><Badge variant="secondary">{index + 1}</Badge><span className="truncate">{typeLabels[block.type] || block.type}</span>{!block.is_visible && <Badge variant="outline">Oculto</Badge>}</span></AccordionTrigger><div className="flex items-center gap-1 pt-2"><Switch checked={block.is_visible} onCheckedChange={(checked) => form.setData('blocks', form.data.blocks.map((item, current) => current === index ? { ...item, is_visible: checked } : item))} aria-label="Visibilidade do bloco" /><Button type="button" variant="ghost" size="icon" onClick={() => moveBlock(index, -1)} disabled={index === 0}><ArrowUp /></Button><Button type="button" variant="ghost" size="icon" onClick={() => moveBlock(index, 1)} disabled={index === form.data.blocks.length - 1}><ArrowDown /></Button><Button type="button" variant="ghost" size="icon" onClick={() => duplicateBlock(index)}><Copy /></Button><Button type="button" variant="ghost" size="icon" onClick={() => removeBlock(index)}><Trash /></Button></div></div><AccordionContent className="border-t pt-4"><BlockFields block={block} media={media} update={(key, value) => updateContent(index, key, value)} /></AccordionContent></AccordionItem>)}</Accordion>}
                    <Card><CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end"><div className="flex-1 space-y-1.5"><Label>Novo bloco</Label><Select value={newBlockType} onValueChange={setNewBlockType}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{blockTypes.map((type) => <SelectItem key={type.value} value={type.value}>{type.label}</SelectItem>)}</SelectContent></Select></div><Button type="button" onClick={addBlock}><Plus className="mr-2" /> Adicionar bloco</Button></CardContent></Card>
                </TabsContent>

                <TabsContent value="settings" className="space-y-4">
                    <Card><CardHeader><CardTitle>Identificação e menu</CardTitle></CardHeader><CardContent className="grid gap-4 md:grid-cols-2"><Field label="Título da página" value={form.data.title} onChange={(value) => form.setData('title', value)} /><div className="space-y-1.5"><Label>Endereço</Label><Input value={form.data.slug} disabled={page.is_system || page.has_published_version} onChange={(event) => form.setData('slug', event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} />{(page.is_system || page.has_published_version) && <p className="text-xs text-muted-foreground">O endereço fica bloqueado nas páginas essenciais ou já publicadas, para não quebrar ligações.</p>}{form.errors.slug && <p className="text-xs text-destructive">{form.errors.slug}</p>}</div><Field label="Nome no menu" value={form.data.navigation_label} onChange={(value) => form.setData('navigation_label', value)} /><Field label="Ordem" type="number" value={form.data.sort_order} onChange={(value) => form.setData('sort_order', Number(value))} /><label className="flex items-center gap-3 text-sm"><Switch checked={form.data.show_in_navigation} onCheckedChange={(checked) => form.setData('show_in_navigation', checked)} /> Mostrar no menu principal</label></CardContent></Card>
                    <Card><CardHeader><CardTitle>Pesquisa e partilha</CardTitle></CardHeader><CardContent className="space-y-4"><Field label="Título SEO" value={form.data.meta_title} onChange={(value) => form.setData('meta_title', value)} /><TextField label="Descrição SEO" value={form.data.meta_description} onChange={(value) => form.setData('meta_description', value)} rows={3} /></CardContent></Card>
                    <Card><CardHeader><CardTitle>Publicação</CardTitle></CardHeader><CardContent className="space-y-4"><div className="flex flex-wrap items-center gap-2"><Badge>{page.status}</Badge><a className="text-sm text-primary hover:underline" href={page.public_url} target="_blank" rel="noreferrer">{page.public_url} <ArrowSquareOut className="inline" /></a></div><div className="grid gap-3 sm:grid-cols-[minmax(220px,1fr)_auto]"><Input type="datetime-local" value={scheduledFor} onChange={(event) => setScheduledFor(event.target.value)} /><Button variant="outline" onClick={() => submit('schedule')} disabled={!scheduledFor || form.processing}><ClockCounterClockwise className="mr-2" /> Agendar</Button></div><div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => submit('hide')}>Ocultar página</Button>{!page.is_system && <Button variant="destructive" onClick={deletePage}>Eliminar página</Button>}</div></CardContent></Card>
                </TabsContent>

                <TabsContent value="media" className="space-y-4">
                    <Card><CardHeader><CardTitle>Adicionar imagem</CardTitle></CardHeader><CardContent><form onSubmit={uploadImage} className="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end"><div className="space-y-1.5"><Label>Ficheiro JPG, PNG ou WebP</Label><Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => upload.setData('image', event.target.files?.[0] || null)} required />{upload.errors.image && <p className="text-xs text-destructive">{upload.errors.image}</p>}</div><div className="space-y-1.5"><Label>Texto alternativo</Label><Input value={upload.data.alt_text} onChange={(event) => upload.setData('alt_text', event.target.value)} placeholder="Descreve o conteúdo da imagem" required />{upload.errors.alt_text && <p className="text-xs text-destructive">{upload.errors.alt_text}</p>}</div><Button type="submit" disabled={upload.processing}><UploadSimple className="mr-2" /> Carregar</Button></form></CardContent></Card>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">{media.map((item) => <MediaCard item={item} key={item.id} />)}{media.length === 0 && <Card><CardContent className="p-8 text-sm text-muted-foreground">A biblioteca ainda não tem imagens.</CardContent></Card>}</div>
                </TabsContent>

                <TabsContent value="history"><Card><CardHeader><CardTitle>Versões</CardTitle></CardHeader><CardContent className="p-0"><div className="divide-y">{page.versions.map((version) => <div key={version.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-medium">Versão {version.version} · {actionLabels[version.action] || version.action}</p><p className="text-xs text-muted-foreground">{new Date(version.created_at).toLocaleString('pt-PT')} · {version.created_by || 'Sistema'}</p></div><Button variant="outline" size="sm" onClick={() => { if (window.confirm('Recuperar esta versão para o rascunho?')) router.post(`/website-redes/paginas/${page.id}/versoes/${version.id}/recuperar`); }}><ClockCounterClockwise className="mr-2" /> Recuperar</Button></div>)}{page.versions.length === 0 && <p className="p-6 text-sm text-muted-foreground">Ainda não existem versões.</p>}</div></CardContent></Card></TabsContent>
            </Tabs>
        </AuthenticatedLayout>
    );
}

function FileTextIcon() { return <Image size={40} className="text-muted-foreground" />; }
