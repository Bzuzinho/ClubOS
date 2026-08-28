import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Checkbox from './Checkbox';

describe('Checkbox', () => {
    it('forwards accessible input props and toggles through user interaction', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(<Checkbox aria-label="Aceitar termos" onChange={onChange} />);

        const checkbox = screen.getByRole('checkbox', { name: 'Aceitar termos' });
        expect(checkbox).not.toBeChecked();

        await user.click(checkbox);

        expect(checkbox).toBeChecked();
        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it('preserves custom classes while keeping the shared checkbox styling', () => {
        render(<Checkbox aria-label="Selecionar" className="custom-checkbox" />);

        expect(screen.getByRole('checkbox', { name: 'Selecionar' })).toHaveClass(
            'rounded',
            'custom-checkbox',
        );
    });
});
