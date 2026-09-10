import { useCallback, useEffect, useRef, useState } from 'react';

export function resolveUrlTab<T extends string>(
    search: string,
    allowedTabs: readonly T[],
    fallback: T,
    parameter = 'tab',
): T {
    const requested = new URLSearchParams(search).get(parameter);

    return requested && allowedTabs.includes(requested as T)
        ? requested as T
        : fallback;
}

export function useUrlTab<T extends string>(
    allowedTabs: readonly T[],
    fallback: T,
    parameter = 'tab',
): readonly [T, (value: string) => void] {
    const allowedTabsRef = useRef(allowedTabs);
    allowedTabsRef.current = allowedTabs;

    const readCurrentTab = useCallback(
        () => typeof window === 'undefined'
            ? fallback
            : resolveUrlTab(window.location.search, allowedTabsRef.current, fallback, parameter),
        [fallback, parameter],
    );

    const [activeTab, setActiveTab] = useState<T>(readCurrentTab);

    useEffect(() => {
        const handlePopState = () => setActiveTab(readCurrentTab());

        window.addEventListener('popstate', handlePopState);

        return () => window.removeEventListener('popstate', handlePopState);
    }, [readCurrentTab]);

    const changeTab = useCallback((value: string) => {
        if (!allowedTabsRef.current.includes(value as T)) {
            return;
        }

        const nextTab = value as T;
        setActiveTab(nextTab);

        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set(parameter, nextTab);
        window.history.pushState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
    }, [parameter]);

    return [activeTab, changeTab] as const;
}
