import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';

const pathways = [
    ['formacao', 'Formação competitiva', 'Uma base técnica exigente e progressiva para jovens atletas a partir dos 6 anos.'],
    ['competicao', 'Competição', 'Planeamento orientado para rendimento, evolução individual e objetivos de equipa.'],
    ['masters', 'Masters', 'Competir, evoluir e pertencer a uma equipa sem que a idade seja um ponto final.'],
    ['complementar', 'Treino complementar', 'Trabalho de natação para triatletas e praticantes de outras modalidades.'],
];

export default function Competicao() {
    return (
        <PublicPage title="Natação de competição" description="Formação competitiva, competição, Masters e treino complementar no BSCN.">
            <PageHero eyebrow="Competição" title={<>Precisão na água.<br />Intenção em cada treino.</>} text="Não ensinamos apenas a nadar mais depressa. Ensinamos a compreender o trabalho que torna isso possível." image="/site-assets/bscn-training-bright.webp" imagePosition="center 50%" />
            <section className="editorial-intro shell"><p className="section-index">01 / Projeto desportivo</p><div><p className="eyebrow">Competir é consequência</p><h2>A evolução nasce de um plano, não de um slogan.</h2><p className="lead">O BSCN é exclusivamente um clube de competição. Cada atleta é enquadrado de acordo com idade, experiência, objetivos e disponibilidade, com avaliação da equipa técnica.</p></div></section>
            <section className="pathways shell">{pathways.map(([id, title, text], index) => <article id={id} key={title}><span>{String(index + 1).padStart(2, '0')}</span><div><h2>{title}</h2><p>{text}</p></div><b>↗</b></article>)}</section>
            <section className="split-feature"><div className="split-image competition-detail" /><div className="split-copy"><p className="eyebrow light">Método</p><h2>Treinar melhor é tornar visível aquilo que ainda pode evoluir.</h2><p>Técnica, condição física, consistência e leitura da prova fazem parte do mesmo processo. Os objetivos ajustam-se; a exigência de trabalhar com clareza mantém-se.</p><Link className="text-link light" href="/junta-te">Pedir avaliação técnica <span>↗</span></Link></div></section>
            <section className="notice shell"><p className="eyebrow">Horários 2026/27</p><div><h2>Os horários definitivos serão publicados após confirmação das pistas.</h2><p>Preferimos uma informação certa um pouco mais tarde do que uma tabela bonita e errada. Entretanto, podes indicar a tua disponibilidade no formulário.</p></div></section>
        </PublicPage>
    );
}
