import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { Link } from '@inertiajs/react';
import ContactForm from './ContactForm';

export default function JuntaTe() {
    return (
        <PublicPage title="Pedir contacto" description="Pede informações sobre condições, horários e enquadramento desportivo no BSCN.">
            <PageHero eyebrow="Pedir contacto" title={<>Vamos perceber como te podemos ajudar.</>} text="Usa este formulário para esclarecer condições, horários ou o enquadramento desportivo antes de iniciares o registo." image="/site-assets/bscn-club-bright.webp" imagePosition="center 48%" />
            <section className="join-layout shell">
                <aside><p className="eyebrow">Pedido de contacto</p><h2>Conta-nos o que precisas de saber. Nós ajudamos a definir o próximo passo.</h2><p>Este pedido não é uma inscrição. A equipa técnica entra em contacto para esclarecer condições, disponibilidade e eventual necessidade de avaliação.</p><div className="join-steps"><span>01 · Envio do pedido</span><span>02 · Contacto do clube</span><span>03 · Esclarecimento ou avaliação</span></div><div className="contact-alternative"><strong>Já decidiste avançar?</strong><p>Segue diretamente para o registo completo de atleta.</p><Link href="/inscricao">Inscreve-te ↗</Link></div></aside>
                <ContactForm />
            </section>
        </PublicPage>
    );
}
