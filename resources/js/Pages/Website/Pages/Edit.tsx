import { DragEvent, FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowSquareOut,
    Check,
    ClockCounterClockwise,
    Copy,
    Desktop,
    DeviceMobile,
    DeviceTablet,
    DotsSixVertical,
    Eye,
    FloppyDisk,
    Plus,
    SpinnerGap,
    Trash,
    UploadSimple,
    X,
} from '@phosphor-icons/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import { ManagedPageCanvas } from '@/Pages/PublicSite/ManagedPage';
import { PublicEvent, PublicNews } from '@/Pages/PublicSite/types';

type ContentPrimitive = string | number | boolean | null;
type Item = Record<string, string | number | null>;
type ContentValue = ContentPrimitive | ContentPrimitive[] | Item[] | SectionItem[];
type BlockContent = Record<string, ContentValue>;
type Shadow = 'none' | 'soft' | 'medium' | 'strong';
type Font = 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
type SectionItemType = 'subsection' | 'card' | 'text' | 'image' | 'button' | 'data_collection';

type BlockStyle = {
    background_color: string | null;
    text_color: string | null;
    heading_color: string | null;
    accent_color: string | null;
    padding_top: number;
    padding_bottom: number;
    content_width: 'page' | 'compact' | 'wide' | 'full';
    text_align: 'left' | 'center' | 'right';
    heading_size: number;
    body_size: number;
    border_radius: number;
    shadow: Shadow;
    card_background: string | null;
    card_border_color: string | null;
    card_radius: number;
    card_shadow: Shadow;
    card_gap: number;
    background_image: string | null;
    background_position: string;
    heading_font: Font;
    body_font: Font;
    heading_weight: number;
    body_weight: number;
    line_height: number;
};

type BlockSettings = {
    anchor_id: string | null;
    animation: 'none' | 'fade' | 'slide-up' | 'zoom';
    animation_delay: number;
    hide_mobile: boolean;
    hide_desktop: boolean;
    open_links_new_tab: boolean;
};

type Block = {
    id?: string;
    block_key: string;
    type: string;
    is_visible: boolean;
    content: BlockContent;
    style: BlockStyle;
    settings: BlockSettings;
};

type SectionItemStyle = {
    background_color: string | null;
    text_color: string | null;
    heading_color: string | null;
    accent_color: string | null;
    border_color: string | null;
    border_width: number;
    border_radius: number;
    shadow: Shadow;
    padding: number;
    min_height: number;
    text_align: 'left' | 'center' | 'right';
    heading_size: number;
    body_size: number;
    heading_font: Font;
    body_font: Font;
    heading_weight: number;
    body_weight: number;
    line_height: number;
    column_span: number;
    tablet_span: number;
    mobile_span: number;
    row_span: number;
    image_ratio: 'auto' | '1:1' | '4:3' | '16:9' | '21:9';
    image_fit: 'cover' | 'contain';
};

type SectionItemSettings = {
    animation: 'none' | 'fade' | 'slide-up' | 'zoom';
    animation_delay: number;
    hide_mobile: boolean;
    hide_desktop: boolean;
    open_link_new_tab: boolean;
};

type SectionItem = {
    id: string;
    type: SectionItemType;
    is_visible: boolean;
    content: BlockContent;
    style: SectionItemStyle;
    settings: SectionItemSettings;
};

type PageDesign = {
    background_color: string;
    text_color: string;
    heading_color: string;
    accent_color: string;
    heading_font: 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    body_font: 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    base_font_size: number;
    content_width: 'compact' | 'standard' | 'wide';
    background_image: string | null;
    background_position: string;
};

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
    design_settings?: Partial<PageDesign>;
    public_url: string;
    blocks: Block[];
    versions: Version[];
    has_published_version: boolean;
    scheduled_for?: string | null;
};

type PageData = {
    title: string;
    slug: string;
    navigation_label: string;
    show_in_navigation: boolean;
    sort_order: number;
    meta_title: string;
    meta_description: string;
    design_settings: PageDesign;
    blocks: Block[];
};

type BlockType = { value: string; label: string };
type PublicPartner = { id: string; name: string; description?: string | null; logo?: string | null; website?: string | null; type?: string | null };
type Device = 'desktop' | 'tablet' | 'mobile';
type InspectorTab = 'content' | 'design' | 'behavior' | 'page' | 'media' | 'history';

const DEFAULT_DESIGN: PageDesign = {
    background_color: '#ffffff', text_color: '#102c44', heading_color: '#062b54', accent_color: '#f2e613',
    heading_font: 'inter', body_font: 'inter', base_font_size: 16, content_width: 'standard', background_image: null, background_position: 'center top',
};
const DEFAULT_STYLE: BlockStyle = {
    background_color: null, text_color: null, heading_color: null, accent_color: null,
    padding_top: 64, padding_bottom: 64, content_width: 'page', text_align: 'left', heading_size: 32, body_size: 14,
    border_radius: 0, shadow: 'none', card_background: null, card_border_color: null, card_radius: 15, card_shadow: 'soft', card_gap: 14,
    background_image: null, background_position: 'center', heading_font: 'inherit', body_font: 'inherit', heading_weight: 600, body_weight: 400, line_height: 1.6,
};
const DEFAULT_SETTINGS: BlockSettings = {
    anchor_id: null, animation: 'none', animation_delay: 0, hide_mobile: false, hide_desktop: false, open_links_new_tab: false,
};
const DEFAULT_ITEM_STYLE: SectionItemStyle = {
    background_color: null, text_color: null, heading_color: null, accent_color: null, border_color: null,
    border_width: 0, border_radius: 0, shadow: 'none', padding: 0, min_height: 0, text_align: 'left',
    heading_size: 22, body_size: 14, heading_font: 'inherit', body_font: 'inherit', heading_weight: 600, body_weight: 400,
    line_height: 1.6, column_span: 1, tablet_span: 1, mobile_span: 1, row_span: 1, image_ratio: 'auto', image_fit: 'cover',
};
const DEFAULT_ITEM_SETTINGS: SectionItemSettings = {
    animation: 'none', animation_delay: 0, hide_mobile: false, hide_desktop: false, open_link_new_tab: false,
};
const itemTypeLabels: Record<SectionItemType, string> = {
    subsection: 'Subsecção', card: 'Card', text: 'Texto', image: 'Imagem', button: 'Botão', data_collection: 'Dados da aplicação',
};
const actionLabels: Record<string, string> = {
    created: 'Criação', imported: 'Importação', autosave: 'Gravação automática', save_draft: 'Rascunho', publish: 'Publicação',
    schedule: 'Agendamento', hide: 'Ocultação', restored: 'Recuperação',
};
const deviceWidths: Record<Device, number> = { desktop: 1440, tablet: 820, mobile: 390 };

