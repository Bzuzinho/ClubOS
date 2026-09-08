import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { ClubMark } from '@/Components/ClubMark';
import { useClubSettings } from '@/hooks/useClubSettings';
import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Smartphone } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const [showPassword, setShowPassword] = useState(false);
    const { clubDisplayName, clubLogoUrl, clubName, clubShortName } = useClubSettings();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Entrar" />

            <div className="flex min-h-screen items-center justify-center bg-gray-100 p-3">
                <div className="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                    <div className="mb-5 text-center">
                        <ClubMark
                            logoUrl={clubLogoUrl}
                            clubName={clubName}
                            clubShortName={clubShortName}
                            className="mx-auto h-16 w-16 border border-gray-200 bg-white text-lg"
                            imageClassName="mx-auto h-16 w-auto object-contain"
                        />
                        <h1 className="mt-3 text-xl font-bold text-gray-900">{clubDisplayName}</h1>
                        <p className="mt-1 text-base text-gray-600">Entre na sua área pessoal {clubName}</p>
                    </div>

                    {status && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-3.5">
                        <div>
                            <InputLabel htmlFor="email" value="Email" className="text-base font-medium text-gray-900" />

                            <TextInput
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                className="mt-1.5 block w-full rounded-lg border-gray-300 px-3.5 py-2 text-base"
                                autoComplete="username"
                                isFocused={true}
                                placeholder="seu.email@exemplo.com"
                                onChange={(e) => setData('email', e.target.value)}
                            />

                            <InputError message={errors.email} className="mt-2" />
                        </div>

                        <div>
                            <InputLabel htmlFor="password" value="Palavra-passe" className="text-base font-medium text-gray-900" />

                            <div className="relative mt-1.5">
                                <TextInput
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    name="password"
                                    value={data.password}
                                    className="block h-12 w-full rounded-lg border-gray-300 px-3.5 pr-12 text-base"
                                    autoComplete="current-password"
                                    placeholder="••••••••"
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword((current) => !current)}
                                    className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-lg text-gray-600 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                                    aria-label={showPassword ? 'Esconder palavra-passe' : 'Mostrar palavra-passe'}
                                >
                                    {showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                                </button>
                            </div>

                            <InputError message={errors.password} className="mt-2" />
                        </div>

                        <label className="flex cursor-pointer items-start gap-3 rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(event) => setData('remember', event.target.checked)}
                                className="mt-0.5 h-5 w-5 rounded border-slate-300 text-blue-700 focus:ring-blue-600"
                            />
                            <span>
                                <span className="block font-medium text-slate-900">Manter sessão iniciada neste dispositivo</span>
                                <span className="mt-0.5 block">Não selecione num computador partilhado.</span>
                            </span>
                        </label>

                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex h-12 w-full items-center justify-center rounded-lg bg-blue-700 px-4 text-base font-semibold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50"
                        >
                            {processing ? 'A entrar...' : 'Entrar'}
                        </button>

                        {canResetPassword && (
                            <div className="pt-0.5 text-center">
                                <Link
                                    href={route('password.request')}
                                    className="text-base text-gray-700 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                >
                                    Esqueceu a palavra-passe?
                                </Link>
                            </div>
                        )}

                        <div className="border-t border-slate-200 pt-4 text-center">
                            <Link
                                href={route('onboarding.install')}
                                className="inline-flex min-h-11 items-center justify-center gap-2 text-sm font-medium text-blue-700 hover:text-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            >
                                <Smartphone className="h-5 w-5" aria-hidden="true" />
                                Como colocar o ícone no telemóvel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </>
    );
}
