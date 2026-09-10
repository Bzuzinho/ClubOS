import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApplicationErrorBoundary } from './ApplicationErrorBoundary';

function BrokenView(): never {
    throw new Error('render failed');
}

describe('ApplicationErrorBoundary', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('substitui um erro de renderização por uma recuperação compreensível', () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        render(
            <ApplicationErrorBoundary>
                <BrokenView />
            </ApplicationErrorBoundary>,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Não foi possível apresentar esta área');
        expect(screen.getByRole('button', { name: 'Tentar novamente' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ir para o início' })).toBeInTheDocument();
    });
});
