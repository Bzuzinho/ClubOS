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
| Prontidão operacional | ~72% |
| Arquitetura backend | Boa |
| Testes backend | Fortes |
| Frontend / E2E / mobile QA | Insuficientes |
| Infraestrutura / Disaster Recovery | Principal risco operacional |

Stack produtiva: Laravel 11, PHP 8.3, React 19 + TypeScript, Inertia, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 85% | Backend sólido. H0.1a e H0.1b concluídas e validadas em produção. Faltam DR, observabilidade e modularização de rotas. |
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
    20260821144101-44bef4288ec9/
  shared/
    .env
    storage/
  current -> releases/20260821144101-44bef4288ec9
  previous -> legacy/pre-releases-20260821144130-455579af1e1f
  legacy/
  legacy-persistence/
```

Release ativa validada:

- commit: `44bef4288ec99d8dd57ad65958c77ad5cf893648`;
- metadata: `layout=atomic-v1`;
- `/var/www/clubmanager` é symlink;
- `current` aponta para a release do commit promovido;
- `previous` aponta para o layout produtivo anterior e constitui rollback target válido;
- `.env` e `storage` estão em estado partilhado;
- `public/build/manifest.json` existe na release ativa;
- aplicação não ficou em maintenance mode;
- Nginx → TLS → PHP-FPM → Laravel `/up` devolve HTTP 200;
- auditoria Access Control pós-cutover devolve `critical_count=0`.

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

### H0.2 — Disaster Recovery — próximo bloco

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
| 1 | H0.2 | Backup externo cifrado, retenção, checksum, restore test, alertas e RPO/RTO. |
| 2 | H1 | QA transversal + dependency remediation. |
| 3 | H2 | Família/EE, stock variantes, legacy e rotas modulares. |
| 4 | H3 | Fecho Desportivo ponta a ponta. |
| 5 | H4 | Decisão e fecho Fiscal. |
| 6 | H5 | Loja + Logística lifecycle completo. |
| 7 | H6 | Comunicação assíncrona e futura integração Redes. |
| 8 | H7 | Portal/PWA/mobile. |
| 9 | H8 | Reporting consolidado. |
| 10 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo: H0.2 — Disaster Recovery. Não iniciar uma feature funcional grande antes de existir backup off-site, política de retenção, restore test e RPO/RTO documentados.

---

## 7. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
| 2026-08-21 | Infraestrutura / Deploy | H0.1b fechada: releases atómicas, shared state, `current/previous`, healthchecks pre/pós-cutover e rollback. A primeira tentativa abortou antes do cutover por incompatibilidade do router PHP isolado; H0.1b.1 adicionou front controller de compatibilidade + smoke test e o segundo cutover concluiu. | PRs #186/#188, release `20260821144101-44bef4288ec9`, commit `44bef4288ec99d8dd57ad65958c77ad5cf893648`, diagnóstico pós-cutover read-only | Produção em `atomic-v1`, `/up=200`, rollback target preservado, Access Control pós-cutover com `critical_count=0`. H0.1b concluída. |
| 2026-08-21 | Access Control / Rotas | H0.1a.2 eliminou os 92 gaps críticos de `module.access`. | `routes/web.php`, `routes/desportivo_member_contract.php`, `routes/member_documents.php`, `tests/Feature/AccessControl/AccessControlReadinessAuditCommandTest.php`, diagnóstico produtivo | Produção validada antes e depois do cutover com `critical_count=0`; 83 warnings granulares permanecem. |
| 2026-08-21 | Infraestrutura / CI/CD | H0.1a endureceu secrets, SSH pinned, dependency audits e gates críticos; H0.1a.1 passou a preservar a auditoria de Access Control antes do gate. | `.github/workflows/ci.yml`, `bin/deploy-vm.mjs`, PRs #181 e #182 | Concluído em produção. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`
