import { PageHero, PublicPage } from '@/Layouts/PublicSiteLayout';
import { PublicEvent, eventDateParts } from './types';

const nationalEvents = [
    { day: '24', month: 'OUT', year: '2026', title: 'Fase de Qualificação — Clubes 3.ª Divisão', place: 'Sines', scope: 'Calendário nacional FPN' },
    { day: '27', month: 'NOV', year: '2026', title: 'Campeonato Nacional de Clubes 3.ª Divisão', place: 'Local indicado pela FPN', scope: 'Calendário nacional FPN' },
    { day: '04–06', month: 'DEZ', year: '2026', title: 'Torneio Zonal de Juvenis — Zona Sul', place: 'Leiria', scope: 'Calendário nacional FPN' },
];

export default function Calendario({ events }: { events: PublicEvent[] }) {
    return (
        <PublicPage title="Calendário" description="Calendário público do BSCN e principais datas oficiais da época 2026/27.">
            <PageHero eyebrow="Calendário" title={<>A época, organizada.</>} text="Eventos públicos do BSCN e datas nacionais já publicadas para 2026/27, sem confundir calendário com convocatória." image="/site-assets/bscn-hero-bright.webp" imagePosition="center 58%" />

            <section className="calendar-layout shell">
                <div className="calendar-intro"><p className="eyebrow">Agenda do clube</p><h2>Próximos eventos públicos</h2><p>O calendário é alimentado diretamente pelo ClubOS. Apenas aparecem eventos confirmados como públicos; os detalhes individuais e convocatórias continuam reservados aos membros.</p><div className="calendar-status"><span className="status-dot" /><div><strong>Atualização automática</strong><p>As alterações feitas pela equipa do clube ficam refletidas no website após publicação.</p></div></div></div>
                <div className="calendar-events">
                    {events.length ? events.map((event) => { const date = eventDateParts(event.startDate); return <article className="calendar-event" key={event.id}><time><strong>{date.day}</strong><span>{date.month}<small>{date.year}</small></span></time><div><p className="story-meta">{event.type.toUpperCase()} · BSCN</p><h3>{event.title}</h3><p>{[event.place, event.startTime].filter(Boolean).join(' · ') || event.description || 'Informação disponível no ClubOS'}</p></div></article>; }) : <div className="calendar-empty"><p>Não existem ainda eventos públicos confirmados no ClubOS para esta época.</p></div>}
                </div>
            </section>

            <section className="calendar-layout shell national-calendar"><div className="calendar-intro"><p className="eyebrow">Época 2026/27</p><h2>Primeiras datas oficiais</h2><p>Estas são datas publicadas pela Federação Portuguesa de Natação. A participação do BSCN depende sempre de confirmação e convocatória técnica.</p></div><div className="calendar-events">{nationalEvents.map((event) => <article className="calendar-event" key={`${event.day}-${event.title}`}><time><strong>{event.day}</strong><span>{event.month}<small>{event.year}</small></span></time><div><p className="story-meta">{event.scope}</p><h3>{event.title}</h3><p>{event.place}</p></div></article>)}</div></section>

            <section className="calendar-source shell"><div><p className="eyebrow">Fonte oficial</p><h2>Calendário nacional FPN 2026/27</h2><p>Consulta o documento completo da Federação Portuguesa de Natação. As datas podem ser alteradas pela entidade organizadora.</p></div><a className="button" href="https://fpnatacao.pt/uploads/Calendario_FPN_2026-2027.pdf" target="_blank" rel="noreferrer">Abrir calendário FPN ↗</a></section>
            <section className="soft-cta shell"><div><p className="eyebrow">Informação do clube</p><h2>Convocatórias e detalhes ficam disponíveis no ClubOS.</h2></div><a className="button" href="/login">Entrar no ClubOS ↗</a></section>
        </PublicPage>
    );
}
