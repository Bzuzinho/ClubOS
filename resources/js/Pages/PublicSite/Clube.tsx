import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';

export default function Clube() {
    return (
        <PublicPage title="O clube" description="Conhece o Benedita Sport Club Natação, um projeto de competição para atletas dos 6 anos aos Masters.">
            <PageHero eyebrow="O clube" title={<>Mais do que uma equipa.<br />Um percurso partilhado.</>} text="O BSCN nasceu na Benedita e cresceu com uma ideia simples: dar à ambição uma estrutura onde possa evoluir." image="/site-assets/bscn-club-bright.webp" imagePosition="center 55%" />
            <section className="editorial-intro shell"><p className="section-index">01 / Identidade</p><div><p className="eyebrow">Desde 2008</p><h2>O clube é aquilo que acontece entre o esforço individual e o objetivo coletivo.</h2><p className="lead">Treinamos atletas. Mas também construímos hábitos, relações e uma cultura que continua depois de sair da água. Na Benedita, a natação de competição tem casa — e essa casa cresce com cada geração.</p></div></section>
            <section className="fact-band"><div className="shell fact-grid"><article><strong>2008</strong><span>Ano de fundação</span></article><article><strong>6+</strong><span>Idade de entrada</span></article><article><strong>∞</strong><span>Sem limite nos Masters</span></article><article><strong>1</strong><span>Projeto competitivo</span></article></div></section>
            <section className="values shell"><div><p className="eyebrow">A nossa forma de estar</p><h2>Exigentes no trabalho.<br />Claros no propósito.</h2></div><div className="value-list"><article><span>01</span><h3>Ambição</h3><p>Querer mais é saudável quando se aprende a trabalhar melhor.</p></article><article><span>02</span><h3>Consistência</h3><p>O talento chama a atenção. A regularidade é o que muda resultados.</p></article><article><span>03</span><h3>Comunidade</h3><p>Ninguém treina sozinho, mesmo quando a prova tem apenas uma pista.</p></article><article><span>04</span><h3>Responsabilidade</h3><p>Com atletas, famílias, equipa técnica e a comunidade que nos apoia.</p></article></div></section>
            <section className="soft-cta shell"><div><p className="eyebrow">O próximo capítulo</p><h2>O clube continua a ser escrito por quem entra.</h2></div><Link className="button" href="/junta-te">Conhecer as condições</Link></section>
        </PublicPage>
    );
}
