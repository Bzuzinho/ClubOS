import InputError from '@/Components/InputError';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Escolher nova palavra-passe" />

            <h1 className="text-center text-2xl font-bold text-slate-900">Escolher nova palavra-passe</h1>
            <p className="mt-2 text-center text-base leading-6 text-slate-600">Introduza e confirme a nova palavra-passe.</p>

            <form onSubmit={submit} className="mt-6 space-y-5">
                <div>
                    <label htmlFor="email" className="text-base font-medium text-slate-900">Email de acesso</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-2 block h-12 w-full rounded-xl border border-slate-300 bg-slate-50 px-4 text-base"
                        autoComplete="username"
                        readOnly
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <label htmlFor="password" className="text-base font-medium text-slate-900">Nova palavra-passe</label>
                    <div className="relative mt-2">
                    <input
                        id="password"
                        type={showPassword ? 'text' : 'password'}
                        name="password"
                        value={data.password}
                        className="block h-12 w-full rounded-xl border border-slate-300 px-4 pr-12 text-base focus:border-blue-600 focus:ring-blue-600"
                        autoComplete="new-password"
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <button type="button" onClick={() => setShowPassword((current) => !current)} className="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-slate-600" aria-label={showPassword ? 'Esconder palavra-passe' : 'Mostrar palavra-passe'}>
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
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-2 block h-12 w-full rounded-xl border border-slate-300 px-4 text-base focus:border-blue-600 focus:ring-blue-600"
                        autoComplete="new-password"
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-2"
                    />
                </div>

                <button type="submit" disabled={processing} className="flex h-12 w-full items-center justify-center rounded-xl bg-blue-700 px-5 text-base font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:opacity-50">
                    {processing ? 'A guardar...' : 'Guardar nova palavra-passe'}
                </button>
            </form>
        </GuestLayout>
    );
}
