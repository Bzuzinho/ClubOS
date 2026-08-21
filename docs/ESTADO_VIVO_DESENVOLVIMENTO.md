# Estado Vivo de Desenvolvimento — ClubOS

> Fonte de verdade funcional e técnica do projeto ClubOS.
>
> Estado consolidado em 2026-08-21.
>
> O histórico detalhado anterior à consolidação está preservado em `docs/history/ESTADO_VIVO_DESENVOLVIMENTO_ATE_2026-08-20.md`.

---

## 1. Regra de utilização

Antes de qualquer desenvolvimento, bugfix, refatoração, auditoria ou alteração de infraestrutura, validar este documento e o código real em `main`.

As percentagens representam maturidade funcional e operacional real, não apenas existência de ficheiros.

Estratégia global:

`consolidar → endurecer → eliminar legacy → fechar módulos incompletos → acrescentar novas funcionalidades grandes`

Não está recomendada uma reescrita do ClubOS.

---

## 2. Estado global atual

| Área | Estado atual |
|---|---:|
| Implementação funcional global | ~75% |
| Prontidão operacional | ~74% |
| Arquitetura backend | Boa |
| Testes backend | Fortes |
| Frontend / E2E / mobile QA | Insuficientes |
| Infraestrutura / Disaster Recovery | Implementação e E2E validados; off-site real ainda por ativar |

Stack produtiva: Laravel 11, PHP 8.3, React 19 + TypeScript, Inertia, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 86% | H0.1a/H0.1b concluídas. H0.2 está integrado e foi validado E2E na Oracle VM com remote efémero; falta ativar R2 real e validar o primeiro ciclo remoto no provider. |
| Website público / construtor | 86% | Renderer, snapshots, publicação e dados dinâmicos avançados. Faltam header/footer globais, notícias completas e validação runtime multi-viewport. |
| Autenticação / Access Control | 78% | Auditoria e gates produtivos ativos. Validação real pós-cutover confirmou zero findings críticos, zero rotas mutáveis sem `module.access`, schema pronto e zero utilizadores com acesso sem `UserType` resolvido. Permanecem 83 warnings de capability granular. |
| Dashboard / entrada por perfil | 70% | Funcional, com leituras canónicas financeiras. Falta QA final por perfil e viewport. |
| Portal atleta / família | 63% | Estrutura funcional. Falta fecho mobile/PWA, UX e validação sistemática. |
| Membros / Pessoas | 85% | Normalização avançada. Família/EE ainda mantém múltiplas representações históricas a consolidar. |
| Família / EE / educandos | 70% | Gestão funcional existe; falta fonte relacional única e cutover progressivo do legacy. |
| Desportivo global | 70% | Principal frente funcional por fechar: Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup. |
| Planeamento desportivo | 65% | Base sólida; falta fechar UX, integrações e reporting. |
| Treinos / presenças / Cais | 70% | Núcleo funcional forte; falta consolidar fluxo ponta a ponta e QA operacional. |
| Competições / resultados | 63% | Estrutura funcional; falta integração final, reporting e remoção legacy. |
| Eventos | 75% | Lifecycle, recorrência, convocatórias e integrações corrigidos. Falta remover estruturas antigas e criar contract tests Eventos ↔ Desportivo. |
| Financeiro geral | 89% | Maduro; os CRUDs legacy de transações/categorias foram aposentados com contrato `410 Gone` e o antigo ecrã `Financeiro/Edit` deixou de expor placeholder, convergindo para a tab canónica de Faturas. Prioridade continua a ser consolidar invariantes e evitar novas fontes de verdade. |
| Fiscal | 65% | Workflow manual/controlado existe; falta decidir provider real ou fechar formalmente o modelo manual produtivo. |
| Inventário / Logística | 70% | `stock_movements` é ledger canónico; `product_variants.stock` continua por consolidar. |
| Loja | 60% | Falta lifecycle completo produto → stock → encomenda → pagamento → fiscal → cancelamento/devolução/reposição. |
| Comunicação | 60% | Falta pipeline assíncrono persistente com attempts, retry, idempotência e provider IDs. |
| Relatórios | 40% | Área menos madura; construir apenas depois de estabilizar fontes de verdade. |
| PWA / Mobile | 55% | Falta matriz QA sistemática Android/iOS/tablet/desktop, com atenção a scroll, overflow, fixed e `100vh`. |
| Importação de recibos antigos | 60% | Falta corpus real representativo e regression dataset idempotente. |

---

## 4. H0 — Production Hardening

### H0.1a — CI/CD e segurança SSH — concluída

Implementado e validado em produção:

