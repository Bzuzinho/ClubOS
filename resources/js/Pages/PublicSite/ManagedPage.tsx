import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { PublicFooter, PublicHeader } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import { CSSProperties, ReactNode } from 'react';
import ContactForm from './ContactForm';
import RegistrationForm from './RegistrationForm';
import {
    PublicEvent,
    PublicNews,
    PublicPartner,
    WebsiteDynamicData,
    eventDateParts,
    newsDate,
} from './types';

type BlockContent = Record<string, unknown>;

type ManagedBlock = {
    id?: string;
    block_key: string;
    type: string;
    is_visible: boolean;
    content: BlockContent;
    style?: BlockStyle;
    settings?: BlockSettings;
};

type BlockStyle = {
    background_color?: string | null;
    text_color?: string | null;
    heading_color?: string | null;
    accent_color?: string | null;
    padding_top?: number;
    padding_right?: number;
    padding_bottom?: number;
    padding_left?: number;
    margin_top?: number;
    margin_right?: number;
    margin_bottom?: number;
    margin_left?: number;
    content_width?: 'page' | 'compact' | 'wide' | 'full';
    text_align?: 'left' | 'center' | 'right';
    heading_size?: number;
    body_size?: number;
    border_radius?: number;
    shadow?: 'none' | 'soft' | 'medium' | 'strong';
    card_background?: string | null;
    card_border_color?: string | null;
    card_radius?: number;
    card_shadow?: 'none' | 'soft' | 'medium' | 'strong';
    card_gap?: number;
    background_image?: string | null;
    background_position?: string;
    heading_font?: 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    body_font?: 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    heading_weight?: number;
    body_weight?: number;
    line_height?: number;
};

type BlockSettings = {
    anchor_id?: string | null;
    animation?: 'none' | 'fade' | 'slide-up' | 'zoom';
    animation_delay?: number;
    hide_mobile?: boolean;
    hide_desktop?: boolean;
    open_links_new_tab?: boolean;
};

type PageDesign = {
    background_color?: string;
    text_color?: string;
    heading_color?: string;
    accent_color?: string;
    heading_font?: 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    body_font?: 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    base_font_size?: number;
    content_width?: 'compact' | 'standard' | 'wide';
    background_image?: string | null;
    background_position?: string;
};

type SectionItem = {
    id: string;
    type: 'container' | 'subsection' | 'card' | 'text' | 'eyebrow' | 'heading' | 'paragraph' | 'rich_text' | 'image' | 'button' | 'link' | 'divider' | 'spacer' | 'data_collection' | 'contact_form' | 'registration_form';
    is_visible?: boolean;
    content: BlockContent;
    style?: SectionItemStyle;
    settings?: SectionItemSettings;
    children?: SectionItem[];
};

type SectionItemStyle = {
    background_color?: string | null;
    text_color?: string | null;
    heading_color?: string | null;
    accent_color?: string | null;
    border_color?: string | null;
    border_width?: number;
    border_radius?: number;
    shadow?: 'none' | 'soft' | 'medium' | 'strong';
    padding?: number;
    padding_top?: number;
    padding_right?: number;
    padding_bottom?: number;
    padding_left?: number;
    margin_top?: number;
    margin_right?: number;
    margin_bottom?: number;
    margin_left?: number;
    min_height?: number;
    text_align?: 'left' | 'center' | 'right';
    heading_size?: number;
    body_size?: number;
    heading_font?: 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    body_font?: 'inherit' | 'inter' | 'poppins' | 'montserrat' | 'georgia' | 'system';
    heading_weight?: number;
    body_weight?: number;
    line_height?: number;
    column_span?: number;
    tablet_span?: number;
    mobile_span?: number;
    row_span?: number;
    image_ratio?: 'auto' | '1:1' | '4:3' | '16:9' | '21:9';
    image_fit?: 'cover' | 'contain';
    data_card_background?: string | null;
    data_card_text_color?: string | null;
    data_card_border_color?: string | null;
    data_card_border_width?: number;
    data_card_radius?: number;
    data_card_shadow?: 'none' | 'soft' | 'medium' | 'strong';
    data_card_padding?: number;
};

type SectionItemSettings = {
    animation?: 'none' | 'fade' | 'slide-up' | 'zoom';
    animation_delay?: number;
    hide_mobile?: boolean;
    hide_desktop?: boolean;
    open_link_new_tab?: boolean;
};

type ManagedPageData = {
    slug: string;
    title: string;
    meta_title?: string | null;
    meta_description?: string | null;
    design_settings?: PageDesign;
    blocks: ManagedBlock[];
};

const EMPTY_DYNAMIC_DATA: WebsiteDynamicData = {
    news: [],
    events: [],
    partners: [],
    convocations: [],
    statistics: [],
};

