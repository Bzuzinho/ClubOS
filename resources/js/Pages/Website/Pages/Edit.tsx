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
    DownloadSimple,
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
import {
    PublicEvent,
    PublicNews,
    PublicPartner,
    WebsiteDataSource,
    WebsiteDynamicData,
} from '@/Pages/PublicSite/types';

type ContentPrimitive = string | number | boolean | null;
type Item = Record<string, string | number | null>;
type ContentValue = ContentPrimitive | ContentPrimitive[] | Item[] | SectionItem[];
type BlockContent = Record<string, ContentValue>;
type Shadow = 'none' | 'soft' | 'medium' | 'strong';
type Font = 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
type SectionItemType = 'container' | 'subsection' | 'card' | 'text' | 'eyebrow' | 'heading' | 'paragraph' | 'rich_text' | 'image' | 'button' | 'link' | 'divider' | 'spacer' | 'data_collection' | 'contact_form' | 'registration_form';
type AddableItemType = 'container' | 'card' | 'eyebrow' | 'heading' | 'paragraph' | 'rich_text' | 'image' | 'button' | 'link' | 'divider' | 'spacer' | 'data_collection';

type BlockStyle = {
    background_color: string | null;
    text_color: string | null;
    heading_color: string | null;
    accent_color: string | null;
    padding_top: number;
    padding_right: number;
    padding_bottom: number;
    padding_left: number;
    margin_top: number;
    margin_right: number;
    margin_bottom: number;
    margin_left: number;
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
    padding_top: number;
    padding_right: number;
    padding_bottom: number;
    padding_left: number;
    margin_top: number;
    margin_right: number;
    margin_bottom: number;
    margin_left: number;
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
    data_card_background: string | null;
    data_card_text_color: string | null;
    data_card_border_color: string | null;
    data_card_border_width: number;
    data_card_radius: number;
    data_card_shadow: Shadow;
    data_card_padding: number;
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
    children: SectionItem[];
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
    can_import_current_website: boolean;
    import_source_label: string;
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
type Device = 'desktop' | 'tablet' | 'mobile';
type InspectorTab = 'content' | 'design' | 'behavior' | 'page' | 'media' | 'history';

const DEFAULT_DESIGN: PageDesign = {
    background_color: '#ffffff', text_color: '#102c44', heading_color: '#062b54', accent_color: '#f2e613',
    heading_font: 'inter', body_font: 'inter', base_font_size: 16, content_width: 'standard', background_image: null, background_position: 'center top',
};
const DEFAULT_STYLE: BlockStyle = {
    background_color: null, text_color: null, heading_color: null, accent_color: null,
    padding_top: 64, padding_right: 0, padding_bottom: 64, padding_left: 0,
    margin_top: 0, margin_right: 0, margin_bottom: 0, margin_left: 0,
    content_width: 'page', text_align: 'left', heading_size: 32, body_size: 14,
    border_radius: 0, shadow: 'none', card_background: null, card_border_color: null, card_radius: 15, card_shadow: 'soft', card_gap: 14,
    background_image: null, background_position: 'center', heading_font: 'inherit', body_font: 'inherit', heading_weight: 600, body_weight: 400, line_height: 1.6,
};
const DEFAULT_SETTINGS: BlockSettings = {
    anchor_id: null, animation: 'none', animation_delay: 0, hide_mobile: false, hide_desktop: false, open_links_new_tab: false,
};
const DEFAULT_ITEM_STYLE: SectionItemStyle = {
    background_color: null, text_color: null, heading_color: null, accent_color: null, border_color: null,
    border_width: 0, border_radius: 0, shadow: 'none', padding: 0,
    padding_top: 0, padding_right: 0, padding_bottom: 0, padding_left: 0,
    margin_top: 0, margin_right: 0, margin_bottom: 0, margin_left: 0,
    min_height: 0, text_align: 'left',
    heading_size: 22, body_size: 14, heading_font: 'inherit', body_font: 'inherit', heading_weight: 600, body_weight: 400,
    line_height: 1.6, column_span: 1, tablet_span: 1, mobile_span: 1, row_span: 1, image_ratio: 'auto', image_fit: 'cover',
    data_card_background: null, data_card_text_color: null, data_card_border_color: null,
    data_card_border_width: 2, data_card_radius: 15, data_card_shadow: 'soft', data_card_padding: 20,
};
const DEFAULT_ITEM_SETTINGS: SectionItemSettings = {
    animation: 'none', animation_delay: 0, hide_mobile: false, hide_desktop: false, open_link_new_tab: false,
};
const itemTypeLabels: Record<SectionItemType, string> = {
    container: 'Contentor', subsection: 'Subsecção', card: 'Card', text: 'Texto antigo', eyebrow: 'Antetítulo', heading: 'Título', paragraph: 'Parágrafo', rich_text: 'Texto longo', image: 'Imagem', button: 'Botão', link: 'Ligação', divider: 'Separador', spacer: 'Espaço', data_collection: 'Dados da aplicação', contact_form: 'Formulário de contacto', registration_form: 'Formulário de inscrição',
};
const addableItemTypeLabels: Record<AddableItemType, string> = {
    container: 'Contentor', card: 'Card', eyebrow: 'Antetítulo', heading: 'Título', paragraph: 'Parágrafo', rich_text: 'Texto longo', image: 'Imagem', button: 'Botão', link: 'Ligação', divider: 'Separador', spacer: 'Espaço', data_collection: 'Dados da aplicação',
};
const actionLabels: Record<string, string> = {
    created: 'Criação', imported: 'Importação', autosave: 'Gravação automática', save_draft: 'Rascunho', publish: 'Publicação',
    imported_current_website: 'Importação do website atual', schedule: 'Agendamento', hide: 'Ocultação', restored: 'Recuperação',
};
const deviceWidths: Record<Device, number> = { desktop: 1440, tablet: 820, mobile: 390 };
const EMPTY_DYNAMIC_DATA: WebsiteDynamicData = { news: [], events: [], partners: [], convocations: [], statistics: [] };
const DEFAULT_DATA_SOURCES: WebsiteDataSource[] = [
    { value: 'news', label: 'Notícias publicadas', description: 'Notícias públicas ordenadas por destaque e data.', emptyMessage: 'Ainda não existem notícias publicadas.', supportsImage: true, supportsLink: true, defaultLayout: 'grid' },
    { value: 'events', label: 'Próximos eventos públicos', description: 'Eventos públicos futuros confirmados.', emptyMessage: 'Ainda não existem eventos públicos futuros.', supportsImage: false, supportsLink: true, defaultLayout: 'list' },
    { value: 'convocations', label: 'Convocatórias públicas', description: 'Convocatórias de eventos públicos, sem dados pessoais dos atletas.', emptyMessage: 'Ainda não existem convocatórias públicas futuras.', supportsImage: false, supportsLink: true, defaultLayout: 'list' },
    { value: 'partners', label: 'Parceiros ativos', description: 'Parceiros ativos dentro do período contratual.', emptyMessage: 'Ainda não existem parceiros públicos ativos.', supportsImage: true, supportsLink: true, defaultLayout: 'grid' },
    { value: 'statistics', label: 'Estatísticas do clube', description: 'Indicadores agregados calculados em tempo real.', emptyMessage: 'Ainda não existem indicadores disponíveis.', supportsImage: false, supportsLink: false, defaultLayout: 'metrics' },
];

function stringValue(value: unknown): string { return typeof value === 'string' ? value : ''; }
function scalarValue(value: unknown): string | number { return typeof value === 'string' || typeof value === 'number' ? value : ''; }
function numberValue(value: unknown, fallback = 0): number { const parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
function booleanValue(value: unknown, fallback = false): boolean { return typeof value === 'boolean' ? value : fallback; }
function stringItems(value: unknown): string[] { return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []; }
function objectItems(value: unknown): Item[] { return Array.isArray(value) ? value.filter((item): item is Item => Boolean(item) && typeof item === 'object') : []; }
function elementId(value: string): string { return `element-${value.replace(/^element-/, '').replace(/[^a-zA-Z0-9-]+/g, '-')}`; }
function elementLabel(item: SectionItem): string { return stringValue(item.content.text) || stringValue(item.content.title) || stringValue(item.content.label) || itemTypeLabels[item.type]; }
function firstHeading(items: SectionItem[]): string {
    for (const item of items) {
        if (item.type === 'heading' && stringValue(item.content.text)) return stringValue(item.content.text);
        const nested = firstHeading(item.children);
        if (nested) return nested;
    }
    return '';
}
function canContainChildren(item: SectionItem | null): boolean { return Boolean(item && ['container', 'subsection', 'card'].includes(item.type)); }
function makeElement(type: SectionItemType, id: string, content: BlockContent = {}, style: Partial<SectionItemStyle> = {}, children: SectionItem[] = []): SectionItem {
    return { id: elementId(id), type, is_visible: true, content, style: { ...DEFAULT_ITEM_STYLE, ...style }, settings: { ...DEFAULT_ITEM_SETTINGS }, children };
}
function legacyItemChildren(item: SectionItem): SectionItem[] {
    const content = item.content || {};
    const children: SectionItem[] = [];
    const prefix = item.id.replace(/^element-/, '');
    if (stringValue(content.image)) children.push(makeElement('image', `${prefix}-image`, { image: content.image, image_alt: content.image_alt || '', url: '' }, { image_ratio: item.style.image_ratio, image_fit: item.style.image_fit }));
    if (stringValue(content.eyebrow)) children.push(makeElement('eyebrow', `${prefix}-eyebrow`, { text: content.eyebrow }, { heading_size: 12, heading_weight: 700 }));
    if (stringValue(content.title)) children.push(makeElement('heading', `${prefix}-title`, { text: content.title }, { heading_size: item.style.heading_size, heading_font: item.style.heading_font, heading_weight: item.style.heading_weight }));
    if (stringValue(content.text)) children.push(makeElement('rich_text', `${prefix}-body`, { text: content.text }, { body_size: item.style.body_size, body_font: item.style.body_font, body_weight: item.style.body_weight, line_height: item.style.line_height }));
    if (stringValue(content.button_label)) children.push(makeElement('link', `${prefix}-link`, { label: content.button_label, url: content.url || '' }));
    return children;
}
function sectionItems(value: unknown): SectionItem[] {
    return Array.isArray(value)
        ? value.filter((item): item is SectionItem => Boolean(item) && typeof item === 'object' && typeof item.id === 'string')
            .map((raw) => {
                const rawStyle = raw.style || {};
                const legacyPadding = numberValue(rawStyle.padding, raw.type === 'card' ? 24 : 0);
                const item: SectionItem = {
                    ...raw,
                    id: elementId(raw.id),
                    is_visible: raw.is_visible !== false,
                    content: raw.content || {},
                    style: {
                        ...DEFAULT_ITEM_STYLE,
                        ...rawStyle,
                        column_span: numberValue(rawStyle.column_span, raw.type === 'data_collection' ? 6 : 1),
                        tablet_span: numberValue(rawStyle.tablet_span, raw.type === 'data_collection' ? 4 : 1),
                        mobile_span: numberValue(rawStyle.mobile_span, raw.type === 'data_collection' ? 2 : 1),
                        padding_top: numberValue(rawStyle.padding_top, legacyPadding),
                        padding_right: numberValue(rawStyle.padding_right, legacyPadding),
                        padding_bottom: numberValue(rawStyle.padding_bottom, legacyPadding),
                        padding_left: numberValue(rawStyle.padding_left, legacyPadding),
                    },
                    settings: { ...DEFAULT_ITEM_SETTINGS, ...(raw.settings || {}) },
                    children: sectionItems(raw.children),
                };
                if (item.children.length === 0 && ['subsection', 'card', 'text'].includes(item.type)) {
                    item.children = legacyItemChildren(item);
                    if (item.type === 'text') item.type = 'container';
                    item.content = { columns_desktop: 1, columns_tablet: 1, columns_mobile: 1, gap: 12, align_items: 'start' };
                }
                return item;
            })
        : [];
}
function newKey(type: string): string { return `${type}-${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now()}`; }
function csrfToken(): string { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ''; }

function createSectionItem(type: AddableItemType): SectionItem {
    const content: Record<AddableItemType, BlockContent> = {
        container: { columns_desktop: 1, columns_tablet: 1, columns_mobile: 1, gap: 12, align_items: 'start' },
        card: { columns_desktop: 1, columns_tablet: 1, columns_mobile: 1, gap: 12, align_items: 'start' },
        eyebrow: { text: 'Novo antetítulo' },
        heading: { text: 'Novo título' },
        paragraph: { text: 'Novo parágrafo.' },
        rich_text: { text: 'Escreve aqui o conteúdo.' },
        image: { image: '', image_alt: '', url: '' },
        button: { label: 'Saber mais', url: '/' },
        link: { label: 'Nova ligação', url: '/' },
        divider: {},
        spacer: {},
        data_collection: { source: 'news', limit: 3, layout: 'grid', columns: 3, show_image: true, show_meta: true, show_description: true, show_link: true, link_label: 'Saber mais' },
    };
    const style = { ...DEFAULT_ITEM_STYLE };
    if (type === 'card') Object.assign(style, { background_color: '#edf4f7', border_color: '#afc7d3', border_width: 2, border_radius: 16, shadow: 'soft', padding: 24, padding_top: 24, padding_right: 24, padding_bottom: 24, padding_left: 24, min_height: 170 });
    if (type === 'button') Object.assign(style, { text_align: 'center', min_height: 64 });
    if (type === 'image') Object.assign(style, { image_ratio: '16:9', border_radius: 16, shadow: 'soft' });
    if (type === 'eyebrow') Object.assign(style, { heading_size: 12, heading_weight: 700 });
    if (type === 'heading') Object.assign(style, { heading_size: 32, heading_weight: 700 });
    if (type === 'spacer') Object.assign(style, { min_height: 32 });
    if (type === 'divider') Object.assign(style, { border_width: 1, border_color: '#afc7d3' });
    if (type === 'data_collection') Object.assign(style, { column_span: 6, tablet_span: 4, mobile_span: 2 });
    const children = type === 'card'
        ? [createSectionItem('eyebrow'), createSectionItem('heading'), createSectionItem('paragraph')]
        : [];
    return { id: newKey('element'), type, is_visible: true, content: content[type], style, settings: { ...DEFAULT_ITEM_SETTINGS }, children };
}

function legacyBlockElements(block: Block): SectionItem[] {
    const content = block.content || {};
    const prefix = block.block_key.replace(/[^a-zA-Z0-9-]+/g, '-');
    const full = { column_span: 6, tablet_span: 2, mobile_span: 1 };
    const elements: SectionItem[] = [];
    if (stringValue(content.eyebrow)) elements.push(makeElement('eyebrow', `${prefix}-eyebrow`, { text: content.eyebrow }, { ...full, heading_size: 12, heading_weight: 700 }));
    if (stringValue(content.title)) elements.push(makeElement('heading', `${prefix}-title`, { text: content.title }, { ...full, heading_size: 32, heading_weight: 700 }));
    const body = stringValue(content.intro) || stringValue(content.text);
    if (body) elements.push(makeElement(body.includes('\n\n') ? 'rich_text' : 'paragraph', `${prefix}-body`, { text: body }, { ...full, body_size: 15 }));
    if (block.type === 'section') elements.push(...sectionItems(content.items));
    if (block.type === 'cards') objectItems(content.items).forEach((card, index) => elements.push(makeElement('card', `${prefix}-card-${index}`, {}, { column_span: 2, tablet_span: 1, mobile_span: 1 }, legacyItemChildren(makeElement('card', `${prefix}-legacy-${index}`, card)))));
    if (block.type === 'image_text' && stringValue(content.image)) elements.unshift(makeElement('image', `${prefix}-image`, { image: content.image, image_alt: content.image_alt || '', url: '' }, { column_span: 3, tablet_span: 2, mobile_span: 1, image_ratio: '16:9' }));
    if (block.type === 'stats') objectItems(content.items).forEach((stat, index) => elements.push(makeElement('card', `${prefix}-stat-${index}`, {}, { column_span: 2, tablet_span: 1, mobile_span: 1, text_align: 'center' }, [makeElement('heading', `${prefix}-stat-${index}-value`, { text: stat.value || '' }), makeElement('paragraph', `${prefix}-stat-${index}-label`, { text: stat.label || '' })])));
    if (['news_feed', 'events_feed'].includes(block.type)) elements.push(makeElement('data_collection', `${prefix}-data`, { source: content.source || (block.type === 'events_feed' ? 'events' : 'news'), limit: content.limit || 6, layout: 'grid', columns: 3, show_image: true, show_meta: true, show_description: true, show_link: true, link_label: 'Saber mais' }, full));
    if (block.type === 'contact_form') elements.push(makeElement('contact_form', `${prefix}-form`, {}, full));
    if (block.type === 'registration_form') elements.push(makeElement('registration_form', `${prefix}-form`, {}, full));
    [['primary_label', 'primary_url'], ['button_label', 'button_url'], ['secondary_label', 'secondary_url']].forEach(([label, url], index) => { if (stringValue(content[label])) elements.push(makeElement('button', `${prefix}-action-${index}`, { label: content[label], url: content[url] || '' }, full)); });
    return elements;
}

function emptyContent(type: string): BlockContent {
    switch (type) {
        case 'hero': return { eyebrow: 'BSCN', title: 'Título principal', text: 'Texto de introdução', image: '/site-assets/bscn-club-bright.webp', image_position: 'center', primary_label: '', primary_url: '', secondary_label: '', secondary_url: '' };
        case 'section': return { eyebrow: 'Secção', title: 'Nova secção', intro: '', columns_desktop: 3, columns_tablet: 2, columns_mobile: 1, gap: 20, align_items: 'stretch', items: [createSectionItem('card')] };
        case 'cards': return { eyebrow: 'Destaques', title: 'Título da secção', intro: '', columns: 3, items: [{ label: '', title: 'Novo card', text: 'Descrição', url: '' }] };
        case 'image_text': return { eyebrow: '', title: 'Título da secção', text: '', image: '/site-assets/bscn-club-bright.webp', image_alt: '', image_position: 'center', image_side: 'left', items: [], button_label: '', button_url: '' };
        case 'stats': return { items: [{ value: '1', label: 'Indicador' }] };
        case 'cta': return { eyebrow: '', title: 'Próximo passo', text: '', button_label: 'Saber mais', button_url: '/', secondary_label: '', secondary_url: '' };
        case 'news_feed': return { eyebrow: 'Atualidade', title: 'Notícias', intro: '', source: 'news', limit: 6 };
        case 'events_feed': return { eyebrow: 'Agenda', title: 'Próximos eventos', intro: '', source: 'events', limit: 12 };
        case 'contact_form': return { eyebrow: 'Pedido de contacto', title: 'Fala connosco', text: '', steps: ['Envio do pedido', 'Contacto do clube'] };
        case 'registration_form': return { eyebrow: 'Inscrição', title: 'Registo de atleta', text: '', steps: ['Dados do atleta', 'Validação pelo clube'] };
        default: return { eyebrow: '', title: 'Título da secção', text: 'Conteúdo da secção.' };
    }
}

function normaliseBlock(block: Block): Block {
    const sourceContent = block.type === 'news_feed' ? { source: 'news', ...block.content } : block.type === 'events_feed' ? { source: 'events', ...block.content } : block.content;
    const content = {
        ...sourceContent,
        columns_desktop: numberValue(sourceContent.columns_desktop, block.type === 'section' ? 3 : 6),
        columns_tablet: numberValue(sourceContent.columns_tablet, 2),
        columns_mobile: numberValue(sourceContent.columns_mobile, 1),
        gap: numberValue(sourceContent.gap, 20),
        align_items: stringValue(sourceContent.align_items) || 'stretch',
        elements: Array.isArray(sourceContent.elements) ? sectionItems(sourceContent.elements) : legacyBlockElements(block),
    };
    const style = { ...defaultStyleFor(block.type), ...(block.style || {}) };
    if (block.type === 'hero' && !style.background_image && stringValue(block.content.image)) {
        style.background_image = stringValue(block.content.image);
        style.background_position = stringValue(block.content.image_position) || 'center';
    }
    return { ...block, content, style, settings: { ...DEFAULT_SETTINGS, ...(block.settings || {}) } };
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
    if (Array.isArray(content.elements)) return <div className="space-y-3"><div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">Este bloco está desmontado em elementos independentes. Seleciona um elemento na árvore ou diretamente na página para editar apenas esse conteúdo.</div><div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Organização do bloco</p><div className="space-y-3"><RangeField label="Colunas no computador" value={numberValue(content.columns_desktop, 6)} onChange={(value) => update('columns_desktop', value)} min={1} max={6} suffix="" /><RangeField label="Colunas no tablet" value={numberValue(content.columns_tablet, 2)} onChange={(value) => update('columns_tablet', value)} min={1} max={4} suffix="" /><RangeField label="Colunas no telemóvel" value={numberValue(content.columns_mobile, 1)} onChange={(value) => update('columns_mobile', value)} min={1} max={2} suffix="" /><RangeField label="Espaço entre elementos" value={numberValue(content.gap, 20)} onChange={(value) => update('gap', value)} min={0} max={80} /><SelectField label="Alinhamento vertical" value={stringValue(content.align_items) || 'stretch'} onChange={(value) => update('align_items', value)} options={[["stretch", "Preencher altura"], ["start", "Topo"], ["center", "Centro"], ["end", "Fundo"]]} /></div></div></div>;
    const headings = <><Field label="Antetítulo" value={stringValue(content.eyebrow)} onChange={(value) => update('eyebrow', value)} /><Field label="Título" value={stringValue(content.title)} onChange={(value) => update('title', value)} /></>;
    switch (block.type) {
        case 'hero': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><Field label="Botão principal" value={stringValue(content.primary_label)} onChange={(value) => update('primary_label', value)} /><Field label="Ligação principal" value={stringValue(content.primary_url)} onChange={(value) => update('primary_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div>;
        case 'section': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={3} /><div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Organização</p><div className="space-y-3"><RangeField label="Colunas no computador" value={numberValue(content.columns_desktop, 3)} onChange={(value) => update('columns_desktop', value)} min={1} max={6} suffix="" /><RangeField label="Colunas no tablet" value={numberValue(content.columns_tablet, 2)} onChange={(value) => update('columns_tablet', value)} min={1} max={4} suffix="" /><RangeField label="Colunas no telemóvel" value={numberValue(content.columns_mobile, 1)} onChange={(value) => update('columns_mobile', value)} min={1} max={2} suffix="" /><RangeField label="Espaço entre elementos" value={numberValue(content.gap, 20)} onChange={(value) => update('gap', value)} min={0} max={80} /><SelectField label="Alinhamento vertical" value={stringValue(content.align_items) || 'stretch'} onChange={(value) => update('align_items', value)} options={[["stretch", "Preencher altura"], ["start", "Topo"], ["center", "Centro"], ["end", "Fundo"]]} /></div></div><p className="text-xs text-muted-foreground">Seleciona um elemento na árvore da esquerda para editar o respetivo conteúdo e posição.</p></div>;
        case 'rich_text': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={10} /></div>;
        case 'cards': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><RangeField label="Colunas" value={numberValue(content.columns, 3)} onChange={(value) => update('columns', value)} min={1} max={4} suffix="" /><ItemsEditor type={block.type} content={content} update={update} /></div>;
        case 'image_text': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><ImageFields content={content} media={media} update={update} /><SelectField label="Lado da imagem" value={stringValue(content.image_side) || 'left'} onChange={(value) => update('image_side', value)} options={[["left", "Esquerda"], ["right", "Direita"]]} /><Field label="Texto do botão" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /><StringListEditor content={content} update={update} /></div>;
        case 'stats': return <ItemsEditor type={block.type} content={content} update={update} />;
        case 'cta': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><Field label="Botão principal" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação principal" value={stringValue(content.button_url)} onChange={(value) => update('button_url', value)} /><Field label="Botão secundário" value={stringValue(content.secondary_label)} onChange={(value) => update('secondary_label', value)} /><Field label="Ligação secundária" value={stringValue(content.secondary_url)} onChange={(value) => update('secondary_url', value)} /></div>;
        case 'news_feed': case 'events_feed': return <div className="space-y-3">{headings}<TextField label="Introdução" value={stringValue(content.intro)} onChange={(value) => update('intro', value)} rows={2} /><SelectField label="Origem dos dados" value={stringValue(content.source) || (block.type === 'events_feed' ? 'events' : 'news')} onChange={(value) => update('source', value)} options={[["news", "Notícias publicadas"], ["events", "Eventos públicos"], ["convocations", "Convocatórias públicas"], ["partners", "Parceiros ativos"], ["statistics", "Estatísticas do clube"]]} /><RangeField label="Número máximo" value={numberValue(content.limit, 6)} onChange={(value) => update('limit', value)} min={1} max={60} suffix="" /><p className="text-xs text-muted-foreground">Ao mudar a origem, o bloco adapta automaticamente os campos e a apresentação aos dados escolhidos.</p></div>;
        case 'contact_form': case 'registration_form': return <div className="space-y-3">{headings}<TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} /><StringListEditor content={content} update={update} /></div>;
        default: return null;
    }
}

function SectionItemContentFields({ item, media, dataSources, dynamicData, update }: { item: SectionItem; media: MediaItem[]; dataSources: WebsiteDataSource[]; dynamicData: WebsiteDynamicData; update: (key: string, value: ContentValue) => void }) {
    const content = item.content;
    if (['container', 'subsection', 'card'].includes(item.type)) return <div className="space-y-3"><div className="rounded-lg border border-violet-200 bg-violet-50 p-3 text-xs text-violet-900">Este elemento é um contentor. Adiciona textos, imagens, ligações ou outros contentores dentro dele através da árvore da esquerda.</div><RangeField label="Colunas internas no computador" value={numberValue(content.columns_desktop, 1)} onChange={(value) => update('columns_desktop', value)} min={1} max={6} suffix="" /><RangeField label="Colunas internas no tablet" value={numberValue(content.columns_tablet, 1)} onChange={(value) => update('columns_tablet', value)} min={1} max={4} suffix="" /><RangeField label="Colunas internas no telemóvel" value={numberValue(content.columns_mobile, 1)} onChange={(value) => update('columns_mobile', value)} min={1} max={2} suffix="" /><RangeField label="Espaço entre elementos internos" value={numberValue(content.gap, 12)} onChange={(value) => update('gap', value)} min={0} max={80} /><SelectField label="Alinhamento interno" value={stringValue(content.align_items) || 'start'} onChange={(value) => update('align_items', value)} options={[["stretch", "Preencher altura"], ["start", "Topo"], ["center", "Centro"], ["end", "Fundo"]]} /></div>;
    if (item.type === 'eyebrow') return <Field label="Antetítulo" value={stringValue(content.text)} onChange={(value) => update('text', value)} />;
    if (item.type === 'heading') return <TextField label="Título" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={3} />;
    if (item.type === 'paragraph') return <TextField label="Parágrafo" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={5} />;
    if (item.type === 'rich_text') return <TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={12} />;
    if (item.type === 'image') return <div className="space-y-3"><ImageFields content={content} media={media} update={update} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></div>;
    if (item.type === 'button') return <div className="space-y-3"><Field label="Texto do botão" value={stringValue(content.label)} onChange={(value) => update('label', value)} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></div>;
    if (item.type === 'link') return <div className="space-y-3"><Field label="Texto da ligação" value={stringValue(content.label)} onChange={(value) => update('label', value)} /><Field label="Destino" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></div>;
    if (item.type === 'divider') return <p className="text-xs text-muted-foreground">O separador não tem texto. Define a cor e espessura no separador Estilo.</p>;
    if (item.type === 'spacer') return <p className="text-xs text-muted-foreground">Define a altura deste espaço no campo Altura mínima, no separador Estilo.</p>;
    if (['contact_form', 'registration_form'].includes(item.type)) return <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Este formulário está ligado ao fluxo seguro da aplicação. Podes mover e estilizar o elemento, mas os respetivos campos e validações mantêm-se protegidos.</div>;
    if (item.type === 'data_collection') {
        const source = (stringValue(content.source) || 'news') as keyof WebsiteDynamicData;
        const sourceDefinition = dataSources.find((candidate) => candidate.value === source) || dataSources[0];
        const available = dynamicData[source]?.length || 0;
        const layout = source === 'statistics' ? 'metrics' : stringValue(content.layout) || sourceDefinition?.defaultLayout || 'grid';

        return <div className="space-y-3">
        <SelectField label="Origem dos dados" value={source} onChange={(value) => update('source', value)} options={dataSources.map((candidate): [string, string] => [candidate.value, candidate.label])} />
        {sourceDefinition && <div className="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-950"><p className="font-semibold">{available} {available === 1 ? 'registo disponível' : 'registos disponíveis'}</p><p className="mt-1 leading-relaxed">{sourceDefinition.description}</p></div>}
        <RangeField label="Número de registos" value={numberValue(content.limit, 3)} onChange={(value) => update('limit', value)} min={1} max={30} suffix="" />
        <SelectField label="Apresentação" value={layout} onChange={(value) => update('layout', value)} options={source === 'statistics' ? [["metrics", "Indicadores numéricos"]] : [["grid", "Grelha de cards"], ["list", "Lista"]]} />
        <RangeField label="Colunas dos dados" value={numberValue(content.columns, 3)} onChange={(value) => update('columns', value)} min={1} max={4} suffix="" />
        <div className="space-y-2 border-t pt-3"><p className="text-xs font-semibold uppercase text-muted-foreground">Campos visíveis</p>{sourceDefinition?.supportsImage && <ToggleField label="Imagem ou logótipo" checked={booleanValue(content.show_image, true)} onChange={(value) => update('show_image', value)} />}<ToggleField label={source === 'statistics' ? 'Legenda do indicador' : 'Data, categoria ou tipo'} checked={booleanValue(content.show_meta, true)} onChange={(value) => update('show_meta', value)} /><ToggleField label="Resumo ou descrição" checked={booleanValue(content.show_description, true)} onChange={(value) => update('show_description', value)} />{sourceDefinition?.supportsLink && <><ToggleField label="Ligação" checked={booleanValue(content.show_link, true)} onChange={(value) => update('show_link', value)} /><Field label="Texto da ligação" value={stringValue(content.link_label) || 'Saber mais'} onChange={(value) => update('link_label', value)} /></>}</div>
    </div>;
    }
    return <div className="space-y-3"><Field label="Antetítulo" value={stringValue(content.eyebrow)} onChange={(value) => update('eyebrow', value)} /><Field label="Título" value={stringValue(content.title)} onChange={(value) => update('title', value)} /><TextField label="Texto" value={stringValue(content.text)} onChange={(value) => update('text', value)} rows={6} />{item.type !== 'text' && <><ImageFields content={content} media={media} update={update} /><Field label="Texto da ligação" value={stringValue(content.button_label)} onChange={(value) => update('button_label', value)} /><Field label="Ligação" value={stringValue(content.url)} onChange={(value) => update('url', value)} /></>}</div>;
}

function SectionItemStyleFields({ item, pageDesign, update }: { item: SectionItem; pageDesign: PageDesign; update: <K extends keyof SectionItemStyle>(key: K, value: SectionItemStyle[K]) => void }) {
    const style = item.style;
    const isContainer = ['container', 'subsection', 'card', 'contact_form', 'registration_form'].includes(item.type);
    const isHeading = ['eyebrow', 'heading'].includes(item.type);
    const isBodyText = ['paragraph', 'rich_text', 'text', 'link', 'button'].includes(item.type);
    const hasTypography = isHeading || isBodyText || isContainer || item.type === 'data_collection';
    const hasSurface = isContainer || ['button', 'data_collection'].includes(item.type);
    const hasBorder = hasSurface || ['image', 'divider'].includes(item.type);
    const hasPadding = hasSurface || isHeading || isBodyText;

    return <div className="space-y-4">
        {(hasSurface || hasBorder || hasTypography) && <div><p className="mb-3 text-sm font-semibold">Aparência aplicável</p><div className="space-y-4">
            {hasSurface && <ColorField label="Fundo do elemento" value={style.background_color} fallback="#edf4f7" onChange={(value) => update('background_color', value)} />}
            {(isBodyText || isContainer || item.type === 'data_collection') && <ColorField label="Cor do texto" value={style.text_color} fallback={pageDesign.text_color} onChange={(value) => update('text_color', value)} />}
            {(isHeading || isContainer || item.type === 'data_collection') && <ColorField label="Cor do título" value={style.heading_color} fallback={pageDesign.heading_color} onChange={(value) => update('heading_color', value)} />}
            {(item.type === 'eyebrow' || isContainer || item.type === 'data_collection') && <ColorField label="Cor de destaque" value={style.accent_color} fallback={pageDesign.accent_color} onChange={(value) => update('accent_color', value)} />}
            {hasBorder && <><ColorField label="Cor do rebordo" value={style.border_color} fallback="#afc7d3" onChange={(value) => update('border_color', value)} /><RangeField label="Espessura do rebordo" value={style.border_width} min={0} max={8} onChange={(value) => update('border_width', value)} /></>}
            {item.type !== 'divider' && (hasSurface || item.type === 'image') && <><RangeField label="Cantos" value={style.border_radius} min={0} max={48} onChange={(value) => update('border_radius', value)} /><SelectField label="Sombra" value={style.shadow} onChange={(value) => update('shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></>}
        </div></div>}
        {hasTypography && <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Tipografia</p><div className="space-y-4">
            {(isHeading || isContainer || item.type === 'data_collection') && <><SelectField label="Tipo de letra do título" value={style.heading_font} onChange={(value) => update('heading_font', value as Font)} options={[["inherit", "Herdar da secção"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho do título" value={style.heading_size} min={10} max={72} onChange={(value) => update('heading_size', value)} /><RangeField label="Peso do título" value={style.heading_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('heading_weight', value)} /></>}
            {(isBodyText || isContainer || item.type === 'data_collection') && <><SelectField label="Tipo de letra do texto" value={style.body_font} onChange={(value) => update('body_font', value as Font)} options={[["inherit", "Herdar da secção"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho do texto" value={style.body_size} min={10} max={30} onChange={(value) => update('body_size', value)} /><RangeField label="Peso do texto" value={style.body_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('body_weight', value)} /></>}
            <RangeField label="Altura da linha" value={style.line_height} min={1} max={2.4} step={0.1} suffix="" onChange={(value) => update('line_height', value)} /><SelectField label="Alinhamento" value={style.text_align} onChange={(value) => update('text_align', value as SectionItemStyle['text_align'])} options={[["left", "Esquerda"], ["center", "Centro"], ["right", "Direita"]]} />
        </div></div>}
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Dimensão e espaçamento</p><div className="space-y-4"><RangeField label="Largura no computador" value={style.column_span} min={1} max={6} suffix=" col." onChange={(value) => update('column_span', value)} /><RangeField label="Largura no tablet" value={style.tablet_span} min={1} max={4} suffix=" col." onChange={(value) => update('tablet_span', value)} /><RangeField label="Largura no telemóvel" value={style.mobile_span} min={1} max={2} suffix=" col." onChange={(value) => update('mobile_span', value)} /><RangeField label="Altura na grelha" value={style.row_span} min={1} max={4} suffix=" linhas" onChange={(value) => update('row_span', value)} /><RangeField label="Altura mínima" value={style.min_height} min={0} max={600} onChange={(value) => update('min_height', value)} />
            <div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Margem exterior</p><div className="space-y-3"><RangeField label="Superior" value={style.margin_top} min={0} max={160} onChange={(value) => update('margin_top', value)} /><RangeField label="Direita" value={style.margin_right} min={0} max={160} onChange={(value) => update('margin_right', value)} /><RangeField label="Inferior" value={style.margin_bottom} min={0} max={160} onChange={(value) => update('margin_bottom', value)} /><RangeField label="Esquerda" value={style.margin_left} min={0} max={160} onChange={(value) => update('margin_left', value)} /></div></div>
            {hasPadding && <div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Espaço interior</p><div className="space-y-3"><RangeField label="Superior" value={style.padding_top} min={0} max={120} onChange={(value) => update('padding_top', value)} /><RangeField label="Direita" value={style.padding_right} min={0} max={120} onChange={(value) => update('padding_right', value)} /><RangeField label="Inferior" value={style.padding_bottom} min={0} max={120} onChange={(value) => update('padding_bottom', value)} /><RangeField label="Esquerda" value={style.padding_left} min={0} max={120} onChange={(value) => update('padding_left', value)} /></div></div>}
            {(item.type === 'image' || stringValue(item.content.image)) && <><SelectField label="Proporção da imagem" value={style.image_ratio} onChange={(value) => update('image_ratio', value as SectionItemStyle['image_ratio'])} options={[["auto", "Original"], ["1:1", "Quadrada"], ["4:3", "4:3"], ["16:9", "16:9"], ["21:9", "Panorâmica"]]} /><SelectField label="Ajuste da imagem" value={style.image_fit} onChange={(value) => update('image_fit', value as SectionItemStyle['image_fit'])} options={[["cover", "Preencher"], ["contain", "Conter"]]} /></>}
        </div></div>
        {item.type === 'data_collection' && <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Cards gerados pelos dados</p><div className="space-y-4"><ColorField label="Fundo dos registos" value={style.data_card_background} fallback="#edf4f7" onChange={(value) => update('data_card_background', value)} /><ColorField label="Texto dos registos" value={style.data_card_text_color} fallback={pageDesign.text_color} onChange={(value) => update('data_card_text_color', value)} /><ColorField label="Rebordo dos registos" value={style.data_card_border_color} fallback="#afc7d3" onChange={(value) => update('data_card_border_color', value)} /><RangeField label="Espessura do rebordo" value={style.data_card_border_width} min={0} max={8} onChange={(value) => update('data_card_border_width', value)} /><RangeField label="Cantos dos registos" value={style.data_card_radius} min={0} max={48} onChange={(value) => update('data_card_radius', value)} /><RangeField label="Espaço interior dos registos" value={style.data_card_padding} min={0} max={80} onChange={(value) => update('data_card_padding', value)} /><SelectField label="Sombra dos registos" value={style.data_card_shadow} onChange={(value) => update('data_card_shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>}
    </div>;
}

function SectionItemBehaviorFields({ item, update }: { item: SectionItem; update: <K extends keyof SectionItemSettings>(key: K, value: SectionItemSettings[K]) => void }) {
    const hasLink = ['image', 'button', 'link', 'data_collection'].includes(item.type);
    return <div className="space-y-4"><p className="text-sm font-semibold">Comportamento do elemento</p><SelectField label="Animação de entrada" value={item.settings.animation} onChange={(value) => update('animation', value as SectionItemSettings['animation'])} options={[["none", "Sem animação"], ["fade", "Aparecer"], ["slide-up", "Subir"], ["zoom", "Aproximar"]]} />{item.settings.animation !== 'none' && <RangeField label="Atraso da animação" value={item.settings.animation_delay} min={0} max={2000} suffix=" ms" onChange={(value) => update('animation_delay', value)} />}<ToggleField label="Ocultar no telemóvel" checked={item.settings.hide_mobile} onChange={(value) => update('hide_mobile', value)} /><ToggleField label="Ocultar no computador" checked={item.settings.hide_desktop} onChange={(value) => update('hide_desktop', value)} />{hasLink && <ToggleField label="Abrir ligações noutro separador" checked={item.settings.open_link_new_tab} onChange={(value) => update('open_link_new_tab', value)} />}</div>;
}

function BlockStyleFields({ block, pageDesign, media, update }: { block: Block; pageDesign: PageDesign; media: MediaItem[]; update: <K extends keyof BlockStyle>(key: K, value: BlockStyle[K]) => void }) {
    const style = block.style;
    const hasIndependentElements = Array.isArray(block.content.elements);
    return <div className="space-y-4">
        <div><p className="mb-3 text-sm font-semibold">Fundo e cores</p><div className="space-y-4"><ColorField label="Fundo do bloco" value={style.background_color} fallback={pageDesign.background_color} onChange={(value) => update('background_color', value)} /><BackgroundImageField label="Imagem de fundo" value={style.background_image} media={media} onChange={(value) => update('background_image', value)} /><Field label="Posição do fundo" value={style.background_position} onChange={(value) => update('background_position', value)} placeholder="center 50%" /><ColorField label="Cor do texto" value={style.text_color} fallback={pageDesign.text_color} onChange={(value) => update('text_color', value)} /><ColorField label="Cor dos títulos" value={style.heading_color} fallback={pageDesign.heading_color} onChange={(value) => update('heading_color', value)} /><ColorField label="Destaque" value={style.accent_color} fallback={pageDesign.accent_color} onChange={(value) => update('accent_color', value)} /></div></div>
        {!hasIndependentElements && <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Texto e tipografia do bloco antigo</p><div className="space-y-4"><SelectField label="Tipo de letra dos títulos" value={style.heading_font} onChange={(value) => update('heading_font', value as Font)} options={[["inherit", "Herdar da página"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho dos títulos" value={style.heading_size} min={18} max={88} onChange={(value) => update('heading_size', value)} /><RangeField label="Peso dos títulos" value={style.heading_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('heading_weight', value)} /><SelectField label="Tipo de letra do texto" value={style.body_font} onChange={(value) => update('body_font', value as Font)} options={[["inherit", "Herdar da página"], ["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho do texto" value={style.body_size} min={10} max={30} onChange={(value) => update('body_size', value)} /><RangeField label="Peso do texto" value={style.body_weight} min={300} max={800} step={100} suffix="" onChange={(value) => update('body_weight', value)} /><RangeField label="Altura da linha" value={style.line_height} min={1} max={2.4} step={0.1} suffix="" onChange={(value) => update('line_height', value)} /><SelectField label="Alinhamento" value={style.text_align} onChange={(value) => update('text_align', value as BlockStyle['text_align'])} options={[["left", "Esquerda"], ["center", "Centro"], ["right", "Direita"]]} /></div></div>}
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Espaço e disposição</p><div className="space-y-4"><SelectField label="Largura" value={style.content_width} onChange={(value) => update('content_width', value as BlockStyle['content_width'])} options={[["page", "Da página"], ["compact", "Compacta"], ["wide", "Larga"], ["full", "Ecrã inteiro"]]} /><div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Margem exterior do bloco</p><div className="space-y-3"><RangeField label="Superior" value={style.margin_top} min={0} max={200} onChange={(value) => update('margin_top', value)} /><RangeField label="Direita" value={style.margin_right} min={0} max={200} onChange={(value) => update('margin_right', value)} /><RangeField label="Inferior" value={style.margin_bottom} min={0} max={200} onChange={(value) => update('margin_bottom', value)} /><RangeField label="Esquerda" value={style.margin_left} min={0} max={200} onChange={(value) => update('margin_left', value)} /></div></div><div className="rounded-lg border bg-muted/20 p-3"><p className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Espaço interior do bloco</p><div className="space-y-3"><RangeField label="Superior" value={style.padding_top} min={0} max={180} onChange={(value) => update('padding_top', value)} /><RangeField label="Direita" value={style.padding_right} min={0} max={180} onChange={(value) => update('padding_right', value)} /><RangeField label="Inferior" value={style.padding_bottom} min={0} max={180} onChange={(value) => update('padding_bottom', value)} /><RangeField label="Esquerda" value={style.padding_left} min={0} max={180} onChange={(value) => update('padding_left', value)} /></div></div><RangeField label="Cantos do bloco" value={style.border_radius} min={0} max={48} onChange={(value) => update('border_radius', value)} /><SelectField label="Sombra do bloco" value={style.shadow} onChange={(value) => update('shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>
        <div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Cards e dados</p><div className="space-y-4"><ColorField label="Fundo dos cards" value={style.card_background} fallback="#edf4f7" onChange={(value) => update('card_background', value)} /><ColorField label="Rebordo dos cards" value={style.card_border_color} fallback="#afc7d3" onChange={(value) => update('card_border_color', value)} /><RangeField label="Cantos dos cards" value={style.card_radius} min={0} max={48} onChange={(value) => update('card_radius', value)} /><RangeField label="Espaço entre cards" value={style.card_gap} min={0} max={64} onChange={(value) => update('card_gap', value)} /><SelectField label="Sombra dos cards" value={style.card_shadow} onChange={(value) => update('card_shadow', value as Shadow)} options={[["none", "Sem sombra"], ["soft", "Suave"], ["medium", "Média"], ["strong", "Forte"]]} /></div></div>
    </div>;
}

function findElement(items: SectionItem[], id: string | null): SectionItem | null {
    if (!id) return null;
    for (const item of items) {
        if (item.id === id) return item;
        const child = findElement(item.children, id);
        if (child) return child;
    }
    return null;
}

function updateElementTree(items: SectionItem[], id: string, update: (item: SectionItem) => SectionItem): SectionItem[] {
    return items.map((item) => item.id === id ? update(item) : { ...item, children: updateElementTree(item.children, id, update) });
}

function removeElementTree(items: SectionItem[], id: string): SectionItem[] {
    return items.filter((item) => item.id !== id).map((item) => ({ ...item, children: removeElementTree(item.children, id) }));
}

function cloneElementWithNewIds(item: SectionItem): SectionItem {
    return { ...structuredClone(item), id: newKey('element'), children: item.children.map(cloneElementWithNewIds) };
}

function duplicateElementTree(items: SectionItem[], id: string): { items: SectionItem[]; copy: SectionItem | null } {
    let copy: SectionItem | null = null;
    const visit = (siblings: SectionItem[]): SectionItem[] => {
        const next: SectionItem[] = [];
        for (const item of siblings) {
            next.push({ ...item, children: visit(item.children) });
            if (item.id === id) {
                copy = cloneElementWithNewIds(item);
                next.push(copy);
            }
        }
        return next;
    };
    return { items: visit(items), copy };
}

function reorderElementTree(items: SectionItem[], sourceId: string, targetId: string): { items: SectionItem[]; changed: boolean } {
    const source = findElement(items, sourceId);
    if (!source || sourceId === targetId || findElement(source.children, targetId)) return { items, changed: false };
    const withoutSource = removeElementTree(items, sourceId);
    let inserted = false;
    const insert = (siblings: SectionItem[]): SectionItem[] => {
        const next: SectionItem[] = [];
        for (const item of siblings) {
            if (item.id === targetId) {
                next.push(source);
                inserted = true;
            }
            next.push({ ...item, children: insert(item.children) });
        }
        return next;
    };
    const reordered = insert(withoutSource);
    return inserted ? { items: reordered, changed: true } : { items, changed: false };
}

function ElementTree({ items, blockKey, selectedItemId, draggedKey, onSelect, onDrag, onDrop, depth = 0 }: { items: SectionItem[]; blockKey: string; selectedItemId: string | null; draggedKey: string | null; onSelect: (blockKey: string, itemId: string) => void; onDrag: (id: string) => void; onDrop: (blockKey: string, sourceId: string, targetId: string) => void; depth?: number }) {
    return <div className={depth === 0 ? 'ml-5 space-y-1 border-l pl-2' : 'ml-4 mt-1 space-y-1 border-l border-dashed pl-2'}>{items.map((item, index) => <div key={item.id}><button draggable onDragStart={(event) => { event.stopPropagation(); onDrag(item.id); }} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.stopPropagation(); if (draggedKey) onDrop(blockKey, draggedKey, item.id); }} onClick={() => onSelect(blockKey, item.id)} className={`flex w-full items-center gap-2 rounded-md border px-2 py-1.5 text-left ${selectedItemId === item.id ? 'border-violet-500 bg-violet-50 ring-1 ring-violet-400' : 'border-dashed bg-slate-50 hover:bg-slate-100'}`}><DotsSixVertical className="shrink-0 text-muted-foreground" /><span className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-white text-[10px] font-semibold">{index + 1}</span><span className="min-w-0 flex-1"><span className="block truncate text-xs font-medium">{elementLabel(item)}</span><span className="block truncate text-[10px] text-muted-foreground">{itemTypeLabels[item.type]}</span></span>{!item.is_visible && <Eye className="text-muted-foreground" />}</button>{item.children.length > 0 && <ElementTree items={item.children} blockKey={blockKey} selectedItemId={selectedItemId} draggedKey={draggedKey} onSelect={onSelect} onDrag={onDrag} onDrop={onDrop} depth={depth + 1} />}</div>)}</div>;
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

export default function WebsitePageEdit({ page, media, blockTypes, news = [], events = [], partners = [], dynamicData, dataSources = DEFAULT_DATA_SOURCES }: { page: Page; media: MediaItem[]; blockTypes: BlockType[]; news?: PublicNews[]; events?: PublicEvent[]; partners?: PublicPartner[]; dynamicData?: WebsiteDynamicData; dataSources?: WebsiteDataSource[] }) {
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
    const [newItemType, setNewItemType] = useState<AddableItemType>('card');
    const [scheduledFor, setScheduledFor] = useState(page.scheduled_for?.slice(0, 16) || '');
    const [versions, setVersions] = useState(page.versions);
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [draggedKey, setDraggedKey] = useState<string | null>(null);
    const lastSaved = useRef(JSON.stringify(initialData));
    const manualSaving = useRef(false);
    const autosaveController = useRef<AbortController | null>(null);
    const upload = useForm<{ image: File | null; alt_text: string }>({ image: null, alt_text: '' });
    const resolvedDynamicData: WebsiteDynamicData = dynamicData || { ...EMPTY_DYNAMIC_DATA, news, events, partners };
    const resolvedDataSources = dataSources.length > 0 ? dataSources : DEFAULT_DATA_SOURCES;
    const typeLabels = useMemo(() => Object.fromEntries(blockTypes.map((item) => [item.value, item.label])), [blockTypes]);
    const serialised = useMemo(() => JSON.stringify(data), [data]);
    const dirty = serialised !== lastSaved.current;
    const selectedIndex = data.blocks.findIndex((block) => block.block_key === selectedKey);
    const selectedBlock = selectedIndex >= 0 ? data.blocks[selectedIndex] : null;
    const selectedItems = selectedBlock ? sectionItems(selectedBlock.content.elements) : [];
    const selectedItem = findElement(selectedItems, selectedItemId);

    const patchBlock = (changes: Partial<Block>) => setData((current) => ({ ...current, blocks: current.blocks.map((block) => block.block_key === selectedKey ? { ...block, ...changes } : block) }));
    const updateContent = (key: string, value: ContentValue) => selectedBlock && patchBlock({ content: { ...selectedBlock.content, [key]: value } });
    const updateStyle = <K extends keyof BlockStyle>(key: K, value: BlockStyle[K]) => selectedBlock && patchBlock({ style: { ...selectedBlock.style, [key]: value } });
    const updateSettings = <K extends keyof BlockSettings>(key: K, value: BlockSettings[K]) => selectedBlock && patchBlock({ settings: { ...selectedBlock.settings, [key]: value } });
    const updateItems = (items: SectionItem[]) => selectedBlock && patchBlock({ content: { ...selectedBlock.content, elements: items } });
    const patchItem = (changes: Partial<SectionItem>) => selectedItem && updateItems(updateElementTree(selectedItems, selectedItem.id, (item) => ({ ...item, ...changes })));
    const updateItemContent = (key: string, value: ContentValue) => selectedItem && patchItem({ content: { ...selectedItem.content, [key]: value } });
    const updateItemStyle = <K extends keyof SectionItemStyle>(key: K, value: SectionItemStyle[K]) => selectedItem && patchItem({ style: { ...selectedItem.style, [key]: value } });
    const updateItemSettings = <K extends keyof SectionItemSettings>(key: K, value: SectionItemSettings[K]) => selectedItem && patchItem({ settings: { ...selectedItem.settings, [key]: value } });
    const selectBlock = (key: string, itemId?: string) => { setSelectedKey(key); setSelectedItemId(itemId || null); setInspectorTab('content'); };
    const reorderBlock = (targetKey: string) => {
        if (!draggedKey || draggedKey === targetKey) return;
        setData((current) => { const blocks = [...current.blocks]; const source = blocks.findIndex((block) => block.block_key === draggedKey); const target = blocks.findIndex((block) => block.block_key === targetKey); if (source < 0 || target < 0) return current; const [moved] = blocks.splice(source, 1); blocks.splice(target, 0, moved); return { ...current, blocks }; });
    };
    const addBlock = () => {
        const block = normaliseBlock({ block_key: newKey(newBlockType), type: newBlockType, is_visible: true, content: emptyContent(newBlockType), style: defaultStyleFor(newBlockType), settings: { ...DEFAULT_SETTINGS } });
        setData((current) => { const blocks = [...current.blocks]; blocks.splice(Math.max(0, selectedIndex + 1), 0, block); return { ...current, blocks }; });
        setSelectedKey(block.block_key); setSelectedItemId(null); setInspectorTab('content');
    };
    const addItem = () => {
        if (!selectedBlock) return;
        const item = createSectionItem(newItemType);
        if (canContainChildren(selectedItem)) {
            updateItems(updateElementTree(selectedItems, selectedItem!.id, (current) => ({ ...current, children: [...current.children, item] })));
        } else {
            updateItems([...selectedItems, item]);
        }
        setSelectedItemId(item.id);
        setInspectorTab('content');
    };
    const reorderItem = (blockKey: string, sourceId: string, targetId: string) => {
        if (sourceId === targetId) return;
        setData((current) => ({ ...current, blocks: current.blocks.map((block) => {
            if (block.block_key !== blockKey) return block;
            const result = reorderElementTree(sectionItems(block.content.elements), sourceId, targetId);
            return result.changed ? { ...block, content: { ...block.content, elements: result.items } } : block;
        }) }));
    };
    const duplicateBlock = () => {
        if (selectedItem) {
            const result = duplicateElementTree(selectedItems, selectedItem.id);
            updateItems(result.items);
            if (result.copy) setSelectedItemId(result.copy.id);
            return;
        }
        if (!selectedBlock) return;
        const copy: Block = { ...structuredClone(selectedBlock), id: undefined, block_key: newKey(selectedBlock.type), content: { ...structuredClone(selectedBlock.content), elements: selectedItems.map(cloneElementWithNewIds) } };
        setData((current) => { const blocks = [...current.blocks]; blocks.splice(selectedIndex + 1, 0, copy); return { ...current, blocks }; });
        setSelectedKey(copy.block_key);
    };
    const removeBlock = () => {
        if (selectedItem) {
            if (!window.confirm('Remover este elemento da secção?')) return;
            const remaining = removeElementTree(selectedItems, selectedItem.id);
            updateItems(remaining);
            setSelectedItemId(null);
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
                const isJson = response.headers.get('content-type')?.includes('application/json');
                const result = isJson ? await response.json() : {};
                if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || (response.status === 413 ? 'O conteúdo enviado excede o limite do servidor.' : `Não foi possível guardar (erro ${response.status}).`));
                lastSaved.current = snapshot; setSavedAt(result.saved_at || new Date().toISOString());
                if (result.version) setVersions((current) => current.some((item) => item.id === result.version.id) ? current : [result.version, ...current].slice(0, 50));
            } catch (error) { if (!(error instanceof DOMException && error.name === 'AbortError')) setSaveError(error instanceof Error ? error.message : 'Não foi possível guardar.'); }
            finally {
                if (autosaveController.current === controller) {
                    autosaveController.current = null;
                    setSaving(false);
                }
            }
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
    const uploadImage = (event: FormEvent) => {
        event.preventDefault();
        setUploadError(null);
        if (!upload.data.image) { setUploadError('Escolhe uma imagem.'); return; }
        if (upload.data.image.size > 8 * 1024 * 1024) { setUploadError('A imagem excede 8 MB. Reduz o ficheiro antes de o carregar.'); return; }
        upload.post('/website/media', {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => { upload.reset(); setUploadError(null); },
            onError: (errors) => setUploadError(Object.values(errors).join(' ') || 'Não foi possível carregar a imagem.'),
        });
    };
    const importCurrentWebsite = () => {
        if (!page.can_import_current_website) return;
        if (dirty && !window.confirm('Existem alterações ainda não guardadas. A importação vai substituí-las. Continuar?')) return;
        if (!window.confirm(`Importar a ${page.import_source_label} para o editor? O rascunho atual será substituído, será criada uma versão no histórico e o website público ficará inalterado.`)) return;
        autosaveController.current?.abort();
        router.post(`/website/paginas/${page.id}/importar`, {}, { preserveState: false });
    };

    const livePage = { ...data, design_settings: data.design_settings, blocks: data.blocks };

    return <div className="fixed inset-0 z-[100] flex h-screen flex-col overflow-hidden bg-slate-100 text-foreground">
        <Head title={`${data.title} — Editor visual`} />
        <header className="flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-white px-3 shadow-sm lg:px-4">
            <div className="flex min-w-0 items-center gap-2"><Button variant="ghost" size="icon" onClick={close}><ArrowLeft /></Button><div className="min-w-0"><p className="truncate text-sm font-semibold">{data.title}</p><div className="flex items-center gap-2 text-xs text-muted-foreground"><Badge variant="outline" className="h-5">{page.status}</Badge>{saving ? <span className="flex items-center gap-1"><SpinnerGap className="animate-spin" /> A guardar</span> : saveError ? <span className="font-medium text-red-700">Erro ao guardar</span> : dirty ? <span>Alterações por guardar</span> : <span className="flex items-center gap-1 text-emerald-700"><Check /> Guardado{savedAt ? ` às ${new Date(savedAt).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })}` : ''}</span>}</div></div></div>
            <div className="hidden items-center gap-1 rounded-lg border bg-slate-50 p-1 md:flex">{(['desktop', 'tablet', 'mobile'] as Device[]).map((item) => <Button key={item} variant={device === item ? 'default' : 'ghost'} size="icon" className="h-8 w-8" onClick={() => setDevice(item)} title={item}>{item === 'desktop' ? <Desktop /> : item === 'tablet' ? <DeviceTablet /> : <DeviceMobile />}</Button>)}</div>
            <div className="flex items-center gap-2">{page.can_import_current_website && <Button variant="outline" size="sm" className="hidden xl:inline-flex" onClick={importCurrentWebsite} disabled={saving}><DownloadSimple className="mr-2" /> Importar website atual</Button>}<Button asChild variant="outline" size="sm" className="hidden sm:inline-flex"><a href={`/website/paginas/${page.id}/previsualizar`} target="_blank" rel="noreferrer"><Eye className="mr-2" /> Pré-visualizar</a></Button><Button variant="outline" size="sm" onClick={() => submit('save_draft')} disabled={saving}><FloppyDisk className="mr-2" /> Guardar</Button><Button size="sm" onClick={() => submit('publish')} disabled={saving}>Aplicar</Button><Button variant="ghost" size="icon" onClick={close}><X /></Button></div>
        </header>
        {saveError && <div className="shrink-0 bg-red-600 px-4 py-2 text-center text-xs font-medium text-white">{saveError}</div>}

        <div className="grid min-h-0 flex-1 grid-cols-[260px_minmax(0,1fr)_350px]">
            <aside className="flex min-h-0 flex-col border-r bg-white">
                <div className="border-b p-3"><div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Estrutura</p><Button size="sm" variant="ghost" onClick={() => { setSelectedKey(null); setSelectedItemId(null); setInspectorTab('page'); }}>Página</Button></div><div className="flex gap-2"><Select value={newBlockType} onValueChange={setNewBlockType}><SelectTrigger className="h-9"><SelectValue /></SelectTrigger><SelectContent>{blockTypes.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}</SelectContent></Select><Button size="icon" className="h-9 w-9 shrink-0" onClick={addBlock} title="Adicionar secção"><Plus /></Button></div></div>
                <ScrollArea className="flex-1"><div className="space-y-2 p-3">{data.blocks.map((block, index) => <div key={block.block_key} className="space-y-1"><button draggable onDragStart={() => setDraggedKey(block.block_key)} onDragOver={(event: DragEvent) => event.preventDefault()} onDrop={() => reorderBlock(block.block_key)} onClick={() => selectBlock(block.block_key)} className={`flex w-full items-center gap-2 rounded-lg border p-2 text-left transition ${selectedKey === block.block_key && !selectedItemId ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'bg-white hover:bg-muted/40'}`}><DotsSixVertical className="shrink-0 text-muted-foreground" /><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-muted text-xs font-semibold">{index + 1}</span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium">{firstHeading(sectionItems(block.content.elements)) || stringValue(block.content.title) || typeLabels[block.type]}</span><span className="block truncate text-xs text-muted-foreground">{typeLabels[block.type]}</span></span>{!block.is_visible && <Eye className="text-muted-foreground" />}</button>{sectionItems(block.content.elements).length > 0 && <ElementTree items={sectionItems(block.content.elements)} blockKey={block.block_key} selectedItemId={selectedItemId} draggedKey={draggedKey} onSelect={(blockKey, itemId) => selectBlock(blockKey, itemId)} onDrag={setDraggedKey} onDrop={reorderItem} />}</div>)}{data.blocks.length === 0 && <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"><p>Esta página ainda não tem blocos.</p>{page.can_import_current_website && <Button className="mt-3" size="sm" onClick={importCurrentWebsite}><DownloadSimple className="mr-2" /> Importar website atual</Button>}</div>}</div></ScrollArea>
                {selectedBlock && <div className="border-t p-2"><p className="mb-2 text-[10px] font-semibold uppercase text-muted-foreground">{canContainChildren(selectedItem) ? `Adicionar dentro de ${itemTypeLabels[selectedItem!.type]}` : 'Adicionar ao bloco'}</p><div className="flex gap-2"><Select value={newItemType} onValueChange={(value) => setNewItemType(value as AddableItemType)}><SelectTrigger className="h-9"><SelectValue /></SelectTrigger><SelectContent>{Object.entries(addableItemTypeLabels).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent></Select><Button size="icon" className="h-9 w-9 shrink-0" onClick={addItem}><Plus /></Button></div></div>}
                {selectedBlock && <div className="flex gap-1 border-t p-2"><Button className="flex-1" size="sm" variant="outline" onClick={duplicateBlock}><Copy className="mr-2" /> Duplicar</Button><Button size="icon" variant="outline" className="h-9 w-9" onClick={removeBlock}><Trash /></Button></div>}
            </aside>

            <main className="min-w-0 overflow-auto bg-[radial-gradient(circle_at_top,#dce7ef,#aebdca)] p-5">
                <div className="mx-auto h-full w-max min-w-full"><FramePreview width={deviceWidths[device]}><ManagedPageCanvas page={livePage} news={news} events={events} partners={partners} dynamicData={resolvedDynamicData} editor={selectBlock} selectedBlockKey={selectedKey} selectedItemId={selectedItemId} /></FramePreview></div>
            </main>

            <aside className="min-h-0 border-l bg-white">
                <Tabs value={inspectorTab} onValueChange={(value) => setInspectorTab(value as InspectorTab)} className="flex h-full flex-col">
                    <div className="shrink-0 space-y-2 border-b p-3"><div><p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Elemento selecionado</p><TabsList className="h-auto w-full justify-start">{selectedBlock ? <><TabsTrigger value="content">Conteúdo</TabsTrigger><TabsTrigger value="design">Estilo</TabsTrigger><TabsTrigger value="behavior">Comportamento</TabsTrigger></> : <span className="px-2 py-1 text-xs text-muted-foreground">Seleciona um elemento na página</span>}</TabsList></div><div><p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Ferramentas da página</p><TabsList className="h-auto w-full justify-start"><TabsTrigger value="page">Página</TabsTrigger><TabsTrigger value="media">Imagens</TabsTrigger><TabsTrigger value="history">Histórico</TabsTrigger></TabsList></div></div>
                    <ScrollArea className="min-h-0 flex-1"><div className="p-4 pt-2">
                        {selectedBlock && <TabsContent value="content" className="mt-0 space-y-4"><div className="flex items-center justify-between"><div><p className="text-sm font-semibold">{selectedItem ? itemTypeLabels[selectedItem.type] : typeLabels[selectedBlock.type]}</p><p className="text-xs text-muted-foreground">Alterações visíveis em tempo real</p></div><Switch checked={selectedItem ? selectedItem.is_visible : selectedBlock.is_visible} onCheckedChange={(is_visible) => selectedItem ? patchItem({ is_visible }) : patchBlock({ is_visible })} /></div>{selectedItem ? <SectionItemContentFields item={selectedItem} media={media} dataSources={resolvedDataSources} dynamicData={resolvedDynamicData} update={updateItemContent} /> : <BlockContentFields block={selectedBlock} media={media} update={updateContent} />}</TabsContent>}
                        {selectedBlock && <TabsContent value="design" className="mt-0 space-y-4">{selectedItem ? <SectionItemStyleFields item={selectedItem} pageDesign={data.design_settings} update={updateItemStyle} /> : <BlockStyleFields block={selectedBlock} pageDesign={data.design_settings} media={media} update={updateStyle} />}</TabsContent>}
                        {selectedBlock && <TabsContent value="behavior" className="mt-0 space-y-4">{selectedItem ? <SectionItemBehaviorFields item={selectedItem} update={updateItemSettings} /> : <><p className="text-sm font-semibold">Comportamento do bloco</p><Field label="Âncora" value={selectedBlock.settings.anchor_id || ''} onChange={(value) => updateSettings('anchor_id', value.toLowerCase().replace(/[^a-z0-9-]/g, '-') || null)} placeholder="ex.: equipa" /><SelectField label="Animação de entrada" value={selectedBlock.settings.animation} onChange={(value) => updateSettings('animation', value as BlockSettings['animation'])} options={[["none", "Sem animação"], ["fade", "Aparecer"], ["slide-up", "Subir"], ["zoom", "Aproximar"]]} />{selectedBlock.settings.animation !== 'none' && <RangeField label="Atraso da animação" value={selectedBlock.settings.animation_delay} min={0} max={2000} suffix=" ms" onChange={(value) => updateSettings('animation_delay', value)} />}<ToggleField label="Ocultar no telemóvel" checked={selectedBlock.settings.hide_mobile} onChange={(value) => updateSettings('hide_mobile', value)} /><ToggleField label="Ocultar no computador" checked={selectedBlock.settings.hide_desktop} onChange={(value) => updateSettings('hide_desktop', value)} />{!Array.isArray(selectedBlock.content.elements) && <ToggleField label="Abrir ligações noutro separador" checked={selectedBlock.settings.open_links_new_tab} onChange={(value) => updateSettings('open_links_new_tab', value)} />}</>}</TabsContent>}
                        <TabsContent value="page" className="mt-0 space-y-4"><p className="text-sm font-semibold">Página e identidade visual</p><Field label="Título da página" value={data.title} onChange={(title) => setData((current) => ({ ...current, title }))} /><Field label="Endereço" value={data.slug} onChange={(slug) => setData((current) => ({ ...current, slug: slug.toLowerCase().replace(/[^a-z0-9-]/g, '-') }))} /><Field label="Nome no menu" value={data.navigation_label} onChange={(navigation_label) => setData((current) => ({ ...current, navigation_label }))} /><RangeField label="Ordem no menu" value={data.sort_order} min={0} max={999} suffix="" onChange={(sort_order) => setData((current) => ({ ...current, sort_order }))} /><ToggleField label="Mostrar no menu" checked={data.show_in_navigation} onChange={(show_in_navigation) => setData((current) => ({ ...current, show_in_navigation }))} /><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Fundo, cores e tipografia</p><div className="space-y-4"><ColorField label="Fundo da página" value={data.design_settings.background_color} fallback="#ffffff" onChange={(background_color) => background_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_color } }))} /><BackgroundImageField label="Imagem de fundo da página" value={data.design_settings.background_image} media={media} onChange={(background_image) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_image } }))} /><Field label="Posição do fundo" value={data.design_settings.background_position} onChange={(background_position) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, background_position } }))} placeholder="center top" /><ColorField label="Texto" value={data.design_settings.text_color} fallback="#102c44" onChange={(text_color) => text_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, text_color } }))} /><ColorField label="Títulos" value={data.design_settings.heading_color} fallback="#062b54" onChange={(heading_color) => heading_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, heading_color } }))} /><ColorField label="Destaque" value={data.design_settings.accent_color} fallback="#f2e613" onChange={(accent_color) => accent_color && setData((current) => ({ ...current, design_settings: { ...current.design_settings, accent_color } }))} /><SelectField label="Tipo de letra dos títulos" value={data.design_settings.heading_font} onChange={(heading_font) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, heading_font: heading_font as PageDesign['heading_font'] } }))} options={[["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><SelectField label="Tipo de letra do texto" value={data.design_settings.body_font} onChange={(body_font) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, body_font: body_font as PageDesign['body_font'] } }))} options={[["inter", "Inter"], ["poppins", "Poppins"], ["montserrat", "Montserrat"], ["georgia", "Georgia"], ["system", "Sistema"]]} /><RangeField label="Tamanho base" value={data.design_settings.base_font_size} min={12} max={22} onChange={(base_font_size) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, base_font_size } }))} /><SelectField label="Largura geral" value={data.design_settings.content_width} onChange={(content_width) => setData((current) => ({ ...current, design_settings: { ...current.design_settings, content_width: content_width as PageDesign['content_width'] } }))} options={[["compact", "Compacta"], ["standard", "Normal"], ["wide", "Larga"]]} /></div></div><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">SEO</p><div className="space-y-3"><Field label="Título SEO" value={data.meta_title} onChange={(meta_title) => setData((current) => ({ ...current, meta_title }))} /><TextField label="Descrição SEO" value={data.meta_description} onChange={(meta_description) => setData((current) => ({ ...current, meta_description }))} rows={3} /></div></div><div className="border-t pt-4"><p className="mb-3 text-sm font-semibold">Publicação</p><div className="space-y-3"><Input type="datetime-local" value={scheduledFor} onChange={(event) => setScheduledFor(event.target.value)} /><Button className="w-full" variant="outline" disabled={!scheduledFor || saving} onClick={() => submit('schedule')}><ClockCounterClockwise className="mr-2" /> Agendar publicação</Button><Button className="w-full" variant="outline" onClick={() => submit('hide')}>Ocultar página</Button><Button asChild className="w-full" variant="outline"><a href={page.public_url} target="_blank" rel="noreferrer">Abrir publicada <ArrowSquareOut className="ml-2" /></a></Button></div></div></TabsContent>
                        <TabsContent value="media" className="mt-0 space-y-4"><form onSubmit={uploadImage} className="space-y-3 rounded-lg border p-3"><div><p className="text-sm font-semibold">Nova imagem</p><p className="mt-1 text-xs text-muted-foreground">JPG, PNG ou WebP até 8 MB.</p></div><Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => { const file = event.target.files?.[0] || null; setUploadError(file && file.size > 8 * 1024 * 1024 ? 'A imagem excede 8 MB.' : null); upload.setData('image', file); }} required /><Input value={upload.data.alt_text} onChange={(event) => upload.setData('alt_text', event.target.value)} placeholder="Texto alternativo" required />{uploadError && <div className="rounded-md bg-red-50 px-3 py-2 text-xs font-medium text-red-700">{uploadError}</div>}<Button type="submit" className="w-full" disabled={upload.processing || Boolean(uploadError)}><UploadSimple className="mr-2" /> Carregar</Button></form><div className="grid gap-3">{media.map((item) => <MediaCard key={item.id} item={item} />)}{media.length === 0 && <p className="text-sm text-muted-foreground">A biblioteca ainda não tem imagens.</p>}</div></TabsContent>
                        <TabsContent value="history" className="mt-0 space-y-3"><p className="text-sm font-semibold">Histórico de versões</p>{versions.map((version) => <div key={version.id} className="rounded-lg border p-3"><p className="text-sm font-medium">Versão {version.version} · {actionLabels[version.action] || version.action}</p><p className="mt-1 text-xs text-muted-foreground">{new Date(version.created_at).toLocaleString('pt-PT')} · {version.created_by || 'Sistema'}</p><Button className="mt-3 w-full" size="sm" variant="outline" onClick={() => window.confirm('Recuperar esta versão para o rascunho?') && router.post(`/website/paginas/${page.id}/versoes/${version.id}/recuperar`)}><ClockCounterClockwise className="mr-2" /> Recuperar</Button></div>)}{versions.length === 0 && <p className="text-sm text-muted-foreground">Ainda não existem versões.</p>}</TabsContent>
                    </div></ScrollArea>
                </Tabs>
            </aside>
        </div>
    </div>;
}