- `ORACLE_VM_USER`, `ORACLE_VM_HOST`, `ORACLE_VM_APP_DIR`, `ORACLE_VM_SSH_KEY` e `ORACLE_VM_KNOWN_HOSTS` obrigatórios;
- sem defaults produtivos no deploy canónico;
- `StrictHostKeyChecking=yes` e host key pinned;
- `composer audit` com todos os advisories reportados e gate atual apenas para severidade `critical`;
- `npm audit --audit-level=critical`;
- auditoria produtiva de Access Control preservada como artefacto antes do gate;
- findings críticos de Access Control bloqueiam o deployment.

Baseline Composer atual: 34 advisories em 12 packages, sem `critical` no baseline validado. Remediação de high/medium fica para uma sprint de dependências com análise de compatibilidade.

### H0.1a.2 — Access Control — concluída

A primeira auditoria produtiva revelou 92 críticos, todos `mutating_admin_route_without_module_guard`:

- Configurações: 57;
- Financeiro: 33;
- Desportivo: 1;
- Membros: 1.

Após correção e novo diagnóstico produtivo:

- `critical_count=0`;
- `mutating_routes_without_module_guard_count=0`;
- `unresolved_user_type_count=0`;
- schema pronto;
- 83 warnings não críticos de granularidade: 57 Configurações, 25 Financeiro, 1 Desportivo.

A auditoria foi repetida depois do cutover atómico H0.1b e manteve exatamente este estado: zero críticos e 83 warnings. Os warnings representam rotas com guard de módulo mas sem capability granular específica; não são bypass crítico de módulo.

### H0.1b — Releases atómicas — concluída e validada em produção

Implementação integrada através das PRs #186 e #188.

Layout produtivo validado em 2026-08-21:

```txt
/var/www/clubmanager
  -> /var/www/clubmanager.deploy/current

/var/www/clubmanager.deploy/
  repository.git/
  releases/
  shared/
    .env
    storage/
  current -> releases/<release-ativa>/
  previous -> releases/<release-anterior>/ ou legacy/<rollback-target>/
  legacy/
  legacy-persistence/
```

Primeira release atómica validada:

- commit: `44bef4288ec99d8dd57ad65958c77ad5cf893648`;
- metadata: `layout=atomic-v1`;
- `/var/www/clubmanager` é symlink;
- `current` aponta para a release do commit promovido;
- `previous` constitui rollback target válido;
- `.env` e `storage` estão em estado partilhado;
- `public/build/manifest.json` existe na release ativa;
- aplicação não ficou em maintenance mode;
- Nginx → TLS → PHP-FPM → Laravel `/up` devolveu HTTP 200;
- auditoria Access Control pós-cutover devolveu `critical_count=0`.

Garantias do modelo de deploy:

- cada release é construída fora do path servido;
- o SHA de `origin/main` tem de coincidir exatamente com o SHA promovido pela CI;
- `public/build` é produzido no runner e copiado para a release;
- `.env` e `storage` são partilhados entre releases;
- `composer install --no-dev` corre na release isolada;
- migrations têm `migrate --pretend` antes de `migrate --force`;
- migrations produtivas têm de ser backward-compatible e seguir expand/contract; rollback de código não reverte migrations;
- o primeiro cutover usa `renameat2(RENAME_EXCHANGE)` para substituir o diretório produtivo por symlink sem janela de path inexistente;
- deploys seguintes trocam `current` atomicamente;
- PHP-FPM é recarregado depois do cutover para invalidar OPcache;
- falhas pós-switch tentam rollback automático para `previous`;
- rollback manual está disponível em `/usr/local/bin/clubmanager-rollback-release.sh`;
- retenção padrão: 5 releases, preservando sempre `current` e `previous`;
- o deploy direto antigo é desativado;
- `bin/deploy-vm.sh` é apenas wrapper do orquestrador canónico `npm run deploy:vm`.

A primeira tentativa de cutover foi abortada de forma segura antes da troca do path porque o healthcheck PHP interno não encontrava o front controller esperado pelo router Laravel. A produção permaneceu no layout antigo, sem maintenance mode e com `/up=200`. O hotfix H0.1b.1 adicionou um front controller de compatibilidade na raiz exclusivamente para o servidor PHP isolado e um smoke test que reproduz exatamente esse comando; a tentativa seguinte realizou o cutover com sucesso.

### H0.2 — Disaster Recovery — implementação e E2E validados; ativação off-site real pendente

Diagnóstico real da Oracle VM em 2026-08-21:

