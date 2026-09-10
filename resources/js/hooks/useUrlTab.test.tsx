import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { resolveUrlTab, useUrlTab } from './useUrlTab';

const tabs = ['dashboard', 'lista', 'relatorios'] as const;

describe('useUrlTab', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('aceita apenas separadores conhecidos', () => {
        expect(resolveUrlTab('?tab=lista', tabs, 'dashboard')).toBe('lista');
        expect(resolveUrlTab('?tab=desconhecido', tabs, 'dashboard')).toBe('dashboard');
    });

    it('guarda a seleção no URL sem perder outros filtros', () => {
        window.history.replaceState({ inertia: true }, '', '/membros?estado=ativo');

        const { result } = renderHook(() => useUrlTab(tabs, 'dashboard'));

        act(() => result.current[1]('relatorios'));

        expect(result.current[0]).toBe('relatorios');
        expect(window.location.pathname).toBe('/membros');
        expect(window.location.search).toContain('estado=ativo');
        expect(window.location.search).toContain('tab=relatorios');
        expect(window.history.state).toEqual({ inertia: true });
    });

    it('acompanha a navegação do browser', () => {
        window.history.replaceState({}, '', '/membros?tab=dashboard');
        const { result } = renderHook(() => useUrlTab(tabs, 'dashboard'));

        act(() => {
            window.history.pushState({}, '', '/membros?tab=lista');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });

        expect(result.current[0]).toBe('lista');
    });
});
