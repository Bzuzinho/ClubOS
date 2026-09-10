import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { SportsNavigation, type SportsNavigationItem } from './SportsNavigation';

const state = vi.hoisted(() => ({ items: [] as SportsNavigationItem[] }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { sportsNavigation: state.items } }),
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => <a {...props}>{children}</a>,
}));

describe('SportsNavigation', () => {
    beforeEach(() => { state.items = []; });
    it('não apresenta navegação quando o servidor não autoriza destinos', () => {
        render(<SportsNavigation maximized={false} onToggle={() => {}} />);
        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    });
    it('abre a página canónica do atleta e permite repor o menu', () => {
        state.items = [{ label: 'Atletas', href: '/desportivo/atletas', active: true }];
        const toggle = vi.fn();
        render(<SportsNavigation maximized onToggle={toggle} />);
        expect(screen.getByRole('link', { name: 'Atletas' })).toHaveAttribute('href', '/desportivo/atletas');
        expect(screen.getByRole('link', { name: 'Atletas' })).toHaveAttribute('aria-current', 'page');
        fireEvent.click(screen.getByRole('button', { name: 'Repor menu' }));
        expect(toggle).toHaveBeenCalledOnce();
    });
});
