import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EventosList } from './EventosList';

const inertiaRouter = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: inertiaRouter }));
vi.mock('sonner', () => ({
    toast: {
        error: vi.fn(),
        success: vi.fn(),
    },
}));

describe('EventosList creation form', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('keeps validation feedback visible in the dialog and does not submit an invalid event', () => {
        render(<EventosList events={[]} canEdit />);

        fireEvent.click(screen.getByRole('button', { name: 'Novo Evento' }));
        fireEvent.change(screen.getByLabelText('Título *'), {
            target: { value: 'Evento sem data' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(screen.getByRole('alert')).toHaveTextContent('Não foi possível guardar o evento.');
        expect(screen.getAllByText('Preencha a data de início do evento.').length).toBeGreaterThan(0);
        expect(screen.getByLabelText('Data Início *')).toHaveAttribute('aria-invalid', 'true');
        expect(inertiaRouter.post).not.toHaveBeenCalled();
    });
});
