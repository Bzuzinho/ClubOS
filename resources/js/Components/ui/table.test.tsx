import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import userEvent from '@testing-library/user-event';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from './table';

describe('responsive record table', () => {
  it('retains column headers, full values and usable actions in a single record', async () => {
    const open = vi.fn();
    render(<Table responsive><TableHeader><TableRow><TableHead>Nome</TableHead><TableHead>Ações</TableHead></TableRow></TableHeader><TableBody><TableRow><TableCell label="Nome">Nome completo do requisitante</TableCell><TableCell label="Ações"><button onClick={open}>Abrir</button></TableCell></TableRow></TableBody></Table>);
    expect(screen.getAllByRole('columnheader')).toHaveLength(2);
    expect(screen.getByRole('cell', { name: 'Nome completo do requisitante' })).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(2);
    await userEvent.click(screen.getByRole('button', { name: 'Abrir' }));
    expect(open).toHaveBeenCalledOnce();
  });
});
