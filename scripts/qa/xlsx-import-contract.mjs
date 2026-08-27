import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

const expectedVersion = '0.20.3';
const packageJson = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
const dependency = packageJson.dependencies?.xlsx;

if (dependency !== 'file:vendor/xlsx-0.20.3.tgz') {
  throw new Error(`xlsx must remain pinned to the vendored tarball; found ${dependency ?? 'missing'}.`);
}

const tarballUrl = new URL('../../vendor/xlsx-0.20.3.tgz', import.meta.url);
const checksumUrl = new URL('../../vendor/xlsx-0.20.3.tgz.sha256', import.meta.url);
const tarball = readFileSync(tarballUrl);
const checksumRecord = readFileSync(checksumUrl, 'utf8').trim();
const expectedChecksum = checksumRecord.split(/\s+/)[0];
const actualChecksum = createHash('sha256').update(tarball).digest('hex');

if (!expectedChecksum || actualChecksum !== expectedChecksum) {
  throw new Error(`Vendored SheetJS checksum mismatch: expected ${expectedChecksum || 'missing'}, got ${actualChecksum}.`);
}

const XLSX = await import('xlsx');
const XLSXesm = await import('xlsx/xlsx.mjs');

for (const [name, module] of [['xlsx', XLSX], ['xlsx/xlsx.mjs', XLSXesm]]) {
  if (module.version !== expectedVersion) {
    throw new Error(`${name} must resolve SheetJS ${expectedVersion}; got ${module.version ?? 'unknown'}.`);
  }
  if (typeof module.read !== 'function' || typeof module.utils?.sheet_to_json !== 'function') {
    throw new Error(`${name} does not expose the spreadsheet APIs used by ClubOS.`);
  }
}

const workbook = XLSX.utils.book_new();
const sheet = XLSX.utils.aoa_to_sheet([
  ['Data', 'Descricao', 'Valor'],
  ['2026-08-27', 'Quota', '12,50'],
]);
XLSX.utils.book_append_sheet(workbook, sheet, 'Dados');

for (const bookType of ['xlsx', 'xls', 'ods', 'csv']) {
  const output = XLSX.write(workbook, { type: 'array', bookType });
  const parsed = XLSX.read(output, { type: 'array' });
  const rows = XLSX.utils.sheet_to_json(parsed.Sheets[parsed.SheetNames[0]], {
    header: 1,
    raw: false,
    defval: null,
  });

  if (!Array.isArray(rows) || rows.length !== 2 || String(rows[0][0]) !== 'Data' || String(rows[1][1]) !== 'Quota') {
    throw new Error(`Spreadsheet round-trip contract failed for ${bookType}.`);
  }
}

const htmlWorkbook = XLSX.read(
  '<table><tr><th>Data</th><th>Descricao</th><th>Valor</th></tr><tr><td>27/08/2026</td><td>Quota</td><td>12,50</td></tr></table>',
  { type: 'string', raw: true },
);
const htmlRows = XLSX.utils.sheet_to_json(htmlWorkbook.Sheets[htmlWorkbook.SheetNames[0]], { header: 1, raw: false, defval: null });
if (!Array.isArray(htmlRows) || String(htmlRows[1]?.[1]) !== 'Quota') {
  throw new Error('HTML-table spreadsheet contract used by bank statement import failed.');
}

console.log(`Spreadsheet import contract passed with SheetJS ${expectedVersion}; sha256=${actualChecksum}; formats=xlsx,xls,ods,csv,html.`);