type CardItem = {
    label?: string;
    title?: string;
    text?: string;
    url?: string;
};

function text(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function number(value: unknown, fallback: number): number {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function strings(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}

function items(value: unknown): CardItem[] {
    return Array.isArray(value)
        ? value.filter((item): item is CardItem => Boolean(item) && typeof item === 'object')
        : [];
}

function sectionItems(value: unknown): SectionItem[] {
    return Array.isArray(value)
        ? value
            .filter((item): item is SectionItem => Boolean(item) && typeof item === 'object' && typeof item.id === 'string')
            .map((item) => ({ ...item, children: sectionItems(item.children) }))
        : [];
}

function safeHref(value: unknown): string {
    const href = text(value).trim();
    if (/^(\/|#|mailto:|tel:|https:\/\/)/i.test(href)) return href;
    return '#';
}

function SmartLink({ href, className, children, newTab = false }: { href: unknown; className?: string; children: ReactNode; newTab?: boolean }) {
    const safe = safeHref(href);
    if (newTab || safe.startsWith('http') || safe.startsWith('mailto:') || safe.startsWith('tel:')) {
        const external = newTab || safe.startsWith('http');
        return <a className={className} href={safe} target={external ? '_blank' : undefined} rel={external ? 'noreferrer' : undefined}>{children}</a>;
    }

    return <Link className={className} href={safe}>{children}</Link>;
}

function SectionHeading({ content }: { content: BlockContent }) {
    const eyebrow = text(content.eyebrow);
    const title = text(content.title);
    const intro = text(content.intro);

    if (!eyebrow && !title && !intro) return null;

    return (
        <div className="cms-heading">
            <div>{eyebrow && <p className="eyebrow">{eyebrow}</p>}{title && <h2>{title}</h2>}</div>
            {intro && <p>{intro}</p>}
        </div>
    );
}

function HeroBlock({ content, newTab }: { content: BlockContent; newTab: boolean }) {
    const primaryLabel = text(content.primary_label);
    const secondaryLabel = text(content.secondary_label);

    return (
        <>
            <PageHero
                eyebrow={text(content.eyebrow) || 'BSCN'}
                title={text(content.title)}
                text={text(content.text)}
                image={text(content.image)}
                imagePosition={text(content.image_position) || 'center'}
            />
            {(primaryLabel || secondaryLabel) && (
                <div className="cms-hero-actions shell">
                    {primaryLabel && <SmartLink className="button" href={content.primary_url} newTab={newTab}>{primaryLabel}</SmartLink>}
                    {secondaryLabel && <SmartLink className="text-link" href={content.secondary_url} newTab={newTab}>{secondaryLabel} <span>↗</span></SmartLink>}
                </div>
            )}
        </>
    );
}

function RichTextBlock({ content }: { content: BlockContent }) {
    return (
        <section className="cms-rich-text shell">
            <SectionHeading content={content} />
            <div className="cms-copy">{text(content.text).split(/\n{2,}/).filter(Boolean).map((paragraph, index) => <p key={index}>{paragraph}</p>)}</div>
        </section>
    );
}

function CardsBlock({ content, newTab }: { content: BlockContent; newTab: boolean }) {
    const cards = items(content.items);
    const columns = Math.min(4, Math.max(1, number(content.columns, 3)));

    return (
        <section className="cms-section shell">
            <SectionHeading content={content} />
            <div className={`cms-cards cms-columns-${columns}`}>
                {cards.map((card, index) => {
                    const article = <article><span>{card.label}</span><h3>{card.title}</h3><p>{card.text}</p>{card.url && <b aria-hidden="true">↗</b>}</article>;
                    return card.url ? <SmartLink href={card.url} newTab={newTab} key={`${card.title}-${index}`}>{article}</SmartLink> : <div key={`${card.title}-${index}`}>{article}</div>;
                })}
            </div>
        </section>
    );
}

function ImageTextBlock({ content, newTab }: { content: BlockContent; newTab: boolean }) {
    const imageRight = text(content.image_side) === 'right';
    const image = text(content.image);
    return (
        <section className={`cms-image-text shell ${imageRight ? 'image-right' : ''}`}>
            <div className="cms-image" role="img" aria-label={text(content.image_alt) || text(content.title)} style={{ backgroundImage: image ? `url('${image}')` : undefined, backgroundPosition: text(content.image_position) || 'center' }} />
            <div className="cms-image-copy">
                <p className="eyebrow">{text(content.eyebrow)}</p>
                <h2>{text(content.title)}</h2>
                <p>{text(content.text)}</p>
                {strings(content.items).length > 0 && <ul>{strings(content.items).map((item) => <li key={item}>{item}</li>)}</ul>}
                {text(content.button_label) && <SmartLink className="button button-outline" href={content.button_url} newTab={newTab}>{text(content.button_label)}</SmartLink>}
            </div>
        </section>
    );
}

function StatsBlock({ content }: { content: BlockContent }) {
    const stats = items(content.items);
    return <section className="cms-stats"><div className="shell">{stats.map((stat, index) => {
        const value = text((stat as Record<string, unknown>).value) || text(stat.title);
        return <article key={`${stat.label}-${index}`}><strong>{value}</strong><span>{stat.label}</span></article>;
    })}</div></section>;
}

function CtaBlock({ content, newTab }: { content: BlockContent; newTab: boolean }) {
    return (
        <section className="cms-cta shell">
            <div><p className="eyebrow">{text(content.eyebrow)}</p><h2>{text(content.title)}</h2><p>{text(content.text)}</p></div>
            <div className="cta-actions">
                {text(content.button_label) && <SmartLink className="button" href={content.button_url} newTab={newTab}>{text(content.button_label)}</SmartLink>}
                {text(content.secondary_label) && <SmartLink className="text-link" href={content.secondary_url} newTab={newTab}>{text(content.secondary_label)} <span>↗</span></SmartLink>}
            </div>
        </section>
    );
}

function NewsBlock({ content, news }: { content: BlockContent; news: PublicNews[] }) {
    const limit = Math.min(30, Math.max(1, number(content.limit, 6)));
    return (
        <section className="cms-section shell">
            <SectionHeading content={content} />
            <div className="story-grid">
                {news.slice(0, limit).map((story) => <article key={story.id}><div className="story-visual" style={{ backgroundImage: `url('${story.image || '/site-assets/bscn-news-bright.webp'}')` }} /><p className="story-meta">{newsDate(story.publishedAt)} · {story.category}</p><h3>{story.title}</h3><p>{story.excerpt}</p></article>)}
                {news.length === 0 && <div className="cms-empty">Ainda não existem notícias publicadas.</div>}
            </div>
        </section>
    );
}

function EventsBlock({ content, events }: { content: BlockContent; events: PublicEvent[] }) {
    const limit = Math.min(60, Math.max(1, number(content.limit, 12)));
    return (
        <section className="cms-section shell">
            <SectionHeading content={content} />
            <div className="calendar-events cms-event-list">
                {events.slice(0, limit).map((event) => { const date = eventDateParts(event.startDate); return <article className="calendar-event" key={event.id}><time><strong>{date.day}</strong><span>{date.month}<small>{date.year}</small></span></time><div><p className="story-meta">{event.type.toUpperCase()} · BSCN</p><h3>{event.title}</h3><p>{[event.place, event.startTime].filter(Boolean).join(' · ') || event.description}</p></div></article>; })}
                {events.length === 0 && <div className="cms-empty">Ainda não existem eventos públicos confirmados.</div>}
            </div>
        </section>
    );
}

type DynamicRecord = { id: string; image?: string | null; meta?: string | null; title: string; description?: string | null; href?: string | null };

function dynamicRecords(content: BlockContent, dynamicData: WebsiteDynamicData): DynamicRecord[] {
    const source = text(content.source) as keyof WebsiteDynamicData || 'news';
    const limit = Math.min(60, Math.max(1, number(content.limit, 3)));

    if (source === 'events') {
        return dynamicData.events.slice(0, limit).map((event) => {
            const date = eventDateParts(event.startDate);
            return { id: event.id, meta: `${date.day} ${date.month} ${date.year} · ${event.type}`, title: event.title, description: [event.place, event.startTime].filter(Boolean).join(' · ') || event.description || '', href: '/calendario' };
        });
    }
    if (source === 'convocations') {
        return dynamicData.convocations.slice(0, limit).map((convocation) => {
            const date = eventDateParts(convocation.startDate);
            const meeting = [convocation.meetingPlace, convocation.meetingTime].filter(Boolean).join(' · ');
            return { id: convocation.id, meta: `${date.day} ${date.month} ${date.year} · ${convocation.athleteCount} atletas`, title: convocation.title, description: meeting || convocation.place || convocation.description || '', href: '/calendario' };
        });
    }
    if (source === 'partners') {
        return dynamicData.partners.slice(0, limit).map((partner) => ({ id: partner.id, image: partner.logo || null, meta: partner.type, title: partner.name, description: partner.description || '', href: partner.website || '/parceiros' }));
    }
    if (source === 'statistics') {
        return dynamicData.statistics.slice(0, limit).map((statistic) => ({ id: statistic.id, meta: statistic.label, title: new Intl.NumberFormat('pt-PT').format(statistic.value), description: statistic.description || '', href: null }));
    }

    return dynamicData.news.slice(0, limit).map((story) => ({ id: story.id, image: story.image || '/site-assets/bscn-news-bright.webp', meta: [newsDate(story.publishedAt), story.category].filter(Boolean).join(' · '), title: story.title, description: story.excerpt, href: '/noticias' }));
}

function DataFeedBlock({ content, defaultSource, dynamicData, newTab, editor }: { content: BlockContent; defaultSource: 'news' | 'events'; dynamicData: WebsiteDynamicData; newTab: boolean; editor: boolean }) {
    return <section className="cms-section shell"><SectionHeading content={content} /><DynamicCollection content={{ ...content, source: text(content.source) || defaultSource }} dynamicData={dynamicData} newTab={newTab} editor={editor} /></section>;
}

function DynamicCollection({ content, dynamicData, newTab, editor }: { content: BlockContent; dynamicData: WebsiteDynamicData; newTab: boolean; editor: boolean }) {
    const source = text(content.source) as keyof WebsiteDynamicData || 'news';
    const columns = Math.min(6, Math.max(1, number(content.columns, source === 'statistics' ? 4 : 3)));
    const requestedLayout = text(content.layout);
    const layout = source === 'statistics' || requestedLayout === 'metrics' ? 'metrics' : requestedLayout === 'list' ? 'list' : 'grid';
    const showImage = content.show_image !== false;
    const showMeta = content.show_meta !== false;
    const showDescription = content.show_description !== false;
    const showLink = content.show_link !== false;
    const linkLabel = text(content.link_label) || 'Saber mais';
    const records = dynamicRecords(content, dynamicData);

    if (records.length === 0 && !editor) return null;

    return <div className={`cms-data-collection cms-data-${layout}`} style={{ '--cms-data-columns': columns } as CSSProperties}>
        {records.map((record) => <article key={record.id}>{showImage && record.image && <div className="cms-data-image"><img src={record.image} alt={record.title} /></div>}<div className="cms-data-copy">{showMeta && record.meta && <p className="cms-data-meta">{record.meta}</p>}<h3>{record.title}</h3>{showDescription && record.description && <p>{record.description}</p>}{showLink && record.href && <SmartLink href={record.href} newTab={newTab}>{linkLabel} <span aria-hidden="true">↗</span></SmartLink>}</div></article>)}
        {records.length === 0 && <div className="cms-data-empty-state">Sem registos nesta origem. O aviso aparece apenas no editor.</div>}
    </div>;
}

function sectionItemVariables(style: SectionItemStyle = {}, type: SectionItem['type'] = 'subsection'): CSSProperties {
    const card = type === 'card';
    const legacyPadding = style.padding ?? (card ? 24 : 0);
    return {
        '--cms-item-background': style.background_color || (card ? 'var(--cms-card-background)' : 'transparent'),
        '--cms-item-text': style.text_color || 'var(--cms-block-text)',
        '--cms-item-heading': style.heading_color || 'var(--cms-block-heading)',
        '--cms-item-accent': style.accent_color || 'var(--cms-block-accent)',
        '--cms-item-border': style.border_color || (card ? 'var(--cms-card-border)' : 'transparent'),
        '--cms-item-border-width': `${style.border_width ?? (card ? 2 : 0)}px`,
        '--cms-item-radius': style.border_radius === undefined ? (card ? 'var(--cms-card-radius)' : '0px') : `${style.border_radius}px`,
        '--cms-item-shadow': style.shadow === undefined ? (card ? 'var(--cms-card-shadow)' : 'none') : shadowValue(style.shadow),
        '--cms-item-padding-top': `${style.padding_top ?? legacyPadding}px`,
        '--cms-item-padding-right': `${style.padding_right ?? legacyPadding}px`,
        '--cms-item-padding-bottom': `${style.padding_bottom ?? legacyPadding}px`,
        '--cms-item-padding-left': `${style.padding_left ?? legacyPadding}px`,
        '--cms-item-margin-top': `${style.margin_top ?? 0}px`,
        '--cms-item-margin-right': `${style.margin_right ?? 0}px`,
        '--cms-item-margin-bottom': `${style.margin_bottom ?? 0}px`,
        '--cms-item-margin-left': `${style.margin_left ?? 0}px`,
        '--cms-item-min-height': `${style.min_height ?? 0}px`,
        '--cms-item-heading-size': `${style.heading_size ?? 22}px`,
        '--cms-item-body-size': `${style.body_size ?? 14}px`,
        '--cms-item-heading-font': style.heading_font && style.heading_font !== 'inherit' ? fontFamily(style.heading_font) : 'var(--cms-block-heading-font)',
        '--cms-item-body-font': style.body_font && style.body_font !== 'inherit' ? fontFamily(style.body_font) : 'var(--cms-block-body-font)',
        '--cms-item-heading-weight': style.heading_weight ?? 600,
        '--cms-item-body-weight': style.body_weight ?? 400,
        '--cms-item-line-height': style.line_height ?? 1.6,
        '--cms-item-span-desktop': Math.max(1, style.column_span ?? 1),
        '--cms-item-span-tablet': Math.max(1, style.tablet_span ?? 1),
        '--cms-item-span-mobile': Math.max(1, style.mobile_span ?? 1),
        '--cms-item-row-span': Math.max(1, style.row_span ?? 1),
        '--cms-data-card-background': style.data_card_background || 'var(--cms-card-background)',
        '--cms-data-card-text': style.data_card_text_color || 'var(--cms-item-text)',
        '--cms-data-card-border': style.data_card_border_color || 'var(--cms-card-border)',
        '--cms-data-card-border-width': `${style.data_card_border_width ?? 2}px`,
        '--cms-data-card-radius': `${style.data_card_radius ?? 15}px`,
        '--cms-data-card-shadow': shadowValue(style.data_card_shadow || 'soft'),
        '--cms-data-card-padding': `${style.data_card_padding ?? 20}px`,
        textAlign: style.text_align || 'left',
    } as CSSProperties;
}

function RenderSectionItem({ item, blockKey, dynamicData, editor, selectedItemId }: { item: SectionItem; blockKey: string; dynamicData: WebsiteDynamicData; editor?: (key: string, itemId?: string) => void; selectedItemId?: string | null }) {
    if (item.is_visible === false) return null;
    const content = item.content || {};
    if (item.type === 'data_collection' && !editor && dynamicRecords(content, dynamicData).length === 0) return null;
    const style = item.style || {};
    const settings = item.settings || {};
    const children = sectionItems(item.children);
    const image = text(content.image);
    const classes = ['cms-section-item', `cms-section-${item.type}`, settings.animation && settings.animation !== 'none' ? `cms-motion-${settings.animation}` : '', settings.hide_mobile ? 'cms-hide-mobile' : '', settings.hide_desktop ? 'cms-hide-desktop' : '', editor ? 'cms-editor-node' : '', selectedItemId === item.id ? 'is-selected' : ''].filter(Boolean).join(' ');
    let body: ReactNode;
    if (['container', 'subsection', 'card'].includes(item.type)) {
        const layout = {
            '--cms-section-columns': Math.min(6, Math.max(1, number(content.columns_desktop, 1))),
            '--cms-section-columns-tablet': Math.min(4, Math.max(1, number(content.columns_tablet, 1))),
            '--cms-section-columns-mobile': Math.min(2, Math.max(1, number(content.columns_mobile, 1))),
            '--cms-section-gap': `${Math.min(80, Math.max(0, number(content.gap, 12)))}px`,
            '--cms-section-align': text(content.align_items) || 'start',
        } as CSSProperties;
        body = <div className="cms-element-children" style={layout}>{children.map((child) => <RenderSectionItem key={child.id} item={child} blockKey={blockKey} dynamicData={dynamicData} editor={editor} selectedItemId={selectedItemId} />)}</div>;
    } else if (item.type === 'eyebrow') {
        body = <p className="eyebrow">{text(content.text)}</p>;
    } else if (item.type === 'heading') {
        body = <h2>{text(content.text)}</h2>;
    } else if (['paragraph', 'rich_text', 'text'].includes(item.type)) {
        body = <div className="cms-item-copy">{text(content.text).split(/\n{2,}/).filter(Boolean).map((paragraph, index) => <p key={index}>{paragraph}</p>)}</div>;
    } else if (item.type === 'image') {
        body = image ? <SmartLink href={content.url} newTab={Boolean(settings.open_link_new_tab)}><img src={image} alt={text(content.image_alt)} /></SmartLink> : <div className="cms-item-placeholder">Escolhe uma imagem</div>;
    } else if (item.type === 'button') {
        body = <SmartLink className="button" href={content.url} newTab={Boolean(settings.open_link_new_tab)}>{text(content.label) || 'Saber mais'}</SmartLink>;
    } else if (item.type === 'link') {
        body = <SmartLink className="text-link" href={content.url} newTab={Boolean(settings.open_link_new_tab)}>{text(content.label) || 'Saber mais'} <span aria-hidden="true">↗</span></SmartLink>;
    } else if (item.type === 'divider') {
        body = <hr className="cms-element-divider" />;
    } else if (item.type === 'spacer') {
        body = <div className="cms-element-spacer" aria-hidden="true" />;
    } else if (item.type === 'data_collection') {
        body = <DynamicCollection content={content} dynamicData={dynamicData} newTab={Boolean(settings.open_link_new_tab)} editor={Boolean(editor)} />;
    } else if (item.type === 'contact_form') {
        body = <ContactForm />;
    } else if (item.type === 'registration_form') {
        body = <RegistrationForm />;
    } else {
        body = <>{text(content.eyebrow) && <p className="eyebrow">{text(content.eyebrow)}</p>}{image && <div className="cms-item-image"><img src={image} alt={text(content.image_alt)} /></div>}{text(content.title) && <h3>{text(content.title)}</h3>}{text(content.text) && <div className="cms-item-copy">{text(content.text).split(/\n{2,}/).filter(Boolean).map((paragraph, index) => <p key={index}>{paragraph}</p>)}</div>}{text(content.button_label) && <SmartLink className="text-link" href={content.url} newTab={Boolean(settings.open_link_new_tab)}>{text(content.button_label)} <span aria-hidden="true">↗</span></SmartLink>}</>;
    }
    const ratio = style.image_ratio && style.image_ratio !== 'auto' ? style.image_ratio.replace(':', ' / ') : undefined;
    return <div className={classes} style={{ ...sectionItemVariables(style, item.type), animationDelay: `${settings.animation_delay ?? 0}ms`, '--cms-item-image-ratio': ratio || 'auto', '--cms-item-image-fit': style.image_fit || 'cover', '--cms-item-image-position': text(content.image_position) || 'center' } as CSSProperties} onClick={editor ? (event) => { event.preventDefault(); event.stopPropagation(); editor(blockKey, item.id); } : undefined}>{body}</div>;
}

function SectionBlock({ block, dynamicData, editor, selectedItemId }: { block: ManagedBlock; dynamicData: WebsiteDynamicData; editor?: (key: string, itemId?: string) => void; selectedItemId?: string | null }) {
    const content = block.content;
    const layout = {
        '--cms-section-columns': Math.min(6, Math.max(1, number(content.columns_desktop, 3))),
        '--cms-section-columns-tablet': Math.min(4, Math.max(1, number(content.columns_tablet, 2))),
        '--cms-section-columns-mobile': Math.min(2, Math.max(1, number(content.columns_mobile, 1))),
        '--cms-section-gap': `${Math.min(80, Math.max(0, number(content.gap, 20)))}px`,
        '--cms-section-align': text(content.align_items) || 'stretch',
    } as CSSProperties;
    return <section className="cms-builder-section shell"><SectionHeading content={content} /><div className="cms-section-layout" style={layout}>{sectionItems(content.items).map((item) => <RenderSectionItem key={item.id} item={item} blockKey={block.block_key} dynamicData={dynamicData} editor={editor} selectedItemId={selectedItemId} />)}</div></section>;
}

function EditableElementsBlock({ block, dynamicData, editor, selectedItemId }: { block: ManagedBlock; dynamicData: WebsiteDynamicData; editor?: (key: string, itemId?: string) => void; selectedItemId?: string | null }) {
    const content = block.content;
    const layout = {
        '--cms-section-columns': Math.min(6, Math.max(1, number(content.columns_desktop, 6))),
        '--cms-section-columns-tablet': Math.min(4, Math.max(1, number(content.columns_tablet, 2))),
        '--cms-section-columns-mobile': Math.min(2, Math.max(1, number(content.columns_mobile, 1))),
        '--cms-section-gap': `${Math.min(80, Math.max(0, number(content.gap, 20)))}px`,
        '--cms-section-align': text(content.align_items) || 'stretch',
    } as CSSProperties;

    return <section className={`cms-builder-section cms-elements-block cms-elements-${block.type} shell`}><div className="cms-section-layout" style={layout}>{sectionItems(content.elements).map((item) => <RenderSectionItem key={item.id} item={item} blockKey={block.block_key} dynamicData={dynamicData} editor={editor} selectedItemId={selectedItemId} />)}</div></section>;
}

function FormBlock({ content, registration }: { content: BlockContent; registration: boolean }) {
    const steps = strings(content.steps);
    return (
        <section className={`${registration ? 'registration-layout' : 'join-layout'} shell`}>
            <aside><p className="eyebrow">{text(content.eyebrow)}</p><h2>{text(content.title)}</h2><p>{text(content.text)}</p>{steps.length > 0 && <div className="join-steps">{steps.map((step, index) => <span key={step}>{String(index + 1).padStart(2, '0')} · {step}</span>)}</div>}</aside>
            {registration ? <RegistrationForm /> : <ContactForm />}
        </section>
    );
}

function shadowValue(value?: string): string {
    if (value === 'strong') return '0 24px 54px rgba(3, 27, 53, .2)';
    if (value === 'medium') return '0 16px 38px rgba(3, 27, 53, .14)';
    if (value === 'soft') return '0 10px 28px rgba(3, 27, 53, .09)';
    return 'none';
}

function fontFamily(value?: string): string {
    if (value === 'poppins') return 'Poppins, Inter, Arial, sans-serif';
    if (value === 'montserrat') return 'Montserrat, Inter, Arial, sans-serif';
    if (value === 'georgia') return 'Georgia, Times New Roman, serif';
    if (value === 'system') return '-apple-system, BlinkMacSystemFont, Segoe UI, sans-serif';
    return 'Inter, Helvetica Neue, Arial, sans-serif';
}

function blockSpacing(type: string): [number, number] {
    if (type === 'hero') return [18, 0];
    if (['section', 'rich_text', 'cards', 'news_feed', 'events_feed'].includes(type)) return [68, 72];
    if (type === 'stats') return [0, 0];
    if (type === 'cta') return [66, 78];
    if (type === 'contact_form') return [75, 90];
    if (type === 'registration_form') return [70, 92];
    return [64, 64];
}

function blockVariables(style: BlockStyle = {}, type = ''): CSSProperties {
    const [paddingTop, paddingBottom] = blockSpacing(type);
    return {
        '--cms-block-background': style.background_color || 'transparent',
        '--cms-block-text': style.text_color || 'var(--cms-page-text)',
        '--cms-block-heading': style.heading_color || 'var(--cms-page-heading)',
        '--cms-block-accent': style.accent_color || 'var(--cms-page-accent)',
        '--cms-block-padding-top': `${style.padding_top ?? paddingTop}px`,
        '--cms-block-padding-right': `${style.padding_right ?? 0}px`,
        '--cms-block-padding-bottom': `${style.padding_bottom ?? paddingBottom}px`,
        '--cms-block-padding-left': `${style.padding_left ?? 0}px`,
        '--cms-block-margin-top': `${style.margin_top ?? 0}px`,
        '--cms-block-margin-right': `${style.margin_right ?? 0}px`,
        '--cms-block-margin-bottom': `${style.margin_bottom ?? 0}px`,
        '--cms-block-margin-left': `${style.margin_left ?? 0}px`,
        '--cms-block-heading-size': `${style.heading_size ?? 32}px`,
        '--cms-block-body-size': `${style.body_size ?? 14}px`,
        '--cms-block-radius': `${style.border_radius ?? 0}px`,
        '--cms-block-shadow': shadowValue(style.shadow),
        '--cms-card-background': style.card_background || 'var(--surface-raised)',
        '--cms-card-border': style.card_border_color || 'rgba(175,199,211,.9)',
        '--cms-card-radius': `${style.card_radius ?? 15}px`,
        '--cms-card-shadow': shadowValue(style.card_shadow || 'soft'),
        '--cms-card-gap': `${style.card_gap ?? 14}px`,
        '--cms-block-heading-font': style.heading_font && style.heading_font !== 'inherit' ? fontFamily(style.heading_font) : 'var(--cms-heading-font)',
        '--cms-block-body-font': style.body_font && style.body_font !== 'inherit' ? fontFamily(style.body_font) : 'var(--cms-body-font)',
        '--cms-block-heading-weight': style.heading_weight ?? 600,
        '--cms-block-body-weight': style.body_weight ?? 400,
        '--cms-block-line-height': style.line_height ?? 1.6,
        textAlign: style.text_align || 'left',
        backgroundImage: style.background_image ? `url('${style.background_image}')` : undefined,
        backgroundPosition: style.background_position || 'center',
        backgroundSize: style.background_image ? 'cover' : undefined,
    } as CSSProperties;
}

function RenderBlock({ block, dynamicData, editor, selected, selectedItemId }: { block: ManagedBlock; dynamicData: WebsiteDynamicData; editor?: (key: string, itemId?: string) => void; selected?: boolean; selectedItemId?: string | null }) {
    if (!block.is_visible) return null;

    const settings = block.settings || {};
    const style = block.style || {};
    const newTab = Boolean(settings.open_links_new_tab);
    const classes = [
        'cms-block',
        `cms-width-${style.content_width || 'page'}`,
        settings.animation && settings.animation !== 'none' ? `cms-motion-${settings.animation}` : '',
        settings.hide_mobile ? 'cms-hide-mobile' : '',
        settings.hide_desktop ? 'cms-hide-desktop' : '',
        editor ? 'cms-editor-block' : '',
        editor && block.type !== 'section' && !Array.isArray(block.content.elements) ? 'cms-editor-simple' : '',
        selected ? 'is-selected' : '',
    ].filter(Boolean).join(' ');

    let content: ReactNode = null;

    if (Array.isArray(block.content.elements)) {
        content = <EditableElementsBlock block={block} dynamicData={dynamicData} editor={editor} selectedItemId={selectedItemId} />;
    } else {
        switch (block.type) {
            case 'hero': content = <HeroBlock content={block.content} newTab={newTab} />; break;
            case 'section': content = <SectionBlock block={block} dynamicData={dynamicData} editor={editor} selectedItemId={selectedItemId} />; break;
            case 'rich_text': content = <RichTextBlock content={block.content} />; break;
            case 'cards': content = <CardsBlock content={block.content} newTab={newTab} />; break;
            case 'image_text': content = <ImageTextBlock content={block.content} newTab={newTab} />; break;
            case 'stats': content = <StatsBlock content={block.content} />; break;
            case 'cta': content = <CtaBlock content={block.content} newTab={newTab} />; break;
            case 'news_feed': content = <DataFeedBlock content={block.content} defaultSource="news" dynamicData={dynamicData} newTab={newTab} editor={Boolean(editor)} />; break;
            case 'events_feed': content = <DataFeedBlock content={block.content} defaultSource="events" dynamicData={dynamicData} newTab={newTab} editor={Boolean(editor)} />; break;
            case 'contact_form': content = <FormBlock content={block.content} registration={false} />; break;
            case 'registration_form': content = <FormBlock content={block.content} registration />; break;
            default: return null;
        }
    }

    if (!editor && !block.style && !block.settings) {
        return content;
    }

    return (
        <div
            id={settings.anchor_id || undefined}
            className={classes}
            style={{ ...blockVariables(style, block.type), animationDelay: `${settings.animation_delay ?? 0}ms` }}
            onClick={editor ? (event) => { event.preventDefault(); event.stopPropagation(); editor(block.block_key); } : undefined}
        >
            {content}
        </div>
    );
}

function pageVariables(design: PageDesign = {}): CSSProperties {
    return {
        '--cms-page-background': design.background_color || '#ffffff',
        '--cms-page-text': design.text_color || '#102c44',
        '--cms-page-heading': design.heading_color || '#062b54',
        '--cms-page-accent': design.accent_color || '#f2e613',
        '--cms-heading-font': fontFamily(design.heading_font),
        '--cms-body-font': fontFamily(design.body_font),
        '--cms-base-font-size': `${design.base_font_size ?? 16}px`,
        backgroundImage: design.background_image ? `url('${design.background_image}')` : undefined,
        backgroundPosition: design.background_position || 'center top',
        backgroundSize: design.background_image ? 'cover' : undefined,
    } as CSSProperties;
}

export function ManagedPageCanvas({ page, news = [], events = [], partners = [], dynamicData, preview = false, editor, selectedBlockKey, selectedItemId, chrome = true }: { page: ManagedPageData; news?: PublicNews[]; events?: PublicEvent[]; partners?: PublicPartner[]; dynamicData?: WebsiteDynamicData; preview?: boolean; editor?: (key: string, itemId?: string) => void; selectedBlockKey?: string | null; selectedItemId?: string | null; chrome?: boolean }) {
    const widthClass = `cms-page-width-${page.design_settings?.content_width || 'standard'}`;
    const resolvedDynamicData: WebsiteDynamicData = dynamicData || { ...EMPTY_DYNAMIC_DATA, news, events, partners };

    return (
        <div className={`public-site cms-managed-page ${widthClass} ${editor ? 'cms-editor-canvas' : ''}`} style={pageVariables(page.design_settings)}>
            {chrome && <PublicHeader />}
            {preview && <div className="cms-preview-banner">Pré-visualização do rascunho · o website público ainda não foi alterado</div>}
            {page.blocks.map((block) => <RenderBlock key={block.id || block.block_key} block={block} dynamicData={resolvedDynamicData} editor={editor} selected={selectedBlockKey === block.block_key} selectedItemId={selectedBlockKey === block.block_key ? selectedItemId : null} />)}
            {page.blocks.length === 0 && <section className="cms-empty-page shell"><h1>{page.title}</h1><p>Esta página ainda não tem blocos visíveis.</p></section>}
            {chrome && <PublicFooter />}
        </div>
    );
}

export default function ManagedPage({ page, news = [], events = [], partners = [], dynamicData, preview = false }: { page: ManagedPageData; news?: PublicNews[]; events?: PublicEvent[]; partners?: PublicPartner[]; dynamicData?: WebsiteDynamicData; preview?: boolean }) {
    return (
        <PublicPage title={page.meta_title || page.title} description={page.meta_description || page.title}>
            <ManagedPageCanvas page={page} news={news} events={events} partners={partners} dynamicData={dynamicData} preview={preview} chrome={false} />
        </PublicPage>
    );
}
