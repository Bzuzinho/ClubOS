import '../../css/public-site.css';

import { Head, Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

const defaultLinks = [
    ['O clube', '/clube'],
    ['Natação', '/competicao'],
    ['Treinos', '/treinos'],
    ['Notícias', '/noticias'],
    ['Calendário', '/calendario'],
    ['Parceiros', '/parceiros'],
    ['Contactos', '/contactos'],
];

export function PublicHeader() {
    const { publicNavigation } = usePage<{ publicNavigation?: Array<{ label: string; href: string }> }>().props;
    const links = publicNavigation?.length
        ? publicNavigation.map((item) => [item.label, item.href])
        : defaultLinks;

    return (
        <>
            <div className="utility-bar">
                <div className="shell utility-inner">
                    <span>Natação de competição na Benedita</span>
                    <a href="/login">Área reservada · ClubOS <span aria-hidden="true">↗</span></a>
                </div>
            </div>
            <header className="site-header">
                <Link className="brand" href="/" aria-label="BSCN — página inicial">
                    <img src="/site-assets/bscn-logo.svg" alt="BSCN" width="46" height="46" />
                    <span><strong>BSCN</strong><small>Benedita Sport Club Natação</small></span>
                </Link>
                <nav className="desktop-nav" aria-label="Navegação principal">
                    {links.map(([label, href]) => <Link href={href} key={label}>{label}</Link>)}
                </nav>
                <div className="header-actions">
                    <Link className="button button-small" href="/inscricao">Inscreve-te</Link>
                    <details className="mobile-menu">
                        <summary aria-label="Abrir menu"><span>Menu</span><b aria-hidden="true">≡</b></summary>
                        <nav>
                            {links.map(([label, href]) => <Link href={href} key={label}>{label}</Link>)}
                            <Link href="/inscricao">Inscreve-te</Link>
                            <Link href="/junta-te">Pedir contacto</Link>
                            <a href="/login">Área reservada · ClubOS ↗</a>
                        </nav>
                    </details>
                </div>
            </header>
        </>
    );
}

export function PublicFooter() {
    return (
        <footer>
            <div className="shell footer-main">
                <div className="footer-brand">
                    <img src="/site-assets/bscn-logo.svg" alt="" width="58" height="58" />
                    <div><strong>BSCN</strong><p>Benedita Sport Club Natação<br />Competição desde 2008.</p></div>
                </div>
                <div><p className="footer-label">Clube</p><Link href="/clube">Quem somos</Link><Link href="/competicao">Projeto desportivo</Link><Link href="/treinos">Treinos e escalões</Link></div>
                <div><p className="footer-label">Informação</p><Link href="/noticias">Notícias</Link><Link href="/calendario">Calendário</Link><Link href="/parceiros">Parceiros</Link><Link href="/contactos">Contactos</Link></div>
                <div><p className="footer-label">Participar</p><Link href="/inscricao">Inscreve-te</Link><Link href="/junta-te">Pedir contacto</Link><a href="/login">Área reservada · ClubOS ↗</a><a href="mailto:geral@bscn.pt">geral@bscn.pt</a></div>
            </div>
            <div className="shell footer-bottom"><span>© 2026 BSCN · Benedita, Alcobaça</span><Link href="/privacidade">Privacidade e cookies</Link></div>
        </footer>
    );
}

export function PageHero({
    eyebrow,
    title,
    text,
    image,
    imagePosition = 'center',
}: {
    eyebrow: string;
    title: ReactNode;
    text: string;
    image?: string;
    imagePosition?: string;
}) {
    return (
        <section className="page-hero">
            <div className="shell page-hero-grid">
                <div className="page-hero-content">
                    <p className="eyebrow">{eyebrow}</p>
                    <h1>{title}</h1>
                    <p>{text}</p>
                </div>
                <div className="page-hero-image" style={{ backgroundImage: image ? `url('${image}')` : undefined, backgroundPosition: imagePosition }} role="img" aria-label="Ambiente de natação do BSCN" />
            </div>
        </section>
    );
}

export function PublicPage({ title, description, children }: PropsWithChildren<{ title: string; description: string }>) {
    return (
        <main className="public-site">
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={`${title} — BSCN`} />
                <meta property="og:description" content={description} />
            </Head>
            <PublicHeader />
            {children}
            <PublicFooter />
        </main>
    );
}
