import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const baselinePath = new URL('../../qa/baselines/typescript.json', import.meta.url);
const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));

if (baseline.contract !== 'clubos-typescript-baseline-v1') {
  console.error(`Invalid TypeScript baseline contract: ${baseline.contract ?? 'missing'}`);
  process.exit(2);
}

for (const key of ['max_errors', 'max_affected_files']) {
  if (!Number.isInteger(baseline[key]) || baseline[key] < 0) {
    console.error(`Invalid TypeScript baseline value for ${key}`);
    process.exit(2);
  }
}

const result = spawnSync(
  process.platform === 'win32' ? 'npx.cmd' : 'npx',
  ['--no-install', 'tsc', '--noEmit', '--pretty', 'false'],
  { encoding: 'utf8' },
);

const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
const lines = output.split(/\r?\n/).filter(Boolean);
const errorLines = lines.filter((line) => /error TS\d+:/.test(line));
const affectedFiles = new Set();
const errorCountsByFile = new Map();
const errorCountsByCode = new Map();

for (const line of errorLines) {
  const match = line.match(/^(.+?)\(\d+,\d+\):\s+error (TS\d+):/);
  if (match) {
    const [, file, code] = match;
    affectedFiles.add(file);
    errorCountsByFile.set(file, (errorCountsByFile.get(file) ?? 0) + 1);
    errorCountsByCode.set(code, (errorCountsByCode.get(code) ?? 0) + 1);
  }
}

const errorCount = errorLines.length;
const affectedFileCount = affectedFiles.size;

console.log(`TypeScript ratchet: ${errorCount} error(s) across ${affectedFileCount} file(s).`);
console.log(`Baseline ceiling: ${baseline.max_errors} error(s) across ${baseline.max_affected_files} file(s).`);
console.log('TypeScript diagnostics by file:');
for (const [file, count] of [...errorCountsByFile.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))) {
  console.log(`${count}\t${file}`);
}
console.log('TypeScript diagnostics by code:');
for (const [code, count] of [...errorCountsByCode.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))) {
  console.log(`${count}\t${code}`);
}

if (result.status === 0) {
  if (errorCount !== 0) {
    console.error('tsc exited successfully but TypeScript errors were parsed from its output.');
    process.exit(2);
  }

  console.log('TypeScript is clean; ratchet passed.');
  process.exit(0);
}

if (errorCount === 0) {
  console.error(output || `tsc failed with exit code ${result.status ?? 'unknown'} without parseable TypeScript diagnostics.`);
  process.exit(result.status || 2);
}

if (errorCount > baseline.max_errors || affectedFileCount > baseline.max_affected_files) {
  console.error('TypeScript debt regressed above the accepted H1 baseline.');
  console.error(errorLines.slice(0, 40).join('\n'));
  process.exit(1);
}

if (errorCount < baseline.max_errors || affectedFileCount < baseline.max_affected_files) {
  console.log('TypeScript debt improved. Lower qa/baselines/typescript.json in the same change to lock in the gain.');
}

console.log('TypeScript ratchet passed without regression.');
