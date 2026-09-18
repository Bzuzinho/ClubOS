import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { MemberImportDialog } from './MemberImportDialog';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('xlsx/xlsx.mjs', () => ({
  read: () => ({ SheetNames: ['Members'], Sheets: { Members: {} } }),
  utils: { sheet_to_json: (_sheet: unknown, options: { header?: number }) => options.header ? [['Nome']] : [{ Nome: 'Pessoa de teste' }] },
}));
const preview = { summary: { total_rows: 1, valid_rows: 1, warning_rows: 0, error_rows: 0 }, rows: [], errors: [], warnings: [] };
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}
async function openMapping() {
  render(<MemberImportDialog />);
  fireEvent.click(screen.getByRole('button', { name: 'Importar utilizadores' }));
  fireEvent.change(screen.getByLabelText('Ficheiro Excel ou CSV'), { target: { files: [{ name: 'members.csv', arrayBuffer: async () => new ArrayBuffer(0) }] } });
  await screen.findByRole('button', { name: 'Validar importação' });
}
function closeDialog() { fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape', code: 'Escape' }); }

beforeEach(() => { vi.stubGlobal('route', (name: string) => name); });
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.resetAllMocks(); });

describe('MemberImportDialog pending operations', () => {
  it('keeps the file session open until reading finishes', async () => {
    const read = deferred<ArrayBuffer>();
    render(<MemberImportDialog />);
    fireEvent.click(screen.getByRole('button', { name: 'Importar utilizadores' }));
    fireEvent.change(screen.getByLabelText('Ficheiro Excel ou CSV'), { target: { files: [{ name: 'members.csv', arrayBuffer: () => read.promise }] } });
    expect(screen.getByRole('button', { name: 'Escolher ficheiro' })).toBeDisabled();
    closeDialog();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await act(async () => read.resolve(new ArrayBuffer(0)));
    expect(await screen.findByRole('button', { name: 'Validar importação' })).toBeEnabled();
  });

  it('locks mapping, back and close while validation is pending and unlocks after failure', async () => {
    await openMapping();
    const pending = deferred<unknown>();
    vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Validar importação' }));
    expect(screen.getByRole('button', { name: 'Voltar' })).toBeDisabled();
    screen.getAllByRole('combobox').forEach(select => expect(select).toBeDisabled());
    closeDialog();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await act(async () => pending.reject(new Error('Validation failed')));
    expect(screen.getByRole('button', { name: 'Voltar' })).toBeEnabled();
    closeDialog();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('preserves an in-flight import and sends only one write until its result arrives', async () => {
    await openMapping();
    vi.mocked(axios.post).mockResolvedValueOnce({ data: preview });
    fireEvent.click(screen.getByRole('button', { name: 'Validar importação' }));
    const importButton = await screen.findByRole('button', { name: 'Importar linhas válidas' });
    const pending = deferred<unknown>();
    vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
    fireEvent.click(importButton);
    fireEvent.click(importButton);
    expect(screen.getByRole('button', { name: 'Ajustar mapeamento' })).toBeDisabled();
    closeDialog();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(axios.post).toHaveBeenCalledTimes(2);
    await act(async () => pending.resolve({ data: { created_count: 1, skipped_count: 0, error_count: 0, created_ids: ['1'], errors: [], warnings: [] } }));
    fireEvent.click(screen.getAllByRole('button', { name: /^Fechar$/ })[0]);
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });
});
