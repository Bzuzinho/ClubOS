import { describe, expect, it } from 'vitest';
import { read, utils } from 'xlsx';
import { decodeMemberImportCsv } from './member-import';

describe('member CSV encoding', () => {
  it.each([false, true])('preserves Portuguese names in UTF-8, BOM=%s', bom => {
    const csv = `${bom ? '\uFEFF' : ''}Nome\nJoão da Conceição\n`;
    const bytes = Uint8Array.from(Buffer.from(csv, 'utf8'));
    const workbook = read(decodeMemberImportCsv(bytes.buffer), { type: 'string' });
    expect(utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]])).toEqual([{ Nome: 'João da Conceição' }]);
  });
  it('preserves legacy Windows-1252 Portuguese names', () => {
    const bytes = Uint8Array.from(Buffer.from('Nome\nJoão da Conceição\n', 'latin1'));
    const workbook = read(decodeMemberImportCsv(bytes.buffer), { type: 'string' });
    expect(utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]])).toEqual([{ Nome: 'João da Conceição' }]);
  });
});
