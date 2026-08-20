import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

export default function FinanceiroEditPage() {
    useEffect(() => {
        router.visit('/financeiro?tab=faturas', {
            replace: true,
        });
    }, []);

    return (
        <>
            <Head title="Financeiro" />
            <div className="flex min-h-screen items-center justify-center bg-background p-6 text-sm text-muted-foreground">
                A abrir a área de Faturas do Financeiro…
            </div>
        </>
    );
}
