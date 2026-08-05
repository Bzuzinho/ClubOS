import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';
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
};

type ManagedPageData = {
    slug: string;
    title: string;
    meta_title?: string | null;
    meta_description?: string | null;
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

function SmartLink({ href, className, children }: { href: unknown; className?: string; children: ReactNode }) {
    const safe = safeHref(href);
    if (safe.startsWith('http') || safe.startsWith('mailto:') || safe.startsWith('tel:')) {
        return <a className={className} href={safe} target={safe.startsWith('http') ? '_blank' : undefined} rel={safe.startsWith('http') ? 'noreferrer' : undefined}>{children}</a>;
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

function HeroBlock({ content }: { content: BlockContent }) {
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
                    {primaryLabel && <SmartLink className="button" href={content.primary_url}>{primaryLabel}</SmartLink>}
                    {secondaryLabel && <SmartLink className="text-link" href={content.secondary_url}>{secondaryLabel} <span>↗</span></SmartLink>}
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

function CardsBlock({ content }: { content: BlockContent }) {
    const cards = items(content.items);
    const columns = Math.min(4, Math.max(1, number(content.columns, 3)));

    return (
        <section className="cms-section shell">
            <SectionHeading content={content} />
            <div className={`cms-cards cms-columns-${columns}`}>
                {cards.map((card, index) => {
                    const article = <article><span>{card.label}</span><h3>{card.title}</h3><p>{card.text}</p>{card.url && <b aria-hidden="true">↗</b>}</article>;
                    return card.url ? <SmartLink href={card.url} key={`${card.title}-${index}`}>{article}</SmartLink> : <div key={`${card.title}-${index}`}>{article}</div>;
                })}
            </div>
        </section>
    );
}

function ImageTextBlock({ content }: { content: BlockContent }) {
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
                {text(content.button_label) && <SmartLink className="button button-outline" href={content.button_url}>{text(content.button_label)}</SmartLink>}
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

function CtaBlock({ content }: { content: BlockContent }) {
    return (
        <section className="cms-cta shell">
            <div><p className="eyebrow">{text(content.eyebrow)}</p><h2>{text(content.title)}</h2><p>{text(content.text)}</p></div>
            <div className="cta-actions">
                {text(content.button_label) && <SmartLink className="button" href={content.button_url}>{text(content.button_label)}</SmartLink>}
                {text(content.secondary_label) && <SmartLink className="text-link" href={content.secondary_url}>{text(content.secondary_label)} <span>↗</span></SmartLink>}
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

function RenderBlock({ block, news, events }: { block: ManagedBlock; news: PublicNews[]; events: PublicEvent[] }) {
    if (!block.is_visible) return null;

    switch (block.type) {
        case 'hero': return <HeroBlock content={block.content} />;
        case 'rich_text': return <RichTextBlock content={block.content} />;
        case 'cards': return <CardsBlock content={block.content} />;
        case 'image_text': return <ImageTextBlock content={block.content} />;
        case 'stats': return <StatsBlock content={block.content} />;
        case 'cta': return <CtaBlock content={block.content} />;
        case 'news_feed': return <NewsBlock content={block.content} news={news} />;
        case 'events_feed': return <EventsBlock content={block.content} events={events} />;
        case 'contact_form': return <FormBlock content={block.content} registration={false} />;
        case 'registration_form': return <FormBlock content={block.content} registration />;
        default: return null;
    }
}

export default function ManagedPage({ page, news = [], events = [], preview = false }: { page: ManagedPageData; news?: PublicNews[]; events?: PublicEvent[]; preview?: boolean }) {
    return (
        <PublicPage title={page.meta_title || page.title} description={page.meta_description || page.title}>
            {preview && <div className="cms-preview-banner">Pré-visualização do rascunho · o website público ainda não foi alterado</div>}
            {page.blocks.map((block) => <RenderBlock key={block.id || block.block_key} block={block} news={news} events={events} />)}
            {page.blocks.length === 0 && <section className="cms-empty-page shell"><h1>{page.title}</h1><p>Esta página ainda não tem blocos visíveis.</p></section>}
        </PublicPage>
    );
}
