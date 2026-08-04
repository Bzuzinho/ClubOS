import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const ErrorText = ({ message }: { message?: string }) => message ? <small className="field-error">{message}</small> : null;

export default function ContactForm() {
    const [message, setMessage] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        athleteName: '',
        birthDate: '',
        email: '',
        phone: '',
        program: '',
        experience: '',
        guardianName: '',
        guardianEmail: '',
        notes: '',
        company: '',
        consent: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setMessage('');
        post('/junta-te', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setMessage('Pedido de contacto enviado. A equipa do BSCN entrará em contacto contigo.');
            },
        });
    }

    return (
        <form className="join-form" onSubmit={submit} noValidate>
            <div className="form-grid">
                <label><span>Nome do atleta *</span><input value={data.athleteName} onChange={(e) => setData('athleteName', e.target.value)} autoComplete="name" /><ErrorText message={errors.athleteName} /></label>
                <label><span>Data de nascimento *</span><input value={data.birthDate} onChange={(e) => setData('birthDate', e.target.value)} type="date" /><ErrorText message={errors.birthDate} /></label>
                <label><span>Email de contacto *</span><input value={data.email} onChange={(e) => setData('email', e.target.value)} type="email" autoComplete="email" /><ErrorText message={errors.email} /></label>
                <label><span>Telefone *</span><input value={data.phone} onChange={(e) => setData('phone', e.target.value)} type="tel" autoComplete="tel" /><ErrorText message={errors.phone} /></label>
                <label className="full"><span>Assunto do contacto *</span><select value={data.program} onChange={(e) => setData('program', e.target.value)}><option value="" disabled>Selecionar</option><option>Competição jovem</option><option>Competição absoluta</option><option>Masters</option><option>Treino complementar</option><option>Horários e condições</option><option>Quero aconselhamento</option></select><ErrorText message={errors.program} /></label>
                <label className="full"><span>Experiência na natação *</span><select value={data.experience} onChange={(e) => setData('experience', e.target.value)}><option value="" disabled>Selecionar</option><option>Sem experiência competitiva</option><option>Até 2 anos</option><option>Entre 2 e 5 anos</option><option>Mais de 5 anos</option><option>Atleta de outra modalidade</option></select><ErrorText message={errors.experience} /></label>
                <label><span>Encarregado de educação</span><input value={data.guardianName} onChange={(e) => setData('guardianName', e.target.value)} /><ErrorText message={errors.guardianName} /></label>
                <label><span>Email do encarregado</span><input value={data.guardianEmail} onChange={(e) => setData('guardianEmail', e.target.value)} type="email" /><ErrorText message={errors.guardianEmail} /></label>
                <label className="full"><span>Mensagem</span><textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={5} placeholder="Objetivos, disponibilidade ou informação relevante" /><ErrorText message={errors.notes} /></label>
                <label className="honeypot" aria-hidden="true"><span>Empresa</span><input value={data.company} onChange={(e) => setData('company', e.target.value)} tabIndex={-1} autoComplete="off" /></label>
            </div>
            <label className="consent"><input type="checkbox" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} /><span>Li a <Link href="/privacidade">informação de privacidade</Link> e autorizo o contacto do BSCN para responder a este pedido. *</span></label>
            <ErrorText message={errors.consent} />
            <button className="button form-submit" disabled={processing}>{processing ? 'A enviar…' : 'Pedir contacto'}</button>
            {message && <p className="form-message success" role="status">{message}</p>}
        </form>
    );
}
