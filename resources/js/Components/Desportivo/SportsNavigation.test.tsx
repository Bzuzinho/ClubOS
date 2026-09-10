import { render, screen } from '@testing-library/react';
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
        render(<SportsNavigation />);
        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    });
    it('abre a página canónica do atleta sem controlo manual de maximização', () => {
        state.items = [{ label: 'Atletas', href: '/desportivo/atletas', active: true }];
        render(<SportsNavigation />);
        expect(screen.getByRole('link', { name: 'Atletas' })).toHaveAttribute('href', '/desportivo/atletas');
        expect(screen.getByRole('link', { name: 'Atletas' })).toHaveAttribute('aria-current', 'page');
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
