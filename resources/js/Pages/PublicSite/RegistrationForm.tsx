import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const ErrorText = ({ message }: { message?: string }) => message ? <small className="field-error">{message}</small> : null;

export default function RegistrationForm() {
    const [message, setMessage] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        athleteName: '', birthDate: '', locality: '', email: '', phone: '', program: '', experience: '',
        previousClub: '', federationNumber: '', availability: '', guardianName: '', guardianRelationship: '',
        guardianEmail: '', guardianPhone: '', notes: '', company: '', consent: false, accuracy: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setMessage('');
        post('/inscricao', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setMessage('Registo submetido. A equipa do BSCN irá validar os dados e indicar os próximos passos.');
            },
        });
    }

    return (
        <form className="join-form registration-form" onSubmit={submit} noValidate>
            <fieldset>
                <legend><span>01</span> Dados do atleta</legend>
                <div className="form-grid">
                    <label className="full"><span>Nome completo *</span><input value={data.athleteName} onChange={(e) => setData('athleteName', e.target.value)} autoComplete="name" /><ErrorText message={errors.athleteName} /></label>
                    <label><span>Data de nascimento *</span><input value={data.birthDate} onChange={(e) => setData('birthDate', e.target.value)} type="date" /><ErrorText message={errors.birthDate} /></label>
                    <label><span>Localidade *</span><input value={data.locality} onChange={(e) => setData('locality', e.target.value)} autoComplete="address-level2" /><ErrorText message={errors.locality} /></label>
                    <label><span>Email *</span><input value={data.email} onChange={(e) => setData('email', e.target.value)} type="email" autoComplete="email" /><ErrorText message={errors.email} /></label>
                    <label><span>Telefone *</span><input value={data.phone} onChange={(e) => setData('phone', e.target.value)} type="tel" autoComplete="tel" /><ErrorText message={errors.phone} /></label>
                </div>
            </fieldset>
            <fieldset>
                <legend><span>02</span> Percurso desportivo</legend>
                <div className="form-grid">
                    <label><span>Grupo pretendido *</span><select value={data.program} onChange={(e) => setData('program', e.target.value)}><option value="" disabled>Selecionar</option><option>Formação competitiva</option><option>Competição</option><option>Masters</option><option>Treino complementar</option><option>A definir pela equipa técnica</option></select><ErrorText message={errors.program} /></label>
                    <label><span>Experiência *</span><select value={data.experience} onChange={(e) => setData('experience', e.target.value)}><option value="" disabled>Selecionar</option><option>Sem experiência competitiva</option><option>Até 2 anos</option><option>Entre 2 e 5 anos</option><option>Mais de 5 anos</option><option>Atleta de outra modalidade</option></select><ErrorText message={errors.experience} /></label>
                    <label><span>Clube anterior</span><input value={data.previousClub} onChange={(e) => setData('previousClub', e.target.value)} /><ErrorText message={errors.previousClub} /></label>
                    <label><span>N.º de federado</span><input value={data.federationNumber} onChange={(e) => setData('federationNumber', e.target.value)} inputMode="numeric" /><ErrorText message={errors.federationNumber} /></label>
                    <label className="full"><span>Disponibilidade habitual</span><textarea value={data.availability} onChange={(e) => setData('availability', e.target.value)} rows={3} placeholder="Dias e períodos em que podes treinar" /><ErrorText message={errors.availability} /></label>
                </div>
            </fieldset>
            <fieldset>
                <legend><span>03</span> Encarregado de educação <small>obrigatório para menores</small></legend>
                <div className="form-grid">
                    <label><span>Nome</span><input value={data.guardianName} onChange={(e) => setData('guardianName', e.target.value)} autoComplete="name" /><ErrorText message={errors.guardianName} /></label>
                    <label><span>Relação com o atleta</span><input value={data.guardianRelationship} onChange={(e) => setData('guardianRelationship', e.target.value)} placeholder="Ex.: mãe, pai, tutor" /><ErrorText message={errors.guardianRelationship} /></label>
                    <label><span>Email</span><input value={data.guardianEmail} onChange={(e) => setData('guardianEmail', e.target.value)} type="email" autoComplete="email" /><ErrorText message={errors.guardianEmail} /></label>
                    <label><span>Telefone</span><input value={data.guardianPhone} onChange={(e) => setData('guardianPhone', e.target.value)} type="tel" autoComplete="tel" /><ErrorText message={errors.guardianPhone} /></label>
                </div>
            </fieldset>
            <fieldset>
                <legend><span>04</span> Informação adicional</legend>
                <div className="form-grid">
                    <label className="full"><span>Objetivos ou informação relevante</span><textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={5} placeholder="Objetivos desportivos, limitações ou contexto que a equipa técnica deva conhecer" /><ErrorText message={errors.notes} /></label>
                    <label className="honeypot" aria-hidden="true"><span>Empresa</span><input value={data.company} onChange={(e) => setData('company', e.target.value)} tabIndex={-1} autoComplete="off" /></label>
                </div>
            </fieldset>
            <label className="consent"><input type="checkbox" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} /><span>Li a <Link href="/privacidade">informação de privacidade</Link> e autorizo o tratamento destes dados para análise e gestão do registo no BSCN. *</span></label>
            <ErrorText message={errors.consent} />
            <label className="consent"><input type="checkbox" checked={data.accuracy} onChange={(e) => setData('accuracy', e.target.checked)} /><span>Confirmo que os dados indicados são verdadeiros e que este registo fica sujeito à validação do clube. *</span></label>
            <ErrorText message={errors.accuracy} />
            <button className="button form-submit" disabled={processing}>{processing ? 'A submeter…' : 'Submeter registo'}</button>
            {message && <p className="form-message success" role="status">{message}</p>}
        </form>
    );
}
