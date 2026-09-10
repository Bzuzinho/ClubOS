import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ModuleTabsList } from './ModuleTabsList';
import { Tabs, TabsTrigger } from '@/Components/ui/tabs';

describe('ModuleTabsList', () => {
    it('expõe todos os destinos numa navegação identificada e deslocável', () => {
        render(
            <Tabs defaultValue="visao-geral">
                <ModuleTabsList label="Áreas de Membros">
                    <TabsTrigger value="visao-geral">Visão geral</TabsTrigger>
                    <TabsTrigger value="membros">Membros</TabsTrigger>
                    <TabsTrigger value="relatorios">Relatórios</TabsTrigger>
                </ModuleTabsList>
            </Tabs>,
        );

        const navigation = screen.getByRole('navigation', { name: 'Áreas de Membros' });
        expect(navigation).toHaveClass('overflow-x-auto');
        expect(screen.getAllByRole('tab')).toHaveLength(3);
        expect(screen.getByRole('tab', { name: 'Visão geral' })).toHaveAttribute('data-state', 'active');
    });
});
