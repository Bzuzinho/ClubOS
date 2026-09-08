import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertCircle, Check, Eye, EyeOff, KeyRound } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Props {
    token: string;
    email: string;
    invitationValid: boolean;
    expiresInHours: number;
}

export default function ActivateAccess({ token, email, invitationValid, expiresInHours }: Props) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const checks = useMemo(() => [
        { label: 'Pelo menos 8 caracteres', valid: data.password.length >= 8 },
        { label: 'Pelo menos uma letra', valid: /[A-Za-zÀ-ÿ]/.test(data.password) },
        { label: 'Pelo menos um número', valid: /\d/.test(data.password) },
        { label: 'As duas palavras-passe são iguais', valid: data.password !== '' && data.password === data.password_confirmation },
    ], [data.password, data.password_confirmation]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('access.activate.store'));
    };

    if (!invitationValid) {
        return (
            <GuestLayout>
                <Head title="Convite indisponível" />
                <div className="text-center">
                    <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700">
                        <AlertCircle className="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h1 className="mt-3 text-2xl font-bold text-slate-900">Este convite já não está disponível</h1>
                    <p className="mt-3 text-base leading-6 text-slate-600">
                        O link pode ter expirado ou já ter sido utilizado. Peça ao clube para enviar um novo convite.
                    </p>
                    <Link
                        href={route('login')}
                        className="mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-blue-700 px-5 text-base font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                    >
                        Ir para a entrada
                    </Link>
                </div>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title="Criar o seu acesso" />

            <div className="text-center">
                <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-blue-700">
                    <KeyRound className="h-6 w-6" aria-hidden="true" />
                </span>
                <h1 className="mt-3 text-2xl font-bold text-slate-900">Criar o seu acesso</h1>
                <p className="mt-2 text-base leading-6 text-slate-600">
                    Escolha uma palavra-passe para entrar na sua área pessoal.
                </p>
            </div>

            <form onSubmit={submit} className="mt-6 space-y-5">
                <div>
                    <label htmlFor="email" className="text-base font-medium text-slate-900">Email de acesso</label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        readOnly
                        className="mt-2 block h-12 w-full rounded-xl border border-slate-300 bg-slate-50 px-4 text-base text-slate-700"
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <label htmlFor="password" className="text-base font-medium text-slate-900">Nova palavra-passe</label>
                    <div className="relative mt-2">
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            value={data.password}
                            autoComplete="new-password"
                            autoFocus
                            onChange={(event) => setData('password', event.target.value)}
                            className="block h-12 w-full rounded-xl border border-slate-300 px-4 pr-12 text-base focus:border-blue-600 focus:ring-blue-600"
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((current) => !current)}
                            className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-600 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-600"
                            aria-label={showPassword ? 'Esconder palavra-passe' : 'Mostrar palavra-passe'}
                        >
                            {showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                        </button>
                    </div>
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <label htmlFor="password_confirmation" className="text-base font-medium text-slate-900">Confirmar palavra-passe</label>
                    <input
                        id="password_confirmation"
                        type={showPassword ? 'text' : 'password'}
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        className="mt-2 block h-12 w-full rounded-xl border border-slate-300 px-4 text-base focus:border-blue-600 focus:ring-blue-600"
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <ul className="space-y-2 rounded-xl bg-slate-50 p-4 text-sm text-slate-700" aria-label="Requisitos da palavra-passe">
                    {checks.map((check) => (
                        <li key={check.label} className="flex items-center gap-2">
                            <Check className={`h-4 w-4 ${check.valid ? 'text-emerald-600' : 'text-slate-300'}`} aria-hidden="true" />
                            <span>{check.label}</span>
                            <span className="sr-only">— {check.valid ? 'cumprido' : 'por cumprir'}</span>
                        </li>
                    ))}
                </ul>

                <button
                    type="submit"
                    disabled={processing || checks.some((check) => !check.valid)}
                    className="flex h-12 w-full items-center justify-center rounded-xl bg-blue-700 px-5 text-base font-semibold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processing ? 'A criar o acesso...' : 'Criar o meu acesso'}
                </button>
            </form>

            <p className="mt-5 text-center text-sm leading-5 text-slate-500">
                Este convite é válido durante {expiresInHours} horas. Depois disso, poderá pedir um novo ao clube.
            </p>
        </GuestLayout>
    );
}
