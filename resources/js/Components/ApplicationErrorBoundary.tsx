import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
}

export class ApplicationErrorBoundary extends Component<Props, State> {
    state: State = {
        hasError: false,
    };

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('[ClubOS] Erro de renderização recuperado pelo limite global.', {
            name: error.name,
            message: error.message,
            componentStack: info.componentStack,
        });
    }

    private reload = (): void => {
        window.location.reload();
    };

    private goToDashboard = (): void => {
        window.location.assign('/dashboard');
    };

    render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <main className="flex min-h-dvh items-center justify-center bg-background px-4 py-8">
                <section
                    role="alert"
                    aria-live="assertive"
                    className="w-full max-w-lg rounded-xl border bg-card p-6 text-card-foreground shadow-sm"
                >
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-primary">
                        ClubOS
                    </p>
                    <h1 className="mt-2 text-xl font-semibold">Não foi possível apresentar esta área</h1>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        Os dados não foram alterados. Pode tentar carregar novamente ou regressar ao início.
                    </p>
                    <div className="mt-5 flex flex-col gap-2 sm:flex-row">
                        <button
                            type="button"
                            onClick={this.reload}
                            className="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                        >
                            Tentar novamente
                        </button>
                        <button
                            type="button"
                            onClick={this.goToDashboard}
                            className="inline-flex h-9 items-center justify-center rounded-md border bg-background px-4 text-sm font-medium"
                        >
                            Ir para o início
                        </button>
                    </div>
                </section>
            </main>
        );
    }
}
