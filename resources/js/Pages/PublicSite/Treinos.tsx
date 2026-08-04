import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';

const groups = [
    ['A partir dos 6 anos', 'Formação competitiva', 'Integração progressiva para jovens com bases de natação e vontade de iniciar um percurso competitivo.'],
    ['Juvenis a seniores', 'Competição', 'Treino orientado para evolução técnica, condição física, participação em provas e objetivos individuais e coletivos.'],
    ['Sem limite de idade', 'Masters', 'Preparação para atletas adultos que querem competir, melhorar marcas e fazer parte de uma equipa.'],
    ['Outras modalidades', 'Treino complementar', 'Natação específica para triatletas e outros praticantes que procuram melhorar técnica, eficiência e resistência.'],
];

export default function Treinos() {
    return (
        <PublicPage title="Treinos" description="Grupos, enquadramento e informação prática sobre os treinos de natação do BSCN.">
            <PageHero eyebrow="Treinos" title={<>Cada grupo tem o seu ritmo. O método é comum.</>} text="Grupos organizados pela equipa técnica, de acordo com idade, experiência, objetivos e disponibilidade de pistas." image="/site-assets/bscn-training-bright.webp" imagePosition="center 50%" />
            <section className="training-overview shell"><p className="section-index">01 / Organização</p><div><p className="eyebrow">Treinar com propósito</p><h2>O horário é importante. O enquadramento certo é decisivo.</h2><p>Antes da integração, a equipa técnica analisa o percurso do atleta e identifica o grupo adequado. O BSCN não funciona como escola de aprendizagem: o objetivo é desenvolver atletas para a prática competitiva ou complementar o trabalho de outras modalidades.</p></div></section>
            <section className="training-groups shell">{groups.map(([label, title, text]) => <article className="training-group" id={title.toLowerCase().replace(' ', '-')} key={title}><span>{label}</span><h3>{title}</h3><p>{text}</p></article>)}</section>
            <section className="notice shell"><p className="eyebrow">Horários 2026/27</p><div><h2>A distribuição definitiva será publicada após confirmação das pistas.</h2><p>Até lá, podes enviar o pedido de inscrição e indicar a tua disponibilidade. O clube entrará em contacto quando houver informação confirmada para o grupo correspondente.</p></div></section>
            <section className="practical-grid shell"><article><h3>Local</h3><p>Piscinas Municipais da Benedita, concelho de Alcobaça.</p></article><article><h3>O que trazer</h3><p>Após o primeiro contacto, a equipa técnica indica o material adequado ao grupo e à avaliação.</p></article><article><h3>Como começar</h3><p>Preenche o formulário de inscrição. Não é necessário deslocares-te sem marcação prévia.</p></article></section>
            <section className="soft-cta shell"><div><p className="eyebrow">Primeiro contacto</p><h2>Queres perceber qual é o grupo adequado para ti?</h2></div><Link className="button" href="/junta-te">Pedir avaliação</Link></section>
        </PublicPage>
    );
}
