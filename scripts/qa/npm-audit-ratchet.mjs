import { appendFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const result = spawnSync(
  process.platform === 'win32' ? 'npm.cmd' : 'npm',
  ['audit', '--json'],
  { encoding: 'utf8' },
);

let report;
try {
  report = JSON.parse(result.stdout || '{}');
} catch (error) {
  console.error('npm audit did not return valid JSON.');
  console.error(result.stderr || result.stdout || error);
  process.exit(2);
}

const vulnerabilities = report.vulnerabilities ?? {};
const metadata = report.metadata?.vulnerabilities ?? {};
const names = Object.keys(vulnerabilities);
const unexpected = names.filter((name) => name !== 'xlsx');
const total = Number(metadata.total ?? names.length ?? 0);
const critical = Number(metadata.critical ?? 0);
const high = Number(metadata.high ?? 0);
const moderate = Number(metadata.moderate ?? 0);
const low = Number(metadata.low ?? 0);
const xlsx = vulnerabilities.xlsx;

console.log(
  `npm security ratchet: total=${total}; critical=${critical}; high=${high}; moderate=${moderate}; low=${low}; packages=${names.join(', ') || 'none'}.`,
);

if (process.env.GITHUB_STEP_SUMMARY) {
  appendFileSync(
    process.env.GITHUB_STEP_SUMMARY,
    [
      '## npm dependency security ratchet',
      '',
      `- Total vulnerabilities: \`${total}\``,
      `- Critical: \`${critical}\``,
      `- High: \`${high}\``,
      `- Moderate: \`${moderate}\``,
      `- Low: \`${low}\``,
      `- Residual xlsx advisory present: \`${Boolean(xlsx)}\``,
      '',
    ].join('\n'),
  );
}

if (critical > 0) {
  console.error(`npm audit reported ${critical} critical vulnerability/vulnerabilities.`);
  process.exit(1);
}

if (unexpected.length > 0) {
  console.error(`npm security debt regressed outside the accepted xlsx exception: ${unexpected.join(', ')}`);
  process.exit(1);
}

if (total > 1 || high > 1 || moderate > 0 || low > 0) {
  console.error('npm vulnerability baseline regressed (max total=1, high=1, moderate=0, low=0).');
  process.exit(1);
}

if (xlsx && xlsx.fixAvailable !== false) {
  console.error('The residual xlsx advisory now has a fix available; remove the exception instead of preserving the baseline.');
  process.exit(1);
}

if (xlsx) {
  console.warn('Residual xlsx high-severity advisory remains accepted temporarily because npm reports fixAvailable=false; migration is tracked in H1.');
}

if (![0, 1].includes(result.status ?? 2)) {
  console.error(`npm audit failed unexpectedly with exit code ${result.status ?? 'unknown'}.`);
  process.exit(result.status || 2);
}

console.log('npm security ratchet passed.');
