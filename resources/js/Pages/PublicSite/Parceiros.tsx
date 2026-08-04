import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';

export default function Parceiros() {
    return (
        <PublicPage title="Parceiros" description="Conhece as formas de apoiar o Benedita Sport Club Natação e o desporto local.">
            <PageHero eyebrow="Parceiros" title={<>Apoiar o desporto local<br />é investir em continuidade.</>} text="Empresas e instituições que ajudam a transformar trabalho, talento e comunidade em oportunidades reais." image="/site-assets/bscn-club-bright.webp" imagePosition="center 54%" />
            <section className="editorial-intro shell"><p className="section-index">01 / Parcerias</p><div><p className="eyebrow">Valor partilhado</p><h2>Uma parceria deve criar impacto, não apenas ocupar espaço num cartaz.</h2><p className="lead">Construímos relações ajustadas aos objetivos de cada parceiro e às necessidades do clube, com presença, proximidade e uma ligação genuína à comunidade.</p></div></section>
            <section className="partner-options shell"><article><span>01</span><h3>Parceiro de época</h3><p>Associação continuada ao projeto competitivo e à comunicação institucional do clube.</p></article><article><span>02</span><h3>Parceiro de evento</h3><p>Apoio orientado para uma competição, estágio, iniciativa ou momento específico.</p></article><article><span>03</span><h3>Apoio em bens ou serviços</h3><p>Contributos materiais ou especializados que reduzam custos e melhorem condições.</p></article></section>
            <section className="home-cta partner-cta shell"><div><p className="eyebrow">Vamos conversar</p><h2>Há muitas formas de fazer parte deste percurso.</h2></div><div><p>Partilha connosco o que procuras. Preparamos uma proposta ajustada ao parceiro e às necessidades reais do clube.</p><a className="button" href="mailto:geral@bscn.pt?subject=Parceria%20com%20o%20BSCN">Quero ser parceiro</a></div></section>
        </PublicPage>
    );
}
