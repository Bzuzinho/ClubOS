import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import RegistrationForm from './RegistrationForm';

export default function Inscricao() {
    return (
        <PublicPage title="Inscreve-te" description="Inicia o processo de registo de atleta no Benedita Sport Club Natação.">
            <PageHero eyebrow="Inscreve-te" title={<>Começa o teu registo no BSCN.</>} text="Para atletas que já decidiram avançar. Preenche os dados do percurso desportivo e a equipa do clube valida a integração." image="/site-assets/bscn-training-bright.webp" imagePosition="center 52%" />
            <section className="registration-layout shell">
                <aside><p className="eyebrow">Registo de atleta</p><h2>Um processo completo, sem confundir inscrição com pedido de informação.</h2><p>Este formulário destina-se a quem pretende iniciar o processo de entrada no clube. O registo não substitui a validação da equipa técnica nem confirma automaticamente uma vaga.</p><div className="join-steps"><span>01 · Dados do atleta</span><span>02 · Percurso desportivo</span><span>03 · Validação pelo clube</span><span>04 · Integração no ClubOS</span></div><div className="contact-alternative"><strong>Ainda tens dúvidas?</strong><p>Se pretendes apenas esclarecer horários, grupos ou condições, não precisas de preencher já o registo completo.</p><Link href="/junta-te">Pedir contacto ↗</Link></div></aside>
                <RegistrationForm />
            </section>
        </PublicPage>
    );
}
