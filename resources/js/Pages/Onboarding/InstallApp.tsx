import { ClubMark } from '@/Components/ClubMark';
import { useClubSettings } from '@/hooks/useClubSettings';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Download, ExternalLink, MonitorSmartphone, Share, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    continueUrl: string;
    firstAccess: boolean;
}

function isIOSDevice(): boolean {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches
        || Boolean((navigator as Navigator & { standalone?: boolean }).standalone);
}

export default function InstallApp({ continueUrl, firstAccess }: Props) {
    const { clubDisplayName, clubLogoUrl, clubName, clubShortName } = useClubSettings();
    const [ios, setIos] = useState(false);
    const [installed, setInstalled] = useState(false);
    const [canInstall, setCanInstall] = useState(false);
    const [showInstructions, setShowInstructions] = useState(false);

    useEffect(() => {
        const syncInstallState = () => {
            setInstalled(isStandalone());
            setCanInstall(Boolean(window.clubOSInstallPrompt));
        };

        setIos(isIOSDevice());
        syncInstallState();
        window.addEventListener('clubos:pwa-install-ready', syncInstallState);
        window.addEventListener('clubos:pwa-installed', syncInstallState);

        return () => {
            window.removeEventListener('clubos:pwa-install-ready', syncInstallState);
            window.removeEventListener('clubos:pwa-installed', syncInstallState);
        };
    }, []);

    const install = async () => {
        if (window.clubOSInstallPrompt) {
            await window.clubOSInstallPrompt.prompt();
            const choice = await window.clubOSInstallPrompt.userChoice;
            if (choice.outcome === 'accepted') {
                setInstalled(true);
            }
            window.clubOSInstallPrompt = undefined;
            setCanInstall(false);
            return;
        }

        setShowInstructions(true);
    };

    const continueToPlatform = () => router.visit(continueUrl);

    return (
        <>
            <Head title={firstAccess ? 'Acesso criado' : 'Instalar BSCN'} />

            <main className="min-h-screen bg-slate-100 px-3 py-6 sm:py-10">
                <div className="mx-auto max-w-lg overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="bg-gradient-to-br from-blue-700 to-blue-900 px-5 py-7 text-center text-white sm:px-8">
                        <ClubMark
                            logoUrl={clubLogoUrl}
                            clubName={clubName}
                            clubShortName={clubShortName}
                            className="mx-auto h-20 w-20 border-2 border-white/20 bg-white text-xl text-blue-800"
                            imageClassName="object-contain p-1"
                        />
                        {firstAccess && <CheckCircle2 className="mx-auto mt-4 h-8 w-8 text-emerald-300" aria-hidden="true" />}
                        <h1 className="mt-3 text-2xl font-bold">{firstAccess ? 'Acesso criado com sucesso' : `Aceder ao ${clubDisplayName}`}</h1>
                        <p className="mt-2 text-base leading-6 text-blue-100">
                            {firstAccess ? 'Já pode entrar na sua área pessoal.' : 'Escolha a forma mais simples de voltar à plataforma.'}
                        </p>
                    </div>

                    <div className="space-y-5 p-5 sm:p-8">
                        <button
                            type="button"
                            onClick={continueToPlatform}
                            className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-700 px-5 py-3 text-base font-semibold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                        >
                            Continuar para a minha área
                            <ExternalLink className="h-5 w-5" aria-hidden="true" />
                        </button>

                        <section className="rounded-2xl border border-slate-200 p-4 sm:p-5">
                            <div className="flex items-start gap-3">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <Smartphone className="h-6 w-6" aria-hidden="true" />
                                </span>
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-900">Colocar um ícone no telemóvel</h2>
                                    <p className="mt-1 text-sm leading-5 text-slate-600">É opcional e permite abrir o BSCN como se fosse uma aplicação.</p>
                                </div>
                            </div>

                            {installed ? (
                                <p className="mt-4 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-800">O BSCN já está aberto como aplicação neste dispositivo.</p>
                            ) : (
                                <button
                                    type="button"
                                    onClick={install}
                                    className="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-blue-700 px-4 py-3 text-base font-semibold text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                                >
                                    <Download className="h-5 w-5" aria-hidden="true" />
                                    {canInstall ? 'Instalar agora' : 'Ver como colocar o ícone'}
                                </button>
                            )}

                            {showInstructions && !installed && (
                                <ol className="mt-4 space-y-3 rounded-xl bg-slate-50 p-4 text-base text-slate-700">
                                    {ios ? (
                                        <>
                                            <li className="flex gap-3"><Share className="mt-0.5 h-5 w-5 shrink-0 text-blue-700" aria-hidden="true" /><span>Abra esta página no <strong>Safari</strong> e toque em <strong>Partilhar</strong>.</span></li>
                                            <li><strong>2.</strong> Escolha <strong>Adicionar ao Ecrã Principal</strong>.</li>
                                            <li><strong>3.</strong> Toque em <strong>Adicionar</strong>.</li>
                                        </>
                                    ) : (
                                        <>
                                            <li><strong>1.</strong> Abra o menu do browser, normalmente identificado por três pontos.</li>
                                            <li><strong>2.</strong> Escolha <strong>Instalar aplicação</strong> ou <strong>Adicionar ao ecrã principal</strong>.</li>
                                            <li><strong>3.</strong> Confirme em <strong>Instalar</strong> ou <strong>Adicionar</strong>.</li>
                                        </>
                                    )}
                                </ol>
                            )}
                        </section>

                        <section className="flex gap-3 rounded-2xl bg-slate-50 p-4">
                            <MonitorSmartphone className="mt-0.5 h-6 w-6 shrink-0 text-slate-600" aria-hidden="true" />
                            <div>
                                <h2 className="font-semibold text-slate-900">Também funciona sempre no browser</h2>
                                <p className="mt-1 text-sm leading-5 text-slate-600">
                                    Não precisa de instalar nada. Pode entrar no telemóvel ou computador através de{' '}
                                    <a href="/login" className="font-semibold text-blue-700 underline">bscn.pt/login</a>.
                                </p>
                            </div>
                        </section>

                        {firstAccess && (
                            <button type="button" onClick={continueToPlatform} className="w-full min-h-11 text-base font-medium text-slate-600 underline underline-offset-4">
                                Agora não — continuar no browser
                            </button>
                        )}
                    </div>
                </div>
            </main>
        </>
    );
}
