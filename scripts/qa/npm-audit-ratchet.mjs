import { appendFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const configuredAttempts = Number.parseInt(process.env.NPM_AUDIT_ATTEMPTS ?? '3', 10);
const maxAttempts = Number.isInteger(configuredAttempts) && configuredAttempts > 0 ? configuredAttempts : 3;
const configuredTimeout = Number.parseInt(process.env.NPM_AUDIT_TIMEOUT_MS ?? '600000', 10);
const timeoutMs = Number.isInteger(configuredTimeout) && configuredTimeout > 0 ? configuredTimeout : 600000;

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const parseReport = (stdout) => {
  let report;

  try {
    report = JSON.parse(stdout || '{}');
  } catch {
    return null;
  }

  const vulnerabilities = report?.vulnerabilities;
  const metadata = report?.metadata?.vulnerabilities;

  if (
    !report ||
    typeof report !== 'object' ||
    Array.isArray(report) ||
    !vulnerabilities ||
    typeof vulnerabilities !== 'object' ||
    Array.isArray(vulnerabilities) ||
    !metadata ||
    typeof metadata !== 'object' ||
    Array.isArray(metadata)
  ) {
    return null;
  }

  return report;
};

const summarize = (report) => {
  const vulnerabilities = report.vulnerabilities;
  const metadata = report.metadata.vulnerabilities;
  const names = Object.keys(vulnerabilities);

  return {
    names,
    total: Number(metadata.total ?? names.length ?? 0),
    critical: Number(metadata.critical ?? 0),
    high: Number(metadata.high ?? 0),
    moderate: Number(metadata.moderate ?? 0),
    low: Number(metadata.low ?? 0),
    info: Number(metadata.info ?? 0),
  };
};

const hasVulnerabilities = ({ names, total, critical, high, moderate, low, info }) => (
  total !== 0 ||
  critical !== 0 ||
  high !== 0 ||
  moderate !== 0 ||
  low !== 0 ||
  info !== 0 ||
  names.length !== 0
);

const appendSummary = (summary, attempt, exitCode) => {
  if (!process.env.GITHUB_STEP_SUMMARY) {
    return;
  }

  appendFileSync(
    process.env.GITHUB_STEP_SUMMARY,
    [
      '## npm dependency security ratchet',
      '',
      `- Total vulnerabilities: \`${summary.total}\``,
      `- Critical: \`${summary.critical}\``,
      `- High: \`${summary.high}\``,
      `- Moderate: \`${summary.moderate}\``,
      `- Low: \`${summary.low}\``,
      `- Info: \`${summary.info}\``,
      `- Vulnerable packages: \`${summary.names.join(', ') || 'none'}\``,
      `- npm audit exit code: \`${exitCode}\``,
      `- Attempts: \`${attempt}\``,
      '',
    ].join('\n'),
  );
};

const printTechnicalDiagnostics = (result) => {
  if (result.error) {
    console.error(result.error);
  }

  const stderr = (result.stderr || '').trim();
  if (stderr) {
    console.error(stderr.split('\n').slice(-20).join('\n'));
  }
};

let finalResult = null;
let finalReport = null;
let finalSummary = null;
let finalAttempt = 0;

for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
  finalAttempt = attempt;

  const result = spawnSync(
    npmCommand,
    ['audit', '--json'],
    {
      encoding: 'utf8',
      timeout: timeoutMs,
      maxBuffer: 10 * 1024 * 1024,
    },
  );

  const report = parseReport(result.stdout);
  const exitCode = Number.isInteger(result.status) ? result.status : 2;

  finalResult = result;
  finalReport = report;
  finalSummary = report ? summarize(report) : null;

  if (finalSummary) {
    console.log(
      `npm security ratchet attempt ${attempt}/${maxAttempts}: total=${finalSummary.total}; critical=${finalSummary.critical}; high=${finalSummary.high}; moderate=${finalSummary.moderate}; low=${finalSummary.low}; info=${finalSummary.info}; packages=${finalSummary.names.join(', ') || 'none'}; exit=${exitCode}.`,
    );

    if (hasVulnerabilities(finalSummary)) {
      appendSummary(finalSummary, attempt, exitCode);
      console.error('npm dependency security baseline regressed: zero vulnerabilities are permitted after H1.15.');
      process.exit(1);
    }

    if (exitCode === 0) {
      appendSummary(finalSummary, attempt, exitCode);
      console.log('npm security ratchet passed at zero vulnerabilities.');
      process.exit(0);
    }
  }

  if (attempt < maxAttempts) {
    const reason = report
      ? `zero-vulnerability report returned exit code ${exitCode}`
      : 'audit did not return the expected JSON security report';

    console.warn(`::warning::npm audit attempt ${attempt}/${maxAttempts} failed technically (${reason}); retrying`);
    printTechnicalDiagnostics(result);
    await sleep(attempt * 2000);
  }
}

if (!finalReport || !finalSummary) {
  console.error(`npm audit did not produce a valid JSON security report after ${maxAttempts} attempt(s).`);
  if (finalResult) {
    printTechnicalDiagnostics(finalResult);
  }
  process.exit(70);
}

appendSummary(
  finalSummary,
  finalAttempt,
  Number.isInteger(finalResult?.status) ? finalResult.status : 2,
);
console.error(
  `npm audit remained technically inconsistent after ${maxAttempts} attempt(s): zero vulnerabilities were reported but the command did not exit successfully.`,
);
if (finalResult) {
  printTechnicalDiagnostics(finalResult);
}
process.exit(70);
