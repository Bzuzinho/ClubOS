import { readFileSync, writeFileSync } from 'node:fs';

const ciPath = '.github/workflows/ci.yml';
let ci = readFileSync(ciPath, 'utf8');

if (!ci.includes('node --check scripts/qa/xlsx-import-contract.mjs')) {
  ci = ci.replace(
    '          node --check scripts/qa/npm-audit-ratchet.mjs\n',
    '          node --check scripts/qa/npm-audit-ratchet.mjs\n          node --check scripts/qa/xlsx-import-contract.mjs\n',
  );
}

if (!ci.includes('Verify spreadsheet import contract')) {
  ci = ci.replace(
    '      - name: Audit Node dependencies\n        run: node scripts/qa/npm-audit-ratchet.mjs\n\n      - name: TypeScript debt ratchet\n',
    '      - name: Audit Node dependencies\n        run: node scripts/qa/npm-audit-ratchet.mjs\n\n      - name: Verify spreadsheet import contract\n        run: node scripts/qa/xlsx-import-contract.mjs\n\n      - name: TypeScript debt ratchet\n',
  );
}

if (!ci.includes('Verify spreadsheet import contract') || !ci.includes('node --check scripts/qa/xlsx-import-contract.mjs')) {
  throw new Error('Could not wire spreadsheet import contract into CI.');
}
writeFileSync(ciPath, ci);

const statePath = 'docs/ESTADO_VIVO_DESENVOLVIMENTO.md';
let state = readFileSync(statePath, 'utf8');
state = state.replace(/> Estado consolidado em \d{4}-\d{2}-\d{2}\./, '> Estado consolidado em 2026-08-27.');
state = state.replace(
  /^\| Base técnica \/ arquitetura \|.*$/m,
  '| Base técnica / arquitetura | 92% | H0.1a/H0.1b/H0.2 concluídos em produção. H1.1 concluiu dependency remediation compatível e QA ratchets; H1.4–H1.13 eliminaram a dívida TypeScript para 0/0; H1.14 elevou o backend para Laravel 13 e fechou Composer em 0 advisories; H1.15 eliminou a última vulnerabilidade npm com SheetJS 0.20.3 vendorizado e ratchet npm em zero. Permanecem hardening R2 e expansão da QA frontend. |',
);
state = state.replace(
  /^- npm aceita temporariamente apenas `xlsx`.*$/m,
  '- npm passou em H1.15 para baseline estrito de 0 vulnerabilidades; qualquer finding novo falha o CI;',
);
state = state.replace(
  /Pendências H1 separadas:\n\n1\. Laravel 12\+[^\n]*\n2\. migração de `xlsx`[^\n]*\n3\. R2 least privilege[^\n]*\n4\. lint, unit\/component tests, E2E, accessibility e matriz mobile\/desktop\./,
  'Pendências H1 separadas:\n\n1. R2 least privilege e confirmação Bucket Lock;\n2. lint, unit/component tests, E2E, accessibility e matriz mobile/desktop.',
);

if (!state.includes('### H1.15 — Fecho npm `xlsx` security debt — PR #223')) {
  const marker = 'A PR #222 é o gate canónico de integração, incluindo CI transversal e PostgreSQL antes de merge/deploy.\n\n';
  const section = `### H1.15 — Fecho npm \`xlsx\` security debt — PR #223\n\nObjetivo: remover a última vulnerabilidade npm residual sem degradar os importadores de folhas de cálculo de Membros e Financeiro.\n\nImplementado:\n\n- \`xlsx\` 0.18.5 do npm registry substituído pela release oficial SheetJS CE 0.20.3 vendorizada em \`vendor/xlsx-0.20.3.tgz\`;\n- \`package.json\` referencia o artefacto local por \`file:\`, pelo que \`npm ci\` e o deploy deixam de depender do CDN;\n- SHA-256 versionado: \`8dc73fc3b00203e72d176e85b50938627c7b086e607c682e8d3c22c02bb99fe8\`;\n- contrato permanente valida os entrypoints \`xlsx\` e \`xlsx/xlsx.mjs\`, checksum e versão runtime;\n- round-trips validados para XLSX, XLS, ODS e CSV e leitura HTML preservada para extratos bancários;\n- npm audit passou de 1 high residual para 0 vulnerabilidades e o ratchet foi apertado para zero, sem exceções por package;\n- TypeScript mantém 0/0 e o build Vite continua verde.\n\nSem migrations, sem alterações de dados e sem alteração das regras de importação, reconciliação ou negócio.\n\n`;
  if (!state.includes(marker)) throw new Error('H1.14 insertion marker not found.');
  state = state.replace(marker, marker + section);
}

state = state.replace(
  /^\| 1 \| H1 \|.*$/m,
  '| 1 | H1 | H1.1 e H1.4–H1.15 concluíram os ratchets de dependências e TypeScript: Composer 0, npm 0, TypeScript 0/0; continuar com hardening residual R2 e QA frontend. |',
);
state = state.replace(
  /^Próximo passo:.*$/m,
  'Próximo passo: fechar o hardening residual R2 com credencial `Object Read & Write` limitada ao bucket de backup e confirmação de Bucket Lock; depois evoluir a matriz frontend de lint, unit/component, E2E, acessibilidade e mobile/desktop.',
);

const historyHeader = '| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |\n|---|---|---|---|---|\n';
if (!state.includes('| 2026-08-27 | QA / Dependências npm | H1.15')) {
  const rows = '| 2026-08-27 | QA / Dependências npm | H1.15 substituiu `xlsx` 0.18.5 pela release oficial SheetJS CE 0.20.3 vendorizada, preservando XLSX/XLS/ODS/CSV/HTML e eliminando a última vulnerabilidade npm residual. | PR #223; `vendor/xlsx-0.20.3.tgz`; `scripts/qa/xlsx-import-contract.mjs`; `scripts/qa/npm-audit-ratchet.mjs` | npm audit e ratchet fechados em 0 vulnerabilidades; resta R2 hardening e QA frontend em H1. |\n| 2026-08-26 | QA / Framework / Composer | H1.14 elevou Laravel 11 para Laravel 13.29.0, preservou UUIDv4 nos modelos e eliminou os 3 advisories Composer residuais. | PR #222; CI #852/#853; merge `99ba31100620754167053e4251ee0f97da282dc6` | Integrado e deployado na Oracle VM; Composer ratchet fechado em 0 advisories. |\n';
  if (!state.includes(historyHeader)) throw new Error('History table header not found.');
  state = state.replace(historyHeader, historyHeader + rows);
}

for (const required of [
  'H1.15 — Fecho npm `xlsx` security debt — PR #223',
  'Composer 0, npm 0, TypeScript 0/0',
  'Próximo passo: fechar o hardening residual R2',
]) {
  if (!state.includes(required)) throw new Error(`Living-state update missing: ${required}`);
}
writeFileSync(statePath, state);
