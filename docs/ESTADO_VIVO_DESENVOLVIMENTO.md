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
| Prontidão operacional | ~70% |
| Arquitetura backend | Boa |
| Testes backend | Fortes |
| Frontend / E2E / mobile QA | Insuficientes |
| Infraestrutura / Disaster Recovery | Principal risco operacional |

Stack produtiva: Laravel 11, PHP 8.3, React 19 + TypeScript, Inertia, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 82% | Backend sólido. H0.1a concluída; H0.1b implementada em branch e aguarda primeiro cutover produtivo. Faltam DR, observabilidade e modularização de rotas. |
| Website público / construtor | 86% | Renderer, snapshots, publicação e dados dinâmicos avançados. Faltam header/footer globais, notícias completas e validação runtime multi-viewport. |
| Autenticação / Access Control | 78% | Auditoria e gates produtivos ativos. Validação real confirmou zero findings críticos, zero rotas mutáveis sem `module.access`, schema pronto e zero utilizadores com acesso sem `UserType` resolvido. Permanecem 83 warnings de capability granular. |
| Dashboard / entrada por perfil | 70% | Funcional, com leituras canónicas financeiras. Falta QA final por perfil e viewport. |
| Portal atleta / família | 63% | Estrutura funcional. Falta fecho mobile/PWA, UX e validação sistemática. |
| Membros / Pessoas | 85% | Normalização avançada. Família/EE ainda mantém múltiplas representações históricas a consolidar. |
| Família / EE / educandos | 70% | Gestão funcional existe; falta fonte relacional única e cutover progressivo do legacy. |
| Desportivo global | 70% | Principal frente funcional por fechar: Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup. |
| Planeamento desportivo | 65% | Base sólida; falta fechar UX, integrações e reporting. |
| Treinos / presenças / Cais | 70% | Núcleo funcional forte; falta consolidar fluxo ponta a ponta e QA operacional. |
| Competições / resultados | 63% | Estrutura funcional; falta integração final, reporting e remoção legacy. |
| Eventos | 75% | Lifecycle, recorrência, convocatórias e integrações corrigidos. Falta remover estruturas antigas e criar contract tests Eventos ↔ Desportivo. |
| Financeiro geral | 89% | Maduro; prioridade é consolidar invariantes e evitar novas fontes de verdade. |
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

Os 83 warnings representam rotas com guard de módulo mas sem capability granular específica; não são bypass crítico de módulo.

### H0.1b — Releases atómicas — implementação concluída em branch, validação produtiva pendente

Branch: `hardening/h0-1b-atomic-releases`.

Arquitetura alvo:

```txt
/var/www/clubmanager
  -> /var/www/clubmanager.deploy/current

/var/www/clubmanager.deploy/
  repository.git/
  releases/
  shared/
    .env
    storage/
  current -> releases/<release-atual>
  previous -> release anterior ou legacy inicial
  legacy/
  legacy-persistence/
```

Decisões e garantias implementadas:

- `/var/www/clubmanager` mantém-se como path compatível para Nginx, scheduler e backups;
- cada release é construída fora do path servido, sem alterar a working tree produtiva;
- o SHA de `origin/main` tem de ser exatamente o SHA promovido pela CI;
- `public/build` é produzido no runner e copiado para a release;
- `.env` e `storage` são partilhados entre releases;
- `composer install --no-dev` corre na release isolada;
- migrations têm `migrate --pretend` antes de `migrate --force`;
- migrations produtivas têm de ser backward-compatible e seguir expand/contract; rollback de código não reverte migrations;
- healthcheck pre-cutover exige `/up = HTTP 200` na release isolada;
- primeiro cutover valida suporte a `renameat2(RENAME_EXCHANGE)` e converte o diretório antigo em symlink sem uma janela de path inexistente;
- deploys seguintes trocam `current` atomicamente;
- PHP-FPM é recarregado depois do cutover para invalidar OPcache;
- healthcheck pós-cutover testa Nginx → TLS → PHP-FPM → Laravel `/up` e exige HTTP 200;
- falhas após a troca tentam rollback automático para `previous`;
- rollback manual disponível em `/usr/local/bin/clubmanager-rollback-release.sh`;
- retenção padrão: 5 releases, preservando sempre `current` e `previous`;
- o antigo deploy direto `/usr/local/bin/clubmanager-deploy-backend.sh` é desativado no primeiro cutover;
- `bin/deploy-vm.sh` é apenas wrapper do orquestrador canónico `npm run deploy:vm`.

O diagnóstico read-only da VM confirmou que esta estratégia não exige alterar o root do Nginx nem os crons existentes. O primeiro cutover produtivo é o próximo gate da H0.1b.

### H0.2 — Disaster Recovery — pendente

Produção, PostgreSQL e backups locais continuam no mesmo domínio de falha da Oracle VM.

Pendências críticas:

- backup off-site cifrado;
- retenção 7 diários / 4 semanais / 6–12 mensais;
- checksum e verificação;
- restore test periódico;
- RPO/RTO formalizados;
- alerta de backup falhado ou demasiado antigo.

O backup local PostgreSQL 17 existente continua a manter 7 dumps diários com checksum.

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
| 1 | H0.1b | Validar primeiro cutover produtivo das releases atómicas, healthcheck real e rollback path. |
| 2 | H0.2 | Backup externo cifrado, retenção, checksum, restore test, alertas e RPO/RTO. |
| 3 | H1 | QA transversal + dependency remediation. |
| 4 | H2 | Família/EE, stock variantes, legacy e rotas modulares. |
| 5 | H3 | Fecho Desportivo ponta a ponta. |
| 6 | H4 | Decisão e fecho Fiscal. |
| 7 | H5 | Loja + Logística lifecycle completo. |
| 8 | H6 | Comunicação assíncrona e futura integração Redes. |
| 9 | H7 | Portal/PWA/mobile. |
| 10 | H8 | Reporting consolidado. |
| 11 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo: CI da H0.1b → merge apenas se verde → primeiro cutover produtivo → confirmar `current/previous`, SHA, shared state, `/up` HTTP 200 e auditorias pós-deploy → só depois considerar H0.1b encerrada.

---

## 7. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
| 2026-08-21 | Infraestrutura / Deploy | H0.1b implementa releases atómicas mantendo `/var/www/clubmanager` como path compatível, estado partilhado, healthchecks pre/pós-cutover e rollback. | `bin/deploy-vm.mjs`, `bin/deploy-vm.sh`, `bin/remote-deploy-backend.sh`, `bin/remote-healthcheck.sh`, `bin/remote-release-rollback.sh`, `tests/Unit/Deployment/AtomicReleaseDeploymentContractTest.php`, `docs/deploy/DEPLOY_WORKFLOW.md`, `docs/OPERACOES_SERVIDOR.md` | CI e primeiro cutover produtivo ainda por validar; migrations exigem expand/contract. |
| 2026-08-21 | Access Control / Rotas | H0.1a.2 eliminou os 92 gaps críticos de `module.access`. | `routes/web.php`, `routes/desportivo_member_contract.php`, `routes/member_documents.php`, `tests/Feature/AccessControl/AccessControlReadinessAuditCommandTest.php`, diagnóstico produtivo | Produção validada com `critical_count=0`; 83 warnings granulares permanecem. |
| 2026-08-21 | Infraestrutura / CI/CD | H0.1a endureceu secrets, SSH pinned, dependency audits e gates críticos; H0.1a.1 passou a preservar a auditoria de Access Control antes do gate. | `.github/workflows/ci.yml`, `bin/deploy-vm.mjs`, PRs #181 e #182 | Concluído em produção. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`
