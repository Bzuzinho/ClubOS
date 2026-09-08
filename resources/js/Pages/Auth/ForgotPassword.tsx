import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Recuperar palavra-passe" />

            <h1 className="text-center text-2xl font-bold text-slate-900">Recuperar palavra-passe</h1>
            <p className="mt-2 text-center text-base leading-6 text-slate-600">
                Indique o email que utiliza para entrar. Enviaremos um link para escolher uma nova palavra-passe.
            </p>

            {status && (
                <div className="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium leading-5 text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="mt-6">
                <label htmlFor="email" className="text-base font-medium text-slate-900">Email de acesso</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-2 block h-12 w-full rounded-xl border border-slate-300 px-4 text-base focus:border-blue-600 focus:ring-blue-600"
                    autoFocus
                    autoComplete="email"
                    onChange={(e) => setData('email', e.target.value)}
                />

                <InputError message={errors.email} className="mt-2" />

                <button type="submit" disabled={processing} className="mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-blue-700 px-5 text-base font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:opacity-50">
                    {processing ? 'A enviar...' : 'Enviar link de recuperação'}
                </button>

                <Link href={route('login')} className="mt-4 flex min-h-11 items-center justify-center text-base font-medium text-slate-600 underline underline-offset-4">
                    Voltar ao início de sessão
                </Link>
            </form>
        </GuestLayout>
    );
}
