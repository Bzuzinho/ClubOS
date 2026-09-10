import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ModuleHeader } from './ModuleHeader';

describe('ModuleHeader', () => {
    it('apresenta título, contexto, descrição e ações com semântica consistente', () => {
        render(
            <ModuleHeader
                title="Membros"
                context={<span>Época 2026/27</span>}
                description="Gestão dos membros do clube"
                actions={<button type="button">Novo membro</button>}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Membros' })).toBeInTheDocument();
        expect(screen.getByText('Época 2026/27')).toBeInTheDocument();
        expect(screen.getByText('Gestão dos membros do clube')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Novo membro' })).toBeInTheDocument();
    });

    it('não cria áreas vazias quando os elementos opcionais não existem', () => {
        const { container } = render(<ModuleHeader title="Eventos" />);

        expect(screen.getByRole('heading', { name: 'Eventos' })).toBeInTheDocument();
        expect(container.querySelectorAll('p')).toHaveLength(0);
    });
});
