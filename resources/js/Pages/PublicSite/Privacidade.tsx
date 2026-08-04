import { PublicPage } from '@/Layouts/PublicSiteLayout';

export default function Privacidade() {
    return (
        <PublicPage title="Privacidade" description="Informação sobre o tratamento de dados pessoais nos formulários públicos do BSCN.">
            <section className="legal shell"><p className="eyebrow">Privacidade</p><h1>Informação sobre o tratamento de dados</h1><p>Os dados enviados através dos formulários são utilizados pelo Benedita Sport Club Natação exclusivamente para responder a pedidos de contacto, avaliar o enquadramento desportivo e gerir o processo inicial de registo no clube.</p><h2>Dados recolhidos</h2><p>No pedido de contacto recolhemos nome, data de nascimento, contactos, experiência e assunto. No registo de atleta recolhemos ainda localidade, percurso desportivo, disponibilidade e, quando aplicável, dados do encarregado de educação.</p><h2>Conservação e direitos</h2><p>Os pedidos e registos serão conservados apenas durante o período necessário ao contacto e processo de integração. Podes solicitar acesso, correção ou eliminação através de <a href="mailto:geral@bscn.pt">geral@bscn.pt</a>.</p><p className="legal-note">Responsável pelo tratamento: Benedita Sport Club Natação. Para questões sobre privacidade, utiliza o contacto geral do clube.</p></section>
        </PublicPage>
    );
}
