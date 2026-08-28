import { render, screen } from '@testing-library/react';
import InputError from './InputError';

describe('InputError', () => {
    it('renders the supplied validation message and preserves custom classes', () => {
        render(<InputError message="Campo obrigatório" className="mt-2" data-testid="input-error" />);

        const error = screen.getByTestId('input-error');

        expect(error).toHaveTextContent('Campo obrigatório');
        expect(error).toHaveClass('text-sm', 'text-red-600', 'mt-2');
    });

    it('renders nothing when there is no message', () => {
        const { container } = render(<InputError />);

        expect(container).toBeEmptyDOMElement();
    });
});
