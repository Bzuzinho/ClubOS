import { PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import { PublicEvent, PublicNews, eventDateParts, newsDate } from './types';

const programmes = [
    { label: 'A partir dos 6 anos', title: 'Formação competitiva', text: 'Avaliação técnica e integração progressiva no grupo adequado.', href: '/competicao#formacao' },
    { label: 'Evolução e prova', title: 'Competição', text: 'Treino estruturado para evoluir e construir resultados consistentes.', href: '/competicao#competicao' },
    { label: 'Sem limite de idade', title: 'Masters', text: 'Competição com método, objetivos e espírito de equipa.', href: '/competicao#masters' },
    { label: 'Triatlo e outras modalidades', title: 'Treino complementar', text: 'Trabalho específico de natação ajustado a outros objetivos desportivos.', href: '/competicao#complementar' },
];

const fallbackNews: PublicNews[] = [
    { id: 'season', title: 'Uma época termina. A próxima já começou.', excerpt: 'O fecho de um ciclo competitivo abre espaço para planear grupos, objetivos e uma nova época mais sólida.', image: '/site-assets/bscn-news-bright.webp', featured: true, publishedAt: '2026-07-07T12:00:00Z', category: 'Clube' },
    { id: 'masters', title: 'Inscrições Masters 2026/27', excerpt: 'Continuar a competir, melhorar marcas e treinar em equipa não tem prazo de validade.', image: '/site-assets/bscn-masters-bright.webp', featured: false, publishedAt: '2026-07-02T12:00:00Z', category: 'Masters' },
    { id: 'recruitment', title: 'Novos atletas para 2026/27', excerpt: 'O primeiro passo é um pedido de contacto e uma avaliação pela equipa técnica.', image: '/site-assets/bscn-training-bright.webp', featured: false, publishedAt: '2026-06-30T12:00:00Z', category: 'Captação' },
];

const fallbackEvents = [
    { day: '24', month: 'OUT', title: 'Qualificação — Clubes 3.ª Divisão', meta: 'Sines · Calendário FPN' },
    { day: '27', month: 'NOV', title: 'Campeonato Nacional de Clubes 3.ª Divisão', meta: 'Calendário nacional FPN' },
    { day: '04–06', month: 'DEZ', title: 'Torneio Zonal de Juvenis — Zona Sul', meta: 'Leiria · Calendário FPN' },
];

export default function Home({ news, events }: { news: PublicNews[]; events: PublicEvent[] }) {
    const stories = [...news, ...fallbackNews].slice(0, 3);
    const agenda = events.length
        ? events.map((event) => {
              const parts = eventDateParts(event.startDate);
              return { day: parts.day, month: parts.month, title: event.title, meta: [event.place, event.startTime].filter(Boolean).join(' · ') || 'Calendário BSCN' };
          })
        : fallbackEvents;

    return (
        <PublicPage title="BSCN — Natação de competição na Benedita" description="Benedita Sport Club Natação. Competição dos 6 anos aos Masters e treino complementar para atletas de outras modalidades.">
            <section className="home-hero" id="top">
                <div className="shell home-hero-grid">
                    <div className="home-hero-copy">
                        <p className="eyebrow">Benedita · Natação de competição</p>
                        <h1>Trabalho, ritmo e ambição.</h1>
                        <p>Dos 6 anos aos Masters, treinamos para evoluir — com método, compromisso e uma equipa que cresce dentro e fora de água.</p>
                        <div className="hero-actions"><Link className="button" href="/inscricao">Inscreve-te</Link><Link className="text-link" href="/clube">Conhecer o clube <span>↗</span></Link></div>
                    </div>
                    <div className="home-hero-media" role="img" aria-label="Piscina de competição iluminada"><div className="hero-badge"><strong>2008</strong><span>Competição<br />na Benedita</span></div></div>
                </div>
            </section>

            <section className="announcement"><div className="shell announcement-inner"><span className="status-dot" aria-hidden="true" /><p><strong>Captação 2026/27</strong> · Pedidos de avaliação para atletas e treino complementar.</p><Link href="/junta-te">Pedir contacto <span>↗</span></Link></div></section>

            <section className="updates-section shell">
                <div className="section-heading compact-heading"><div><p className="eyebrow">Atualidade</p><h2>Notícias e próximas datas.</h2></div><div className="heading-links"><Link className="text-link" href="/noticias">Todas as notícias <span>↗</span></Link><Link className="text-link" href="/calendario">Calendário completo <span>↗</span></Link></div></div>
                <div className="updates-grid">
                    <div className="news-highlight-grid">
                        <article className="lead-news"><div className="lead-news-image" style={{ backgroundImage: `url('${stories[0].image || '/site-assets/bscn-news-bright.webp'}')` }} /><div className="lead-news-copy"><p className="story-meta">{newsDate(stories[0].publishedAt)} · {stories[0].category}</p><h3>{stories[0].title}</h3><p>{stories[0].excerpt}</p><Link href="/noticias">Ler notícia <span>↗</span></Link></div></article>
                        <div className="mini-news-list">{stories.slice(1, 3).map((story, index) => <article className="mini-news" key={story.id}><div className="mini-news-image" style={{ backgroundImage: `url('${story.image || (index ? '/site-assets/bscn-training-bright.webp' : '/site-assets/bscn-masters-bright.webp')}')` }} /><div><p className="story-meta">{newsDate(story.publishedAt)} · {story.category}</p><h3>{story.title}</h3><p>{story.excerpt}</p><Link href="/noticias">Ler mais <span>↗</span></Link></div></article>)}</div>
                    </div>
                    <aside className="agenda-card"><div className="agenda-head"><div><p className="eyebrow">Agenda 2026/27</p><h3>Próximas datas</h3></div><span>Atualização<br />automática</span></div><div className="event-list">{agenda.slice(0, 3).map((event) => <article className="event-row" key={`${event.day}-${event.title}`}><time><strong>{event.day}</strong><span>{event.month}</span></time><div><h4>{event.title}</h4><p>{event.meta}</p></div></article>)}</div><p className="agenda-note">Os eventos do clube são publicados pelo ClubOS. Datas nacionais dependem de convocatória técnica.</p><Link className="button button-outline agenda-button" href="/calendario">Ver calendário 2026/27</Link></aside>
                </div>
            </section>

            <section className="home-intro shell"><div><p className="eyebrow">Um clube com direção</p><h2>Não somos uma escola de aprendizagem. Somos um projeto de competição.</h2></div><div className="home-intro-copy"><p>O BSCN acompanha cada atleta de acordo com a idade, experiência e objetivos. Técnica, compromisso e evolução fazem parte do mesmo percurso — desde os primeiros anos de competição até aos Masters.</p><div className="quick-facts"><div><strong>6+</strong><span>idade de entrada</span></div><div><strong>4</strong><span>percursos desportivos</span></div><div><strong>1</strong><span>equipa, muitas etapas</span></div></div></div></section>

            <section className="programmes-section"><div className="shell"><div className="section-heading"><div><p className="eyebrow">Encontra o teu percurso</p><h2>Natação com um lugar para cada ambição.</h2></div><Link className="text-link" href="/competicao">Ver projeto desportivo <span>↗</span></Link></div><div className="programme-cards">{programmes.map((item) => <Link href={item.href} className="programme-card" key={item.title}><span>{item.label}</span><h3>{item.title}</h3><p>{item.text}</p><b aria-hidden="true">↗</b></Link>)}</div></div></section>

            <section className="training-feature shell"><div className="training-image" role="img" aria-label="Pistas de uma piscina de competição" /><div className="training-copy"><p className="eyebrow">Treinar na Benedita</p><h2>Informação prática, sem caça ao tesouro.</h2><p>Treinamos nas Piscinas Municipais da Benedita. Os horários variam por escalão e serão publicados assim que a distribuição de pistas para 2026/27 estiver confirmada.</p><ul><li>Grupos definidos pela equipa técnica</li><li>Horários adequados a cada escalão</li><li>Avaliação antes da integração</li></ul><Link className="button button-outline" href="/treinos">Treinos e horários</Link></div></section>

            <section className="clubos-section shell"><div className="clubos-mark"><img src="/site-assets/bscn-logo.svg" alt="" width="70" height="70" /></div><div className="clubos-copy"><p className="eyebrow">Área reservada</p><h2>ClubOS — a área digital do clube.</h2><p>Acesso para atletas, encarregados de educação, treinadores e direção.</p><ul><li>Treinos e agenda</li><li>Documentos e pagamentos</li><li>Comunicação do clube</li></ul></div><div className="clubos-action"><a className="button" href="/login">Entrar com a minha conta ↗</a><small>Acesso seguro à aplicação do clube</small></div></section>

            <section className="home-cta shell"><div><p className="eyebrow">Próxima etapa</p><h2>Queres saber se o BSCN é o clube certo para ti?</h2></div><div><p>Se já decidiste avançar, inicia o registo de atleta. Se ainda tens dúvidas, fala primeiro connosco.</p><div className="cta-actions"><Link className="button" href="/inscricao">Inscreve-te</Link><Link className="text-link" href="/junta-te">Pedir contacto <span>↗</span></Link></div></div></section>
        </PublicPage>
    );
}
