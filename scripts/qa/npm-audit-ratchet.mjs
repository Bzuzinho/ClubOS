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
const total = Number(metadata.total ?? names.length ?? 0);
const critical = Number(metadata.critical ?? 0);
const high = Number(metadata.high ?? 0);
const moderate = Number(metadata.moderate ?? 0);
const low = Number(metadata.low ?? 0);
const info = Number(metadata.info ?? 0);

console.log(
  `npm security ratchet: total=${total}; critical=${critical}; high=${high}; moderate=${moderate}; low=${low}; info=${info}; packages=${names.join(', ') || 'none'}.`,
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
      `- Info: \`${info}\``,
      `- Vulnerable packages: \`${names.join(', ') || 'none'}\``,
      '',
    ].join('\n'),
  );
}

if (total !== 0 || critical !== 0 || high !== 0 || moderate !== 0 || low !== 0 || info !== 0 || names.length !== 0) {
  console.error('npm dependency security baseline regressed: zero vulnerabilities are permitted after H1.15.');
  process.exit(1);
}

if ((result.status ?? 2) !== 0) {
  console.error(`npm audit failed unexpectedly with exit code ${result.status ?? 'unknown'} despite a zero-vulnerability report.`);
  process.exit(result.status || 2);
}

console.log('npm security ratchet passed at zero vulnerabilities.');
