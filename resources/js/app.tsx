import './bootstrap';
import '../css/app.css';

import { Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const appName = import.meta.env.VITE_APP_NAME || 'ClubOS';
const Devtools = import.meta.env.DEV
    ? lazy(() =>
          import('@tanstack/react-query-devtools').then((module) => ({
              default: module.ReactQueryDevtools,
          }))
      )
    : null;

// Configure React Query
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            retry: 1,
            staleTime: 5 * 60 * 1000, // 5 minutes
        },
    },
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <QueryClientProvider client={queryClient}>
                <App {...props} />
                {Devtools ? (
                    <Suspense fallback={null}>
                        <Devtools initialIsOpen={false} />
                    </Suspense>
                ) : null}
            </QueryClientProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
