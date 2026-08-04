import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';

export default function Contactos() {
    return (
        <PublicPage title="Contactos" description="Contacta o BSCN para inscrições, treinos, parcerias ou apoio à área reservada.">
            <PageHero eyebrow="Contactos" title={<>Fala com a pessoa certa, sem voltas à piscina.</>} text="Inscrições, informação desportiva, parcerias ou apoio: escolhe o assunto e entra em contacto connosco." image="/site-assets/bscn-hero-bright.webp" imagePosition="center 52%" />
            <section className="contact-layout shell">
                <div><p className="eyebrow">Estamos disponíveis</p><h2>Como podemos ajudar?</h2><p>Para tornar a resposta mais rápida, indica no assunto se o contacto é sobre inscrição, treinos, parceria ou apoio à área reservada.</p></div>
                <div className="contact-cards">
                    <article className="contact-card"><span>Inscrição</span><h3>Quero treinar no BSCN</h3><Link href="/inscricao">Iniciar registo de atleta ↗</Link></article>
                    <article className="contact-card"><span>Pedido de contacto</span><h3>Ainda tenho dúvidas</h3><Link href="/junta-te">Pedir contacto ao clube ↗</Link></article>
                    <article className="contact-card"><span>Informação geral</span><h3>Clube e atividade desportiva</h3><a href="mailto:geral@bscn.pt?subject=Pedido%20de%20informação">geral@bscn.pt</a></article>
                    <article className="contact-card"><span>Parcerias</span><h3>Quero apoiar o clube</h3><a href="mailto:geral@bscn.pt?subject=Parceria%20com%20o%20BSCN">Apresentar proposta ↗</a></article>
                    <article className="contact-card"><span>Área reservada</span><h3>Acesso ao ClubOS</h3><a href="/login">Entrar na aplicação ↗</a></article>
                </div>
            </section>
            <section className="location-band shell"><div><p className="eyebrow">Onde treinamos</p><h2>Piscinas Municipais da Benedita</h2><p>Benedita, concelho de Alcobaça, Portugal.</p></div><div><p className="eyebrow">Antes de te deslocares</p><h2>Marca primeiro o contacto.</h2><p>Os grupos têm horários e condições específicas. Para inscrições ou avaliações, usa o formulário para que a equipa possa preparar o enquadramento adequado.</p></div></section>
        </PublicPage>
    );
}
