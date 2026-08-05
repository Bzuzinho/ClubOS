import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { PublicFooter, PublicHeader } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import { CSSProperties, ReactNode } from 'react';
import ContactForm from './ContactForm';
import RegistrationForm from './RegistrationForm';
import { PublicEvent, PublicNews, eventDateParts, newsDate } from './types';

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
    padding_bottom?: number;
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
};

type ManagedPageData = {
    slug: string;
    title: string;
    meta_title?: string | null;
    meta_description?: string | null;
    design_settings?: PageDesign;
    blocks: ManagedBlock[];
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
    if (['rich_text', 'cards', 'news_feed', 'events_feed'].includes(type)) return [68, 72];
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
        '--cms-block-padding-bottom': `${style.padding_bottom ?? paddingBottom}px`,
        '--cms-block-heading-size': `${style.heading_size ?? 32}px`,
        '--cms-block-body-size': `${style.body_size ?? 14}px`,
        '--cms-block-radius': `${style.border_radius ?? 0}px`,
        '--cms-block-shadow': shadowValue(style.shadow),
        '--cms-card-background': style.card_background || 'var(--surface-raised)',
        '--cms-card-border': style.card_border_color || 'rgba(175,199,211,.9)',
        '--cms-card-radius': `${style.card_radius ?? 15}px`,
        '--cms-card-shadow': shadowValue(style.card_shadow || 'soft'),
        '--cms-card-gap': `${style.card_gap ?? 14}px`,
        textAlign: style.text_align || 'left',
    } as CSSProperties;
}

function RenderBlock({ block, news, events, editor, selected }: { block: ManagedBlock; news: PublicNews[]; events: PublicEvent[]; editor?: (key: string) => void; selected?: boolean }) {
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
        selected ? 'is-selected' : '',
    ].filter(Boolean).join(' ');

    let content: ReactNode = null;

    switch (block.type) {
        case 'hero': content = <HeroBlock content={block.content} newTab={newTab} />; break;
        case 'rich_text': content = <RichTextBlock content={block.content} />; break;
        case 'cards': content = <CardsBlock content={block.content} newTab={newTab} />; break;
        case 'image_text': content = <ImageTextBlock content={block.content} newTab={newTab} />; break;
        case 'stats': content = <StatsBlock content={block.content} />; break;
        case 'cta': content = <CtaBlock content={block.content} newTab={newTab} />; break;
        case 'news_feed': content = <NewsBlock content={block.content} news={news} />; break;
        case 'events_feed': content = <EventsBlock content={block.content} events={events} />; break;
        case 'contact_form': content = <FormBlock content={block.content} registration={false} />; break;
        case 'registration_form': content = <FormBlock content={block.content} registration />; break;
        default: return null;
    }

    if (!editor && !block.style && !block.settings) {
        return content;
    }

    return (
        <div
            id={settings.anchor_id || undefined}
            className={classes}
            style={{ ...blockVariables(style, block.type), animationDelay: `${settings.animation_delay ?? 0}ms` }}
            onClickCapture={editor ? (event) => { event.preventDefault(); event.stopPropagation(); editor(block.block_key); } : undefined}
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
    } as CSSProperties;
}

export function ManagedPageCanvas({ page, news = [], events = [], preview = false, editor, selectedBlockKey, chrome = true }: { page: ManagedPageData; news?: PublicNews[]; events?: PublicEvent[]; preview?: boolean; editor?: (key: string) => void; selectedBlockKey?: string | null; chrome?: boolean }) {
    const widthClass = `cms-page-width-${page.design_settings?.content_width || 'standard'}`;

    return (
        <div className={`public-site cms-managed-page ${widthClass} ${editor ? 'cms-editor-canvas' : ''}`} style={pageVariables(page.design_settings)}>
            {chrome && <PublicHeader />}
            {preview && <div className="cms-preview-banner">Pré-visualização do rascunho · o website público ainda não foi alterado</div>}
            {page.blocks.map((block) => <RenderBlock key={block.id || block.block_key} block={block} news={news} events={events} editor={editor} selected={selectedBlockKey === block.block_key} />)}
            {page.blocks.length === 0 && <section className="cms-empty-page shell"><h1>{page.title}</h1><p>Esta página ainda não tem blocos visíveis.</p></section>}
            {chrome && <PublicFooter />}
        </div>
    );
}

export default function ManagedPage({ page, news = [], events = [], preview = false }: { page: ManagedPageData; news?: PublicNews[]; events?: PublicEvent[]; preview?: boolean }) {
    return (
        <PublicPage title={page.meta_title || page.title} description={page.meta_description || page.title}>
            <ManagedPageCanvas page={page} news={news} events={events} preview={preview} chrome={false} />
        </PublicPage>
    );
}
