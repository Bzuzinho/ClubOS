import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { PublicNews, newsDate } from './types';

const fallbackNews: PublicNews[] = [
    { id: '1', title: 'Uma época termina. A próxima já começou.', excerpt: 'O fecho de um ciclo competitivo abre espaço para planear grupos, objetivos e uma nova época mais sólida.', image: '/site-assets/bscn-news-bright.webp', featured: true, publishedAt: '2026-07-07T12:00:00Z', category: 'Clube' },
    { id: '2', title: 'Inscrições Masters 2026/27', excerpt: 'Competir continua a ser uma boa ideia — e fazê-lo em equipa ainda mais.', image: '/site-assets/bscn-masters-bright.webp', featured: false, publishedAt: '2026-07-02T12:00:00Z', category: 'Masters' },
    { id: '3', title: 'Novos atletas para 2026/27', excerpt: 'O processo começa com um pedido de contacto e uma avaliação da equipa técnica.', image: '/site-assets/bscn-training-bright.webp', featured: false, publishedAt: '2026-06-30T12:00:00Z', category: 'Captação' },
    { id: '4', title: 'Uma equipa construída todos os dias', excerpt: 'Treino, entreajuda e compromisso: a parte do resultado que começa muito antes da prova.', image: '/site-assets/bscn-hero-bright.webp', featured: false, publishedAt: '2026-06-23T12:00:00Z', category: 'Equipa' },
    { id: '5', title: 'A técnica que ninguém vê decide a prova que todos veem', excerpt: 'Pequenos ajustes, repetidos com consistência, transformam a forma de nadar.', image: '/site-assets/bscn-training-bright.webp', featured: false, publishedAt: '2026-06-17T12:00:00Z', category: 'Treino' },
    { id: '6', title: 'Um projeto local com horizonte largo', excerpt: 'Crescer sem perder a identidade do lugar onde tudo começou.', image: '/site-assets/bscn-club-bright.webp', featured: false, publishedAt: '2026-05-20T12:00:00Z', category: 'Benedita' },
];

export default function Noticias({ news }: { news: PublicNews[] }) {
    const stories = news.length ? news : fallbackNews;

    return (
        <PublicPage title="Notícias" description="Notícias, convocatórias públicas e atualidade do Benedita Sport Club Natação.">
            <PageHero eyebrow="Notícias" title={<>O clube está sempre em movimento.</>} text="Informação sobre a época, competições, convocatórias, conquistas e a vida diária do BSCN." image="/site-assets/bscn-news-bright.webp" imagePosition="center 50%" />
            <section className="stories shell">
                <div className="stories-head"><div><p className="eyebrow">Do BSCN</p><h2>Notícias e histórias do clube</h2></div><p>As publicações criadas no ClubOS aparecem automaticamente nesta página, com os destaques mais recentes em primeiro lugar.</p></div>
                <div className="story-grid">{stories.map((story, index) => <article className={index === 0 ? 'featured-story' : ''} key={story.id}><div className="story-visual" style={{ backgroundImage: `url('${story.image || '/site-assets/bscn-news-bright.webp'}')` }} /><p className="story-meta">{newsDate(story.publishedAt)} · {story.category.toUpperCase()}</p><h3>{story.title}</h3><p>{story.excerpt}</p><a href="https://www.instagram.com/benedita_sc_natacao/" target="_blank" rel="noreferrer">Ver atualidade <span>↗</span></a></article>)}</div>
            </section>
            <section className="social-note shell"><span>↗</span><div><p className="eyebrow">Informação centralizada</p><h2>Uma publicação. Informação sempre atualizada.</h2><p>Notícias e calendário público são geridos no ClubOS e apresentados automaticamente no website.</p></div></section>
        </PublicPage>
    );
}