function stringValue(value: unknown): string { return typeof value === 'string' ? value : ''; }
function scalarValue(value: unknown): string | number { return typeof value === 'string' || typeof value === 'number' ? value : ''; }
function numberValue(value: unknown, fallback = 0): number { const parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
function booleanValue(value: unknown, fallback = false): boolean { return typeof value === 'boolean' ? value : fallback; }
function stringItems(value: unknown): string[] { return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []; }
function objectItems(value: unknown): Item[] { return Array.isArray(value) ? value.filter((item): item is Item => Boolean(item) && typeof item === 'object') : []; }
function sectionItems(value: unknown): SectionItem[] {
    return Array.isArray(value)
        ? value.filter((item): item is SectionItem => Boolean(item) && typeof item === 'object' && typeof item.id === 'string')
            .map((item) => ({
                ...item,
                is_visible: item.is_visible !== false,
                content: item.content || {},
                style: { ...DEFAULT_ITEM_STYLE, ...(item.style || {}) },
                settings: { ...DEFAULT_ITEM_SETTINGS, ...(item.settings || {}) },
            }))
        : [];
}
function newKey(type: string): string { return `${type}-${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now()}`; }
function csrfToken(): string { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''; }

function createSectionItem(type: SectionItemType): SectionItem {
    const content: Record<SectionItemType, BlockContent> = {
        subsection: { eyebrow: 'Subsecção', title: 'Novo espaço de conteúdo', text: 'Adiciona aqui o conteúdo desta área.', image: '', image_alt: '', button_label: '', url: '' },
        card: { eyebrow: 'Destaque', title: 'Novo card', text: 'Adiciona aqui o conteúdo do card.', image: '', image_alt: '', button_label: '', url: '' },
        text: { eyebrow: '', title: 'Título do texto', text: 'Escreve aqui o conteúdo.' },
        image: { image: '', image_alt: '', url: '' },
        button: { label: 'Saber mais', url: '/' },
        data_collection: { source: 'news', limit: 3, layout: 'grid', columns: 3, show_image: true, show_meta: true, show_description: true, show_link: true, link_label: 'Saber mais' },
    };
    const style = { ...DEFAULT_ITEM_STYLE };
    if (type === 'card') Object.assign(style, { background_color: '#edf4f7', border_color: '#afc7d3', border_width: 2, border_radius: 16, shadow: 'soft', padding: 24, min_height: 170 });
    if (type === 'button') Object.assign(style, { text_align: 'center', min_height: 64 });
    if (type === 'image') Object.assign(style, { image_ratio: '16:9', border_radius: 16, shadow: 'soft' });
    return { id: newKey('element'), type, is_visible: true, content: content[type], style, settings: { ...DEFAULT_ITEM_SETTINGS } };
}

function emptyContent(type: string): BlockContent {
    switch (type) {
        case 'hero': return { eyebrow: 'BSCN', title: 'Título principal', text: 'Texto de introdução', image: '/site-assets/bscn-club-bright.webp', image_position: 'center', primary_label: '', primary_url: '', secondary_label: '', secondary_url: '' };
        case 'section': return { eyebrow: 'Secção', title: 'Nova secção', intro: '', columns_desktop: 3, columns_tablet: 2, columns_mobile: 1, gap: 20, align_items: 'stretch', items: [createSectionItem('card')] };
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

function normaliseBlock(block: Block): Block {
    const content = block.type === 'section' ? { ...block.content, items: sectionItems(block.content?.items) } : block.content;
    return { ...block, content, style: { ...defaultStyleFor(block.type), ...(block.style || {}) }, settings: { ...DEFAULT_SETTINGS, ...(block.settings || {}) } };
}

function defaultStyleFor(type: string): BlockStyle {
    const spacing: Record<string, [number, number]> = {
        hero: [18, 0], section: [68, 72], rich_text: [68, 72], cards: [68, 72], news_feed: [68, 72], events_feed: [68, 72],
        stats: [0, 0], cta: [66, 78], contact_form: [75, 90], registration_form: [70, 92],
    };
    const [padding_top, padding_bottom] = spacing[type] || [64, 64];
    return { ...DEFAULT_STYLE, padding_top, padding_bottom };
}

function Field({ label, value, onChange, type = 'text', placeholder }: { label: string; value: string | number; onChange: (value: string) => void; type?: string; placeholder?: string }) {
    return <div className="space-y-1.5"><Label className="text-xs">{label}</Label><Input className="h-9" type={type} value={value} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} /></div>;
}

function TextField({ label, value, onChange, rows = 4 }: { label: string; value: string; onChange: (value: string) => void; rows?: number }) {
    return <div className="space-y-1.5"><Label className="text-xs">{label}</Label><Textarea value={value} rows={rows} onChange={(event) => onChange(event.target.value)} /></div>;
}

function ColorField({ label, value, onChange, fallback, allowEmpty = true }: { label: string; value: string | null; onChange: (value: string | null) => void; fallback: string; allowEmpty?: boolean }) {
    return <div className="space-y-1.5"><Label className="text-xs">{label}</Label><div className="flex gap-2"><input type="color" className="h-9 w-11 cursor-pointer rounded border bg-white p-1" value={value || fallback} onChange={(event) => onChange(event.target.value)} /><Input className="h-9" value={value || ''} placeholder={allowEmpty ? 'Herdar da página' : '#000000'} onChange={(event) => onChange(event.target.value || (allowEmpty ? null : fallback))} />{allowEmpty && value && <Button type="button" size="icon" variant="ghost" className="h-9 w-9" onClick={() => onChange(null)}><X /></Button>}</div></div>;
}

function RangeField({ label, value, onChange, min, max, suffix = 'px', step = 1 }: { label: string; value: number; onChange: (value: number) => void; min: number; max: number; suffix?: string; step?: number }) {
    return <div className="space-y-1.5"><div className="flex justify-between"><Label className="text-xs">{label}</Label><span className="text-xs text-muted-foreground">{value}{suffix}</span></div><input className="w-full accent-primary" type="range" min={min} max={max} step={step} value={value} onChange={(event) => onChange(Number(event.target.value))} /></div>;
}

function SelectField({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: Array<[string, string]> }) {
    return <div className="space-y-1.5"><Label className="text-xs">{label}</Label><Select value={value} onValueChange={onChange}><SelectTrigger className="h-9"><SelectValue /></SelectTrigger><SelectContent>{options.map(([key, text]) => <SelectItem key={key} value={key}>{text}</SelectItem>)}</SelectContent></Select></div>;
}

function ImageFields({ content, media, update }: { content: BlockContent; media: MediaItem[]; update: (key: string, value: ContentValue) => void }) {
    const selected = stringValue(content.image);
    const choose = (url: string) => {
        update('image', url);
        const item = media.find((candidate) => candidate.url === url);
        if (item) update('image_alt', item.alt_text);
    };
    return <div className="space-y-3"><div className="space-y-1.5"><Label className="text-xs">Imagem</Label><select className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={selected} onChange={(event) => choose(event.target.value)}><option value="">Sem imagem</option>{selected && !media.some((item) => item.url === selected) && <option value={selected}>Imagem atual</option>}{media.map((item) => <option key={item.id} value={item.url}>{item.original_name}</option>)}</select></div><Field label="Posição da imagem" value={stringValue(content.image_position) || 'center'} onChange={(value) => update('image_position', value)} placeholder="center 50%" /><Field label="Texto alternativo" value={stringValue(content.image_alt)} onChange={(value) => update('image_alt', value)} /></div>;
}

function BackgroundImageField({ label, value, media, onChange }: { label: string; value: string | null; media: MediaItem[]; onChange: (value: string | null) => void }) {
    return <div className="space-y-1.5"><Label className="text-xs">{label}</Label><select className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={value || ''} onChange={(event) => onChange(event.target.value || null)}><option value="">Sem imagem</option>{value && !media.some((item) => item.url === value) && <option value={value}>Imagem atual</option>}{media.map((item) => <option key={item.id} value={item.url}>{item.original_name}</option>)}</select></div>;
}

function ToggleField({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
    return <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"><span>{label}</span><Switch checked={checked} onCheckedChange={onChange} /></label>;
}

function ItemsEditor({ type, content, update }: { type: string; content: BlockContent; update: (key: string, value: ContentValue) => void }) {
    const entries = objectItems(content.items);
    const isStats = type === 'stats';
    const dragged = useRef<number | null>(null);
    const setEntry = (index: number, key: string, value: string) => update('items', entries.map((entry, current) => current === index ? { ...entry, [key]: value } : entry));
    const reorder = (target: number) => { if (dragged.current === null || dragged.current === target) return; const next = [...entries]; const [item] = next.splice(dragged.current, 1); next.splice(target, 0, item); dragged.current = target; update('items', next); };
    return <div className="space-y-3">{entries.map((entry, index) => <div key={index} draggable onDragStart={() => { dragged.current = index; }} onDragOver={(event) => { event.preventDefault(); reorder(index); }} className="rounded-lg border bg-muted/20 p-3"><div className="mb-3 flex items-center justify-between"><span className="flex items-center gap-1 text-xs font-semibold uppercase text-muted-foreground"><DotsSixVertical /> {isStats ? 'Indicador' : 'Card'} {index + 1}</span><Button type="button" size="icon" variant="ghost" className="h-7 w-7" onClick={() => update('items', entries.filter((_, current) => current !== index))}><Trash /></Button></div><div className="grid gap-3">{isStats ? <><Field label="Valor" value={entry.value || ''} onChange={(value) => setEntry(index, 'value', value)} /><Field label="Legenda" value={entry.label || ''} onChange={(value) => setEntry(index, 'label', value)} /></> : <><Field label="Etiqueta" value={entry.label || ''} onChange={(value) => setEntry(index, 'label', value)} /><Field label="Título" value={entry.title || ''} onChange={(value) => setEntry(index, 'title', value)} /><TextField label="Texto" value={stringValue(entry.text)} onChange={(value) => setEntry(index, 'text', value)} rows={2} /><Field label="Ligação" value={entry.url || ''} onChange={(value) => setEntry(index, 'url', value)} /></>}</div></div>)}<Button type="button" variant="outline" size="sm" onClick={() => update('items', [...entries, isStats ? { value: '1', label: 'Indicador' } : { label: '', title: 'Novo card', text: '', url: '' }])}><Plus className="mr-2" /> Adicionar</Button></div>;
}

function StringListEditor({ content, update }: { content: BlockContent; update: (key: string, value: ContentValue) => void }) {
    const entries = stringItems(content.items ?? content.steps);
    const key = content.steps ? 'steps' : 'items';
    return <div className="space-y-2"><Label className="text-xs">Lista</Label>{entries.map((entry, index) => <div className="flex gap-2" key={index}><Input className="h-9" value={entry} onChange={(event) => update(key, entries.map((item, current) => current === index ? event.target.value : item))} /><Button type="button" size="icon" variant="ghost" className="h-9 w-9" onClick={() => update(key, entries.filter((_, current) => current !== index))}><Trash /></Button></div>)}<Button type="button" variant="outline" size="sm" onClick={() => update(key, [...entries, 'Novo item'])}><Plus className="mr-2" /> Adicionar</Button></div>;
}

function BlockContentFields({ block, media, update }: { block: Block; media: MediaItem[]; update: (key: string, value: ContentValue) => void }) {
    const content = block.content;
    const headings = <><Field label="Antetítulo" value={stringValue(content.eyebrow)} onChange={(value) => update('eyebrow', value)} /><Field label="Título" value={stringValue(content.title)} onChange={(value) => update('title', value)} /></>;
    switch (block.type) {
        case 'hero': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><Field label="Botão principal" value={stringValue(content.primary_label)} onChange={(value) => update('primary_label', value)} /><Field label="Ligação principal" value={stringValue(content.primary_url)} onChange={(value) => update('primary_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div>;
        case 'section': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={3} /><div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Organização</p><div className="space-y-3"><RangeField label="Colunas no computador" value={numberValue(content.columns_desktop, 3)} onChange={(value) => update('columns_desktop', value)} min={1} max={6} suffix="" /><RangeField label="Colunas no tablet" value={numberValue(content.columns_tablet, 2)} onChange={(value) => update('columns_tablet', value)} min={1} max={4} suffix="" /><RangeField label="Colunas no telemóvel" value={numberValue(content.columns_mobile, 1)} onChange={(value) => update('columns_mobile', value)} min={1} max={2} suffix="" /><RangeField label="Espaço entre elementos" value={numberValue(content.gap, 20)} onChange={(value) => update('gap', value)} min={0} max={80} /><SelectField label="Alinhamento vertical" value={stringValue(content.align_items) || 'stretch'} onChange={(value) => update('align_items', value)} options={[["stretch", "Preencher altura"], ["start", "Topo"], ["center", "Centro"], ["end", "Fundo"]]} /></div></div><p className="text-xs text-muted-foreground">Seleciona um elemento na árvore da esquerda para editar o respetivo conteúdo e posição.</p></div>;
        case 'rich_text': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={10} /></div>;
        case 'cards': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><RangeField label="Colunas" value={numberValue(content.columns, 3)} onChange={(value) => update('columns', value)} min={1} max={4} suffix="" /><ItemsEditor type={block.type} content={content} update={update} /></div>;
        case 'image_text': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><SelectField label="Lado da imagem" value={stringValue(content.image_side) || 'left'} onChange={(value) => update('image_side', value)} options={[["left", "Esquerda"], ["right", "Direita"]]} /><Field label="Texto do botão" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /><StringListEditor content={content} update={update} /></div>;
        case 'stats': return <ItemsEditor type={block.type} content={content} update={update} />;
        case 'cta': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><Field label="Botão principal" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação principal" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div>;
        case 'news_feed': case 'events_feed': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><RangeField label="Número máximo" value={numberValue(content.limit, 6)} onChange={(value) => update('limit', value)} min={1} max={30} suffix="" /></div>;
        case 'contact_form': case 'registration_form': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><StringListEditor content={content} update={update} /></div>;
        default: return null;
    }
}

function SectionItemContentFields({ item, media, update }: { item: SectionItem; media: MediaItem[]; update: (key: string, value: ContentValue) => void }) {
    const content = item.content;
    if (item.type === 'image') return <div className="space-y-3"><ImageFields content={content} media={media} update={update} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></div>;
    if (item.type === 'button') return <div className="space-y-3"><Field label="Texto do botão" value={stringValue(content.label)} onChange={(value) => update('label', value)} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></div>;
    if (item.type === 'data_collection') return <div className="space-y-3">
        <SelectField label="Origem dos dados" value={stringValue(content.source) || 'news'} onChange={(value) => update('source', value)} options={[["news", "Notícias publicadas"], ["events", "Eventos públicos"], ["partners", "Parceiros ativos"]]} />
        <RangeField label="Número de registos" value={numberValue(content.limit, 3)} onChange={(value) => update('limit', value)} min={1} max={30} suffix="" />
        <SelectField label="Apresentação" value={stringValue(content.layout) || 'grid'} onChange={(value) => update('layout', value)} options={[["grid", "Grelha de cards"], ["list", "Lista"]]} />
        <RangeField label="Colunas dos dados" value={numberValue(content.columns, 3)} onChange={(value) => update('columns', value)} min={1} max={4} suffix="" />
        <div className="space-y-2 border-t pt-3"><p className="text-xs font-semibold uppercase text-muted-foreground">Campos visíveis</p><ToggleField label="Imagem ou logótipo" checked={booleanValue(content.show_image, true)} onChange={(value) => update('show_image', value)} /><ToggleField label="Data, categoria ou tipo" checked={booleanValue(content.show_meta, true)} onChange={(value) => update('show_meta', value)} /><ToggleField label="Resumo ou descrição" checked={booleanValue(content.show_description, true)} onChange={(value) => update('show_description', value)} /><ToggleField label="Ligação" checked={booleanValue(content.show_link, true)} onChange={(value) => update('show_link', value)} /><Field label="Texto da ligação" value={stringValue(content.link_label) || 'Saber mais'} onChange={(value) => update('link_label', value)} /></div>
    </div>;
    return <div className="space-y-3"><Field label="Antetítulo" value={stringValue(content.eyebrow)} onChange={(value) => update('eyebrow', value)} /><Field label="Título" value={stringValue(content.title)} onChange={(value) => update('title', value)} /><TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={6} />{item.type !== 'text' && <><ImageFields content={content} media={media} update={update} /><Field label="Texto da ligação" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></>}</div>;
}

function SectionItemStyleFields({ item, pageDesign, update }: { item: SectionItem; pageDesign: PageDesign; update: <K extends keyof SectionItemStyle>(key: K, value: SectionItemStyle[K]) => void }) {
    const style = item.style;
    return <div className="space-y-4">
        <div><p className="mb-3 text-sm font-semibold">Cores e superfície</p><div className="space-y-4"><ColorField label="Fundo" value={style.background_color} fallback="#edf4f7" onChange={(value) => update('background_color', value)} /><ColorField label="Texto" value={style.text_color} fallback={pageDesign.text_color} onChange={(value) => update('text_color', value)} /><ColorField label="Títulos" value={style.heading_color} fallback={pageDesign.heading_color} onChange={(value) => update('heading_color', value)} /><ColorField label="Destaque" value={style.accent_color} fallback={pageDesign.accent_color} onChange={(value) => update('accent_color', value)} /><ColorField label="Rebordo" value={style.border_color} fallback="#afc7d3" onChange={(value) => update('border_color', value)} /><RangeField label="Espessura do rebordo" value={style.border_width} min={0} max={8} onChange={(value) => update('border_width', value)} /><RangeField label="Cantos" value={style.border_radius} min={0} max={48} onChange={(value) => update('border_radius', value)} /><SelectField label="Sombra" value={style.shadow} onChange={(value) => update('shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Texto e tipografia</p><div className="space-y-4"><SelectField label="Tipo de letra dos títulos" value={style.heading_font} onChange={(value) => update('heading_font', value as Font)} options={[["inherit", "Herdar da secção"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho dos títulos" value={style.heading_size} min={14} max={72} onChange={(value) => update('heading_size', value)} /><RangeField label="Peso dos títulos" value={style.heading_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('heading_weight', value)} /><SelectField label="Tipo de letra do texto" value={style.body_font} onChange={(value) => update('body_font', value as Font)} options={[["inherit", "Herdar da secção"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho do texto" value={style.body_size} min={10} max={30} onChange={(value) => update('body_size', value)} /><RangeField label="Peso do texto" value={style.body_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('body_weight', value)} /><RangeField label="Altura da linha" value={style.line_height} min={1} max={2.4} step={0.1} suffix="" onChange={(value) => update('line_height', value)} /><SelectField label="Alinhamento" value={style.text_align} onChange={(value) => update('text_align', value as SectionItemStyle['text_align'])} options={[["left", "Esquerda"], ["center", "Centro"], ["right", "Direita"]]} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Tamanho e posição</p><div className="space-y-4"><RangeField label="Largura no computador" value={style.column_span} min={1} max={6} suffix=" col." onChange={(value) => update('column_span', value)} /><RangeField label="Largura no tablet" value={style.tablet_span} min={1} max={4} suffix=" col." onChange={(value) => update('tablet_span', value)} /><RangeField label="Largura no telemóvel" value={style.mobile_span} min={1} max={2} suffix=" col." onChange={(value) => update('mobile_span', value)} /><RangeField label="Altura na grelha" value={style.row_span} min={1} max={4} suffix=" linhas" onChange={(value) => update('row_span', value)} /><RangeField label="Espaço interior" value={style.padding} min={0} max={80} onChange={(value) => update('padding', value)} /><RangeField label="Altura mínima" value={style.min_height} min={0} max={600} onChange={(value) => update('min_height', value)} />{(item.type === 'image' || stringValue(item.content.image)) && <><SelectField label="Proporção da imagem" value={style.image_ratio} onChange={(value) => update('image_ratio', value as SectionItemStyle['image_ratio'])} options={[["auto", "Original"], ["1:1", "Quadrada"], ["4:3", "4:3"], ["16:9", "16:9"], ["21:9", "Panorâmica"]]} /><SelectField label="Ajuste da imagem" value={style.image_fit} onChange={(value) => update('image_fit', value as SectionItemStyle['image_fit'])} options={[["cover", "Preencher"], ["contain", "Conter"]]} /></>}</div></div>
    </div>;
}

function SectionItemBehaviorFields({ item, update }: { item: SectionItem; update: <K extends keyof SectionItemSettings>(key: K, value: SectionItemSettings[K]) => void }) {
    return <div className="space-y-4"><p className="text-sm font-semibold">Comportamento do elemento</p><SelectField label="Animação de entrada" value={item.settings.animation} onChange={(value) => update('animation', value as SectionItemSettings['animation'])} options={[["none", "Sem animação"], ["fade", "Aparecer"], ["slide-up", "Subir"], ["zoom", "Aproximar"]]} /><RangeField label="Atraso da animação" value={item.settings.animation_delay} min={0} max={2000} suffix=" ms" onChange={(value) => update('animation_delay', value)} /><ToggleField label="Ocultar no telemóvel" checked={item.settings.hide_mobile} onChange={(value) => update('hide_mobile', value)} /><ToggleField label="Ocultar no computador" checked={item.settings.hide_desktop} onChange={(value) => update('hide_desktop', value)} /><ToggleField label="Abrir ligações noutro separador" checked={item.settings.open_link_new_tab} onChange={(value) => update('open_link_new_tab', value)} /></div>;
}

function BlockStyleFields({ block, pageDesign, media, update }: { block: Block; pageDesign: PageDesign; media: MediaItem[]; update: <K extends keyof BlockStyle>(key: K, value: BlockStyle[K]) => void }) {
    const style = block.style;
    return <div className="space-y-4">
        <div><p className="mb-3 text-sm font-semibold">Fundo e cores</p><div className="space-y-4"><ColorField label="Fundo do bloco" value={style.background_color} fallback={pageDesign.background_color} onChange={(value) => update('background_color', value)} /><BackgroundImageField label="Imagem de fundo" value={style.background_image} media={media} onChange={(value) => update('background_image', value)} /><Field label="Posição do fundo" value={style.background_position} onChange={(value) => update('background_position', value)} placeholder="center 50%" /><ColorField label="Cor do texto" value={style.text_color} fallback={pageDesign.text_color} onChange={(value) => update('text_color', value)} /><ColorField label="Cor dos títulos" value={style.heading_color} fallback={pageDesign.heading_color} onChange={(value) => update('heading_color', value)} /><ColorField label="Destaque" value={style.accent_color} fallback={pageDesign.accent_color} onChange={(value) => update('accent_color', value)} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Texto e tipografia</p><div className="space-y-4"><SelectField label="Tipo de letra dos títulos" value={style.heading_font} onChange={(value) => update('heading_font', value as Font)} options={[["inherit", "Herdar da página"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho dos títulos" value={style.heading_size} min={18} max={88} onChange={(value) => update('heading_size', value)} /><RangeField label="Peso dos títulos" value={style.heading_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('heading_weight', value)} /><SelectField label="Tipo de letra do texto" value={style.body_font} onChange={(value) => update('body_font', value as Font)} options={[["inherit", "Herdar da página"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho do texto" value={style.body_size} min={10} max={30} onChange={(value) => update('body_size', value)} /><RangeField label="Peso do texto" value={style.body_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('body_weight', value)} /><RangeField label="Altura da linha" value={style.line_height} min={1} max={2.4} step={0.1} suffix="" onChange={(value) => update('line_height', value)} /><SelectField label="Alinhamento" value={style.text_align} onChange={(value) => update('text_align', value as BlockStyle['text_align'])} options={[["left", "Esquerda"], ["center", "Centro"], ["right", "Direita"]]} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Espaço e disposição</p><div className="space-y-4"><RangeField label="Espaço superior" value={style.padding_top} min={0} max={180} onChange={(value) => update('padding_top', value)} /><RangeField label="Espaço inferior" value={style.padding_bottom} min={0} max={180} onChange={(value) => update('padding_bottom', value)} /><SelectField label="Largura" value={style.content_width} onChange={(value) => update('content_width', value as BlockStyle['content_width'])} options={[["page", "Da página"], ["compact", "Compacta"], ["wide", "Larga"], ["full", "Ecrã inteiro"]]} /><RangeField label="Cantos do bloco" value={style.border_radius} min={0} max={48} onChange={(value) => update('border_radius', value)} /><SelectField label="Sombra do bloco" value={style.shadow} onChange={(value) => update('shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Cards e dados</p><div className="space-y-4"><ColorField label="Fundo dos cards" value={style.card_background} fallback="#edf4f7" onChange={(value) => update('card_background', value)} /><ColorField label="Rebordo dos cards" value={style.card_border_color} fallback="#afc7d3" onChange={(value) => update('card_border_color', value)} /><RangeField label="Cantos dos cards" value={style.card_radius} min={0} max={48} onChange={(value) => update('card_radius', value)} /><RangeField label="Espaço entre cards" value={style.card_gap} min={0} max={64} onChange={(value) => update('card_gap', value)} /><SelectField label="Sombra dos cards" value={style.card_shadow} onChange={(value) => update('card_shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>
    </div>;
}

function FramePreview({ width, children }: { width: number; children: ReactNode }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const initialise = () => {
        const doc = frame.current?.contentDocument;
        if (!doc) return;
        doc.head.innerHTML = '';
        document.head.querySelectorAll('link[rel="stylesheet"], style').forEach((node) => doc.head.appendChild(node.cloneNode(true)));
        doc.body.style.margin = '0';
        doc.body.style.background = '#fff';
        setTarget(doc.body);
    };
    return <><iframe ref={frame} title="Pré-visualização em tempo real" onLoad={initialise} style={{ width }} className="h-full max-w-none rounded-lg border-0 bg-white shadow-2xl" srcDoc="<!doctype html><html><head></head><body></body></html>" />{target && createPortal(children, target)}</>;
}

function MediaCard({ item }: { item: MediaItem }) {
    const [alt, setAlt] = useState(item.alt_text);
    return <div className="overflow-hidden rounded-lg border"><img className="aspect-video w-full object-cover" src={item.url} alt={item.alt_text} /><div className="space-y-2 p-2"><p className="truncate text-xs font-medium">{item.original_name}</p><Input className="h-8" value={alt} onChange={(event) => setAlt(event.target.value)} /><div className="flex justify-between"><Button size="sm" variant="outline" disabled={!alt.trim() || alt === item.alt_text} onClick={() => router.patch(`/website/media/${item.id}`, { alt_text: alt }, { preserveState: true, preserveScroll: true })}>Guardar</Button><Button size="icon" variant="ghost" className="h-8 w-8" disabled={item.in_use} onClick={() => window.confirm('Eliminar esta imagem?') && router.delete(`/website/media/${item.id}`, { preserveState: true })}><Trash /></Button></div></div></div>;
}

export default function WebsitePageEdit({ page, media, blockTypes, news = [], events = [], partners = [] }: { page: Page; media: MediaItem[]; blockTypes: BlockType[]; news?: PublicNews[]; events?: PublicEvent[]; partners?: PublicPartner[] }) {
    const initialData: PageData = {
        title: page.title, slug: page.slug, navigation_label: page.navigation_label || '', show_in_navigation: page.show_in_navigation,
        sort_order: page.sort_order, meta_title: page.meta_title || '', meta_description: page.meta_description || '',
        design_settings: { ...DEFAULT_DESIGN, ...(page.design_settings || {}) }, blocks: page.blocks.map(normaliseBlock),
    };
    const [data, setData] = useState<PageData>(initialData);
    const [selectedKey, setSelectedKey] = useState<string | null>(initialData.blocks[0]?.block_key || null);
    const [selectedItemId, setSelectedItemId] = useState<string | null>(null);
    const [inspectorTab, setInspectorTab] = useState<InspectorTab>(initialData.blocks.length ? 'content' : 'page');
    const [device, setDevice] = useState<Device>('desktop');
    const [newBlockType, setNewBlockType] = useState('section');
    const [newItemType, setNewItemType] = useState<SectionItemType>('card');
    const [scheduledFor, setScheduledFor] = useState(page.scheduled_for?.slice(0, 16) || '');
    const [versions, setVersions] = useState(page.versions);
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [draggedKey, setDraggedKey] = useState<string | null>(null);
    const lastSaved = useRef(JSON.stringify(initialData));
    const manualSaving = useRef(false);
    const autosaveController = useRef<AbortController | null>(null);
    const upload = useForm<{ image: File | null; alt_text: string }>({ image: null, alt_text: '' });
    const typeLabels = useMemo(() => Object.fromEntries(blockTypes.map((item) => [item.value, item.label])), [blockTypes]);
    const serialised = useMemo(() => JSON.stringify(data), [data]);
    const dirty = serialised !== lastSaved.current;
    const selectedIndex = data.blocks.findIndex((block) => block.block_key === selectedKey);
    const selectedBlock = selectedIndex >= 0 ? data.blocks[selectedIndex] : null;
    const selectedItems = selectedBlock?.type === 'section' ? sectionItems(selectedBlock.content.items) : [];
    const selectedItemIndex = selectedItems.findIndex((item) => item.id === selectedItemId);
    const selectedItem = selectedItemIndex >= 0 ? selectedItems[selectedItemIndex] : null;

    const patchBlock = (changes: Partial<Block>) => setData((current) => ({ ...current, blocks: current.blocks.map((block) => block.block_key === selectedKey ? { ...block, ...changes } : block) }));
    const updateContent = (key: string, value: ContentValue) => selectedBlock && patchBlock({ content: { ...selectedBlock.content, [key]: value } });
    const updateStyle = <K extends keyof BlockStyle>(key: K, value: BlockStyle[K]) => selectedBlock && patchBlock({ style: { ...selectedBlock.style, [key]: value } });
    const updateSettings = <K extends keyof BlockSettings>(key: K, value: BlockSettings[K]) => selectedBlock && patchBlock({ settings: { ...selectedBlock.settings, [key]: value } });
    const updateItems = (items: SectionItem[]) => selectedBlock && patchBlock({ content: { ...selectedBlock.content, items } });
    const patchItem = (changes: Partial<SectionItem>) => selectedItem && updateItems(selectedItems.map((item) => item.id === selectedItem.id ? { ...item, ...changes } : item));
    const updateItemContent = (key: string, value: ContentValue) => selectedItem && patchItem({ content: { ...selectedItem.content, [key]: value } });
    const updateItemStyle = <K extends keyof SectionItemStyle>(key: K, value: SectionItemStyle[K]) => selectedItem && patchItem({ style: { ...selectedItem.style, [key]: value } });
    const updateItemSettings = <K extends keyof SectionItemSettings>(key: K, value: SectionItemSettings[K]) => selectedItem && patchItem({ settings: { ...selectedItem.settings, [key]: value } });
    const selectBlock = (key: string, itemId?: string) => { setSelectedKey(key); setSelectedItemId(itemId || null); setInspectorTab('content'); };
    const reorderBlock = (targetKey: string) => {
        if (!draggedKey || draggedKey === targetKey) return;
        setData((current) => { const blocks = [...current.blocks]; const source = blocks.findIndex((block) => block.block_key === draggedKey); const target = blocks.findIndex((block) => block.block_key === targetKey); const [moved] = blocks.splice(source, 1); blocks.splice(target, 0, moved); return { ...current, blocks }; });
    };
    const addBlock = () => {
        const block: Block = { block_key: newKey(newBlockType), type: newBlockType, is_visible: true, content: emptyContent(newBlockType), style: defaultStyleFor(newBlockType), settings: { ...DEFAULT_SETTINGS } };
        setData((current) => { const blocks = [...current.blocks]; blocks.splice(Math.max(0, selectedIndex + 1), 0, block); return { ...current, blocks }; });
        setSelectedKey(block.block_key); setSelectedItemId(null); setInspectorTab('content');
    };
    const addItem = () => {
        if (!selectedBlock || selectedBlock.type !== 'section') return;
        const item = createSectionItem(newItemType);
        updateItems([...selectedItems, item]);
        setSelectedItemId(item.id);
        setInspectorTab('content');
    };
    const reorderItem = (blockKey: string, sourceId: string, targetId: string) => {
        if (sourceId === targetId) return;
        setData((current) => ({ ...current, blocks: current.blocks.map((block) => {
            if (block.block_key !== blockKey || block.type !== 'section') return block;
            const items = sectionItems(block.content.items);
            const source = items.findIndex((item) => item.id === sourceId);
            const target = items.findIndex((item) => item.id === targetId);
            if (source < 0 || target < 0) return block;
            const [moved] = items.splice(source, 1);
            items.splice(target, 0, moved);
            return { ...block, content: { ...block.content, items } };
        }) }));
    };
    const duplicateBlock = () => {
        if (selectedItem) {
            const copy = { ...structuredClone(selectedItem), id: newKey('element') };
            const items = [...selectedItems];
            items.splice(selectedItemIndex + 1, 0, copy);
            updateItems(items);
            setSelectedItemId(copy.id);
            return;
        }
        if (!selectedBlock) return;
        const copy: Block = { ...structuredClone(selectedBlock), id: undefined, block_key: newKey(selectedBlock.type) };
        setData((current) => { const blocks = [...current.blocks]; blocks.splice(selectedIndex + 1, 0, copy); return { ...current, blocks }; });
        setSelectedKey(copy.block_key);
    };
    const removeBlock = () => {
        if (selectedItem) {
            if (!window.confirm('Remover este elemento da secção?')) return;
            const remaining = selectedItems.filter((item) => item.id !== selectedItem.id);
            updateItems(remaining);
            setSelectedItemId(remaining[Math.min(selectedItemIndex, remaining.length - 1)]?.id || null);
            return;
        }
        if (!selectedBlock || !window.confirm('Remover este bloco do rascunho?')) return;
        const remaining = data.blocks.filter((block) => block.block_key !== selectedBlock.block_key);
        setData((current) => ({ ...current, blocks: remaining }));
        setSelectedKey(remaining[Math.min(selectedIndex, remaining.length - 1)]?.block_key || null);
        setSelectedItemId(null);
    };

    const payload = (operation: string) => ({ ...data, operation, scheduled_for: operation === 'schedule' ? scheduledFor : '' });

    useEffect(() => {
        if (!dirty || manualSaving.current) return;
        const timer = window.setTimeout(async () => {
            const snapshot = serialised;
            const controller = new AbortController();
            autosaveController.current = controller;
            setSaving(true); setSaveError(null);
            try {
                const response = await fetch(`/website/paginas/${page.id}/autosave`, {
                    method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: JSON.stringify(payload('autosave')), signal: controller.signal,
                });
                const result = await response.json();
                if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || 'Não foi possível guardar.');
                lastSaved.current = snapshot; setSavedAt(result.saved_at || new Date().toISOString());
                if (result.version) setVersions((current) => current.some((item) => item.id === result.version.id) ? current : [result.version, ...current].slice(0, 50));
            } catch (error) { if (!(error instanceof DOMException && error.name === 'AbortError')) setSaveError(error instanceof Error ? error.message : 'Não foi possível guardar.'); }
            finally { if (autosaveController.current === controller) autosaveController.current = null; setSaving(false); }
        }, 4000);
        return () => window.clearTimeout(timer);
    }, [dirty, page.id, serialised]);

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    useEffect(() => {
        setVersions(page.versions);
    }, [page.versions]);

    const submit = (operation: 'save_draft' | 'publish' | 'schedule' | 'hide') => {
        if (operation === 'publish' && !window.confirm('Aplicar estas alterações imediatamente no website?')) return;
        if (operation === 'hide' && !window.confirm('Ocultar esta página do website público?')) return;
        autosaveController.current?.abort(); autosaveController.current = null;
        manualSaving.current = true; setSaving(true); setSaveError(null);
        router.patch(`/website/paginas/${page.id}`, payload(operation), {
            preserveScroll: true,
            onSuccess: () => { lastSaved.current = JSON.stringify(data); setSavedAt(new Date().toISOString()); },
            onError: (errors) => setSaveError(Object.values(errors).join(' ')),
            onFinish: () => { manualSaving.current = false; setSaving(false); },
        });
    };
    const close = () => { if (!dirty || window.confirm('Ainda existem alterações por guardar. Sair na mesma?')) router.visit('/website/paginas'); };
    const uploadImage = (event: FormEvent) => { event.preventDefault(); upload.post('/website/media', { forceFormData: true, preserveState: true, preserveScroll: true, onSuccess: () => upload.reset() }); };

    const livePage = { ...data, design_settings: data.design_settings, blocks: data.blocks };

    return <div className="fixed inset-0 z-[100] flex h-screen flex-col overflow-hidden bg-slate-100 text-foreground">
        <Head title={`${data.title} — Editor visual`} />
        <header className="flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-white px-3 shadow-sm lg:px-4">
            <div className="flex min-w-0 items-center gap-2"><Button variant="ghost" size="icon" onClick={close}><ArrowLeft /></Button><div className="min-w-0"><p className="truncate text-sm font-semibold">{data.title}</p><div className="flex items-center gap-2 text-xs text-muted-foreground"><Badge variant="outline" className="h-5">{page.status}</Badge>{saving ? <span className="flex items-center gap-1"><SpinnerGap className="animate-spin" /> A guardar</span> : dirty ? <span>Alterações por guardar</span> : <span className="flex items-center gap-1 text-emerald-700"><Check /> Guardado{savedAt ? ` às ${new Date(savedAt).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })}` : ''}</span>}</div></div></div>
            <div className="hidden items-center gap-1 rounded-lg border bg-slate-50 p-1 md:flex">{(['desktop', 'tablet', 'mobile'] as Device[]).map((item) => <Button key={item} variant={device === item ? 'default' : 'ghost'} size="icon" className="h-8 w-8" onClick={() => setDevice(item)} title={item}>{item === 'desktop' ? <Desktop /> : item === 'tablet' ? <DeviceTablet /> : <DeviceMobile />}</Button>)}</div>
            <div className="flex items-center gap-2"><Button asChild variant="outline" size="sm" className="hidden sm:inline-flex"><a href={`/website/paginas/${page.id}/previsualizar`} target="_blank" rel="noreferrer"><Eye className="mr-2" /> Pré-visualizar</a></Button><Button variant="outline" size="sm" onClick={() => submit('save_draft')} disabled={saving}><FloppyDisk className="mr-2" /> Guardar</Button><Button size="sm" onClick={() => submit('publish')} disabled={saving}>Aplicar</Button><Button variant="ghost" size="icon" onClick={close}><X /></Button></div>
        </header>
        {saveError && <div className="shrink-0 bg-red-600 px-4 py-2 text-center text-xs font-medium text-white">{saveError}</div>}

        <div className="grid min-h-0 flex-1 grid-cols-[260px_minmax(0,1fr)_350px]">
            <aside className="flex min-h-0 flex-col border-r bg-white">
                <div className="border-b p-3"><div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Estrutura</p><Button size="sm" variant="ghost" onClick={() => { setSelectedKey(null); setSelectedItemId(null); setInspectorTab('page'); }}>Página</Button></div><div className="flex gap-2"><Select value={newBlockType} onValueChange={setNewBlockType}><SelectTrigger className="h-9"><SelectValue /></SelectTrigger><SelectContent>{blockTypes.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}</SelectContent></Select><Button size="icon" className="h-9 w-9 shrink-0" onClick={addBlock} title="Adicionar secção"><Plus /></Button></div></div>
                <ScrollArea className="flex-1"><div className="space-y-2 p-3">{data.blocks.map((block, index) => <div key={block.block_key} className="space-y-1"><button draggable onDragStart={() => setDraggedKey(block.block_key)} onDragOver={(event: DragEvent) => event.preventDefault()} onDrop={() => reorderBlock(block.block_key)} onClick={() => selectBlock(block.block_key)} className={`flex w-full items-center gap-2 rounded-lg border p-2 text-left transition ${selectedKey === block.block_key && !selectedItemId ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'bg-white hover:bg-muted/40'}`}><DotsSixVertical className="shrink-0 text-muted-foreground" /><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted text-xs font-semibold">{index + 1}</span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium">{stringValue(block.content.title) || typeLabels[block.type]}</span><span className="block truncate text-xs text-muted-foreground">{typeLabels[block.type]}</span></span>{!block.is_visible && <Eye className="text-muted-foreground" />}</button>{block.type === 'section' && <div className="ml-5 space-y-1 border-l pl-2">{sectionItems(block.content.items).map((item, itemIndex) => <button key={item.id} draggable onDragStart={(event) => { event.stopPropagation(); setDraggedKey(item.id); }} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.stopPropagation(); if (draggedKey) reorderItem(block.block_key, draggedKey, item.id); }} onClick={() => selectBlock(block.block_key, item.id)} className={`flex w-full items-center gap-2 rounded-md border px-2 py-1.5 text-left ${selectedItemId === item.id ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-400' : 'border-dashed bg-slate-50 hover:bg-slate-100'}`}><DotsSixVertical className="shrink-0 text-muted-foreground" /><span className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-white text-[10px] font-semibold">{itemIndex + 1}</span><span className="min-w-0 flex-1"><span className="block truncate text-xs font-medium">{stringValue(item.content.title) || stringValue(item.content.label) || itemTypeLabels[item.type]}</span><span className="block truncate text-[10px] text-muted-foreground">{itemTypeLabels[item.type]}</span></span>{!item.is_visible && <Eye className="text-muted-foreground" />}</button>)}</div>}</div>)}{data.blocks.length === 0 && <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"><p>Esta página ainda não tem blocos.</p><Button className="mt-3" size="sm" onClick={() => router.post(`/website/paginas/${page.id}/importar`)}>Importar conteúdo atual</Button></div>}</div></ScrollArea>
                {selectedBlock?.type === 'section' && <div className="border-t p-2"><p className="mb-2 text-[10px] font-semibold uppercase text-muted-foreground">Adicionar dentro da secção</p><div className="flex gap-2"><Select value={newItemType} onValueChange={(value) => setNewItemType(value as SectionItemType)}><SelectTrigger className="h-9"><SelectValue /></SelectTrigger><SelectContent>{Object.entries(itemTypeLabels).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent></Select><Button size="icon" className="h-9 w-9 shrink-0" onClick={addItem}><Plus /></Button></div></div>}
                {selectedBlock && <div className="flex gap-1 border-t p-2"><Button className="flex-1" size="sm" variant="outline" onClick={duplicateBlock}><Copy className="mr-2" /> Duplicar</Button><Button size="icon" variant="outline" className="h-9 w-9" onClick={removeBlock}><Trash /></Button></div>}
            </aside>

            <main className="min-w-0 overflow-auto bg-[radial-gradient(circle_at_top,#dce7ef,#aebdca)] p-5">
                <div className="mx-auto h-full w-max min-w-full"><FramePreview width={deviceWidths[device]}><ManagedPageCanvas page={livePage} news={news} events={events} partners={partners} editor={selectBlock} selectedBlockKey={selectedKey} selectedItemId={selectedItemId} /></FramePreview></div>
            </main>

            <aside className="min-h-0 border-l bg-white">
                <Tabs value={inspectorTab} onValueChange={(value) => setInspectorTab(value as InspectorTab)} className="flex h-full flex-col">
                    <TabsList className="m-2 h-auto shrink-0 flex-wrap justify-start">{selectedBlock && <><TabsTrigger value="content">Conteúdo</TabsTrigger><TabsTrigger value="design">Estilo</TabsTrigger><TabsTrigger value="behavior">Comportamento</TabsTrigger></>}<TabsTrigger value="page">Página</TabsTrigger><TabsTrigger value="media">Imagens</TabsTrigger><TabsTrigger value="history">Histórico</TabsTrigger></TabsList>
                    <ScrollArea className="min-h-0 flex-1"><div className="p-4 pt-2">
                        {selectedBlock && <TabsContent value="content" className="mt-0 space-y-4"><div className="flex items-center justify-between"><div><p className="text-sm font-semibold">{selectedItem ? itemTypeLabels[selectedItem.type] : typeLabels[selectedBlock.type]}</p><p className="text-xs text-muted-foreground">Alterações visíveis em tempo real</p></div><Switch checked={selectedItem ? selectedItem.is_visible : selectedBlock.is_visible} onCheckedChange={(is_visible) => selectedItem ? patchItem({ is_visible }) : patchBlock({ is_visible })} /></div>{selectedItem ? <SectionItemContentFields item={selectedItem} media={media} update={updateItemContent} /> : <BlockContentFields block={selectedBlock} media={media} update={updateContent} />}</TabsContent>}
                        {selectedBlock && <TabsContent value="design" className="mt-0 space-y-4">{selectedItem ? <SectionItemStyleFields item={selectedItem} pageDesign={data.design_settings} update={updateItemStyle} /> : <BlockStyleFields block={selectedBlock} pageDesign={data.design_settings} media={media} update={updateStyle} />}</TabsContent>}
                        {selectedBlock && <TabsContent value="behavior" className="mt-0 space-y-4">{selectedItem ? <SectionItemBehaviorFields item={selectedItem} update={updateItemSettings} /> : <><p className="text-sm font-semibold">Comportamento do bloco</p><Field label="Âncora" value={selectedBlock.settings.anchor_id || ''} onChange={(value) => updateSettings('anchor_id', value.toLowerCase().replace(/[^a-z0-9-]/g, '-') || null)} placeholder="ex.: equipa" /><SelectField label="Animação de entrada" value={selectedBlock.settings.animation} onChange={(value) => updateSettings('animation', value as BlockSettings['animation'])} options={[["none", "Sem animação"], ["fade", "Aparecer"], ["slide-up", "Subir"], ["zoom", "Aproximar"]]} /><RangeField label="Atraso da animação" value={selectedBlock.settings.animation_delay} min={0} max={2000} suffix=" ms" onChange={(value) => updateSettings('animation_delay', value)} /><ToggleField label="Ocultar no telemóvel" checked={selectedBlock.settings.hide_mobile} onChange={(value) => updateSettings('hide_mobile', value)} /><ToggleField label="Ocultar no computador" checked={selectedBlock.settings.hide_desktop} onChange={(value) => updateSettings('hide_desktop', value)} /><ToggleField label="Abrir ligações noutro separador" checked={selectedBlock.settings.open_links_new_tab} onChange={(value) => updateSettings('open_links_new_tab', value)} /></>}</TabsContent>}
                        <TabsContent value="page" className="mt-0 space-y-4"><p className="text-sm font-semibold">Página e identidade visual</p><Field label="Título da página" value={data.title} onChange={(title) => setData((current) => ({ ...current, title }))} /><Field label="Endereço" value={data.slug} onChange={(slug) => setData((current) => ({ ...current, slug: slug.toLowerCase().replace(/[^a-z0-9-]/g, '-') }))} /><Field label="Nome no menu" value={data.navigation_label} onChange={(navigation_label) => setData((current) => ({ ...current, navigation_label }))} /><RangeField label="Ordem no menu" value={data.sort_order} min={0} max={999} suffix="" onChange={(sort_order) => setData((current) => ({ ...current, sort_order }))} /><ToggleField label="Mostrar no menu" checked={data.show_in_navigation} onChange={(show_in_navigation) => setData((current) => ({ ...current, show_in_navigation }))} /><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Fundo, cores e tipografia</p><div className="space-y-4"><ColorField label="Fundo da página" value={data.design_settings.background_color} fallback="#ffffff" onChange={(background_color) => background_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_color } }))} /><BackgroundImageField label="Imagem de fundo da página" value={data.design_settings.background_image} media={media} onChange={(background_image) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_image } }))} /><Field label="Posição do fundo" value={data.design_settings.background_position} onChange={(background_position) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_position } }))} placeholder="center top" /><ColorField label="Texto" value={data.design_settings.text_color} fallback="#102c44" onChange={(text_color) => text_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, text_color } }))} /><ColorField label="Títulos" value={data.design_settings.heading_color} fallback="#062b54" onChange={(heading_color) => heading_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, heading_color } }))} /><ColorField label="Destaque" value={data.design_settings.accent_color} fallback="#f2e613" onChange={(accent_color) => accent_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, accent_color } }))} /><SelectField label="Tipo de letra dos títulos" value={data.design_settings.heading_font} onChange={(heading_font) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, heading_font: heading_font as PageDesign['heading_font'] } }))} options={[["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><SelectField label="Tipo de letra do texto" value={data.design_settings.body_font} onChange={(body_font) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, body_font: body_font as PageDesign['body_font'] } }))} options={[["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho base" value={data.design_settings.base_font_size} min={12} max={22} onChange={(base_font_size) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, base_font_size } }))} /><SelectField label="Largura geral" value={data.design_settings.content_width} onChange={(content_width) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, content_width: content_width as PageDesign['content_width'] } }))} options={[["compact", "Compacta"], ["standard", "Normal"], ["wide", "Larga"]]} /></div></div><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">SEO</p><div className="space-y-3"><Field label="Título SEO" value={data.meta_title} onChange={(meta_title) => setData((current) => ({ ...current, meta_title }))} /><TextField label="Descrição SEO" value={data.meta_description} onChange={(meta_description) => setData((current) => ({ ...current, meta_description }))} rows={3} /></div></div><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Publicação</p><div className="space-y-3"><Input type="datetime-local" value={scheduledFor} onChange={(event) => setScheduledFor(event.target.value)} /><Button className="w-full" variant="outline" disabled={!scheduledFor || saving} onClick={() => submit('schedule')}><ClockCounterClockwise className="mr-2" /> Agendar publicação</Button><Button className="w-full" variant="outline" onClick={() => submit('hide')}>Ocultar página</Button><Button asChild className="w-full" variant="outline"><a href={page.public_url} target="_blank" rel="noreferrer">Abrir publicada <ArrowSquareOut className="ml-2" /></a></Button></div></div></TabsContent>
                        <TabsContent value="media" className="mt-0 space-y-4"><form onSubmit={uploadImage} className="space-y-3 rounded-lg border p-3"><p className="text-sm font-semibold">Nova imagem</p><Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => upload.setData('image', event.target.files?.[0] || null)} required /><Input value={upload.data.alt_text} onChange={(event) => upload.setData('alt_text', event.target.value)} placeholder="Texto alternativo" required /><Button type="submit" className="w-full" disabled={upload.processing}><UploadSimple className="mr-2" /> Carregar</Button></form><div className="grid gap-3">{media.map((item) => <MediaCard key={item.id} item={item} />)}{media.length === 0 && <p className="text-sm text-muted-foreground">A biblioteca ainda não tem imagens.</p>}</div></TabsContent>
                        <TabsContent value="history" className="mt-0 space-y-3"><p className="text-sm font-semibold">Histórico de versões</p>{versions.map((version) => <div key={version.id} className="rounded-lg border p-3"><p className="text-sm font-medium">Versão {version.version} · {actionLabels[version.action] || version.action}</p><p className="mt-1 text-xs text-muted-foreground">{new Date(version.created_at).toLocaleString('pt-PT')} · {version.created_by || 'Sistema'}</p><Button className="mt-3 w-full" size="sm" variant="outline" onClick={() => window.confirm('Recuperar esta versão para o rascunho?') && router.post(`/website/paginas/${page.id}/versoes/${version.id}/recuperar`)}><ClockCounterClockwise className="mr-2" /> Recuperar</Button></div>)}{versions.length === 0 && <p className="text-sm text-muted-foreground">Ainda não existem versões.</p>}</TabsContent>
                    </div></ScrollArea>
                </Tabs>
            </aside>
        </div>
    </div>;
}
