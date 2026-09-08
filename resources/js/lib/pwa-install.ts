export interface DeferredInstallPrompt extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

declare global {
    interface Window {
        clubOSInstallPrompt?: DeferredInstallPrompt;
    }
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        window.clubOSInstallPrompt = event as DeferredInstallPrompt;
        window.dispatchEvent(new Event('clubos:pwa-install-ready'));
    });

    window.addEventListener('appinstalled', () => {
        window.clubOSInstallPrompt = undefined;
        window.dispatchEvent(new Event('clubos:pwa-installed'));
    });
}