- cron local ativo às 02:15 UTC;
- 7 dumps diários consecutivos presentes;
- último dump validado: `clubmanager-prod-20260821-021501.dump` (~1,56 MB);
- checksum SHA256 do último dump: OK;
- catálogo `pg_restore --list` com PostgreSQL 17: OK;
- `storage` partilhado do layout atómico: ~30,5 MB no levantamento inicial;
- ~35 GB livres na VM no levantamento inicial;
- dump criado com PostgreSQL 17;
- `pg_restore` default do sistema é PostgreSQL 14 e não é adequado aos dumps PG17;
- binários PostgreSQL 17 existem em `/usr/lib/postgresql/17/bin`;
- restore smoke test local real com PostgreSQL 17 concluído em ~8 segundos;
- restore local: 208 tabelas públicas e 214 migrations.

Implementado e integrado em `main` através da PR #191 e hotfixes H0.2.1/H0.2.2/H0.2.3:

- backup local valida SHA256 **e** `pg_restore --list` com PostgreSQL 17;
- restore local exige ferramentas PostgreSQL 17;
- arquivo off-site inclui dump, checksum, `.env`, `storage/app/public` e manifesto;
- cifragem client-side GPG AES-256 antes do upload;
- transporte S3-compatible via `rclone`, com Cloudflare R2 como configuração recomendada;
- upload remoto é relido e comparado por SHA256 antes de ser marcado como sucesso;
- retenção lógica 7 diários / 4 semanais / 12 mensais;
- retenção tolera Bucket Lock, permitindo retenção mais longa mas nunca mais curta;
- restore test off-site semanal para BD temporária, nunca sobre a produção;
- health check local/off-site com thresholds de 26h/30h/8 dias;
- monitor diário via GitHub Actions;
- RPO operacional inicial formalizado em <=24h;
- RTO operacional inicial formalizado em <=2h;
- runbook completo em `docs/DR_RUNBOOK.md`.

Validação E2E do pipeline DR na Oracle VM, já com o hotfix H0.2.3 ativo:

- release/commit produtivo exercitado: `325b97c4e69b4c6432f26d373e807cd2d32be9c9`;
- foi criado um remote efémero local exclusivamente para simular o provider off-site sem alterar a configuração DR real;
- arquivo DR criado e cifrado com GPG AES-256 antes do transporte;
- upload concluído e verificado por leitura remota;
- `encrypted_object_count=1` e `plaintext_remote_count=0`;
- objeto descarregado e decifrado;
- restore executado com PostgreSQL 17 numa base temporária, nunca sobre produção;
- restore E2E: 208 tabelas públicas, 214 migrations, 14 segundos;
- health estrito após o restore: `local_integrity=ok`, `offsite_status=ok`, `restore_test_status=ok`;
- resultado final do cenário: `local_offsite_e2e=ok`.

A primeira execução E2E revelou um problema real de permissões: o dump temporário era `postgres:postgres 0600`, mas o diretório `mktemp` pai era `0700 root`, impedindo o `pg_restore` executado como `postgres` de o atravessar. A PR #196/H0.2.3 corrigiu o workspace para `root:postgres 0710`, mantendo o dump `0600` e sem permissões globais abertas; o E2E completo passou depois do hotfix estar ativo em produção.

Esta validação prova o pipeline de software ponta a ponta, mas **não substitui a validação num provider off-site real**. A configuração produtiva continua com `dr_enabled=no` até ser criado e ativado o backend remoto.

Falta para fechar H0.2:

- criar/confirmar bucket R2 privado;
- configurar Bucket Lock por prefixo;
- criar token S3 limitado ao bucket;
- configurar os 5 secrets `CLUBOS_DR_*` no GitHub;
- executar bootstrap na VM;
- validar primeiro backup off-site cifrado no R2;
- validar primeiro restore test **a partir do objeto R2**;
- confirmar `DR Health Monitor` verde com off-site real ativado.

Até essa ativação, os backups locais continuam saudáveis e o pipeline DR está provado E2E, mas produção e os backups persistentes ainda permanecem no mesmo domínio de falha físico.

---

## 5. Dívida estrutural prioritária

- Família/EE: convergir `user_guardian`, `familias/familia_user`, `user_relationships` e compatibilidades para uma fonte canónica.
- Inventário: integrar `product_variants.stock` no ledger canónico ou transformar variantes/SKU em entidade física de inventário.
- Desportivo: fechar fluxo Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup.
- Eventos: remover estruturas de compatibilidade já sem consumo e criar contract tests com Desportivo.
- Rotas: modularizar `routes/web.php` sem alterar URLs.
- Fiscal: implementar provider real ou formalizar definitivamente o workflow manual como modelo produtivo.
- Frontend QA: typecheck, lint, unit tests, E2E, accessibility e matriz mobile/desktop.
- Access Control: resolver os 83 warnings de capability granular sem reabrir bypasses de módulo.

---

## 6. Prioridades recomendadas

| Ordem | Sprint | Objetivo |
|---:|---|---|
| 1 | H0.2 | Ativar R2, primeiro backup cifrado remoto, restore test remoto e monitor verde; depois fechar DR. |
| 2 | H1 | QA transversal + dependency remediation. |
| 3 | H2 | Família/EE, stock variantes, legacy e rotas modulares. |
| 4 | H3 | Fecho Desportivo ponta a ponta. |
| 5 | H4 | Decisão e fecho Fiscal. |
| 6 | H5 | Loja + Logística lifecycle completo. |
| 7 | H6 | Comunicação assíncrona e futura integração Redes. |
| 8 | H7 | Portal/PWA/mobile. |
| 9 | H8 | Reporting consolidado. |
| 10 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo: concluir H0.2 ativando o backend off-site real e validando backup + restore a partir do provider. Não iniciar uma feature funcional grande antes desta validação.

---

## 7. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
| 2026-08-21 | Infraestrutura / DR | H0.2 E2E fechado ao nível do software: arquivo → cifragem AES-256 → upload → verificação → download → decifragem → restore PG17 temporário → health estrito. A primeira execução detetou `Permission denied` no dump temporário; H0.2.3 corrigiu a travessia do workspace sem abrir permissões globais. | PR #196; diagnóstico temporário #192; produção `325b97c4e69b4c6432f26d373e807cd2d32be9c9`; E2E: 208 tabelas, 214 migrations, 14 s, `local_offsite_e2e=ok` | Implementação e E2E validados na Oracle VM. R2 real continua desativado; falta bucket/token/locks/secrets/bootstrap + primeiro backup/restore remoto real + monitor verde. |
| 2026-08-21 | Financeiro / Legacy cleanup | Aposentados os CRUDs legacy de transações e categorias financeiras com `410 Gone` e redirecionamento semântico para o Financeiro canónico; removido o último placeholder ativo de `Financeiro/Edit`, que passa a convergir para a tab canónica de Faturas. | PRs #179/#180; CI #678/#680; commits `6ea4c80bc0c8ef716f8c6ba6746c1aeb950dcb58` e `a45da50dd9ab6bd7afe2ece6deea04d4a36be7df` | Integrado em `main` e incluído na cadeia de releases produtivas atualmente em `325b97c4e69b4c6432f26d373e807cd2d32be9c9`; sem migrations nem alteração de dados. |
| 2026-08-21 | Infraestrutura / DR | H0.2: diagnóstico produtivo confirmou 7 dumps locais; corrigida a diferença PG17 dump vs `pg_restore` 14; restore real PG17 provado em BD temporária (8s, 208 tabelas, 214 migrations). Implementado pipeline off-site cifrado, retenção 7/4/12, restore test remoto, health monitor e RPO/RTO. | `scripts/ops/database/backup-local-postgres.sh`, `scripts/ops/database/restore-local-postgres.sh`, `scripts/ops/dr/*`, `.github/workflows/dr-health-monitor.yml`, `tests/Unit/Operations/DisasterRecoveryContractTest.php`, `docs/DR_RUNBOOK.md`, diagnóstico PR #190 | Implementação integrada e posteriormente validada E2E; ativação do provider off-site real continua pendente. |
| 2026-08-21 | Infraestrutura / Deploy | H0.1b fechada: releases atómicas, shared state, `current/previous`, healthchecks pre/pós-cutover e rollback. A primeira tentativa abortou antes do cutover por incompatibilidade do router PHP isolado; H0.1b.1 adicionou front controller de compatibilidade + smoke test e o segundo cutover concluiu. | PRs #186/#188, primeira release atómica validada `20260821144101-44bef4288ec9`, commit `44bef4288ec99d8dd57ad65958c77ad5cf893648`, diagnóstico pós-cutover read-only | Produção em `atomic-v1`, rollback target preservado, Access Control pós-cutover com `critical_count=0`. H0.1b concluída. |
| 2026-08-21 | Access Control / Rotas | H0.1a.2 eliminou os 92 gaps críticos de `module.access`. | `routes/web.php`, `routes/desportivo_member_contract.php`, `routes/member_documents.php`, `tests/Feature/AccessControl/AccessControlReadinessAuditCommandTest.php`, diagnóstico produtivo | Produção validada antes e depois do cutover com `critical_count=0`; 83 warnings granulares permanecem. |
| 2026-08-21 | Infraestrutura / CI/CD | H0.1a endureceu secrets, SSH pinned, dependency audits e gates críticos; H0.1a.1 passou a preservar a auditoria de Access Control antes do gate. | `.github/workflows/ci.yml`, `bin/deploy-vm.mjs`, PRs #181 e #182 | Concluído em produção. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`
