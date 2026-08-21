# Estado Vivo de Desenvolvimento — ClubOS

> Fonte de verdade funcional e técnica do projeto ClubOS.
>
> Estado consolidado em 2026-08-20.
>
> O histórico detalhado anterior a esta consolidação foi preservado integralmente em `docs/history/ESTADO_VIVO_DESENVOLVIMENTO_ATE_2026-08-20.md`.

---

## 1. Objetivo e regra de utilização

Este documento contém apenas o estado atual, riscos vivos, prioridades e alterações recentes com impacto técnico ou funcional.

Antes de qualquer desenvolvimento, bugfix, refatoração, auditoria ou alteração de infraestrutura, consultar este ficheiro e validar o código real em `main`.

As percentagens refletem maturidade operacional real e não apenas existência de código.

Critérios de referência:

- 0% — inexistente;
- 10%–25% — conceito, estrutura ou placeholder;
- 30%–50% — estrutura parcial sem fluxo completo;
- 55%–70% — fluxo principal existe, mas falta validação, testes ou consolidação;
- 75%–85% — funcionalidade avançada, faltando hardening;
- 90%–100% — funcionalidade fechada, testada, validada e integrada operacionalmente.

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

### Estratégia global

Não reescrever a aplicação.

Sequência recomendada:

`consolidar → endurecer → eliminar legacy → fechar módulos incompletos → acrescentar novas funcionalidades grandes`

---

## 3. Stack técnica

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 11 |
| PHP | PHP 8.3 |
| Frontend | React 19 + TypeScript |
| Navegação | Inertia.js |
| Build | Vite |
| UI | Tailwind CSS / Radix / Headless UI |
| Base de dados produção | PostgreSQL 17 na Oracle VM |
| Cache / filas | Redis |
| CI/CD | GitHub Actions |
| Web runtime | Nginx + PHP-FPM |

---

## 4. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 82% | Backend sólido. Falta concluir hardening de deploy, modularização de rotas, observabilidade e DR. |
| Website público / construtor | 86% | Renderer, snapshots, publicação e dados dinâmicos avançados. Faltam header/footer globais, notícias completas e validação runtime multi-viewport. |
| Autenticação / Access Control | 75% | Guards e auditoria produtiva existem. O primeiro gate produtivo revelou 92 findings críticos; a classificação concreta está em análise a partir do relatório JSON preservado como artefacto. Falta matriz formal final de permissões. |
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
| Fiscal | 65% | Workflow manual/controlado existe; falta decidir provider real ou fechar formalmente modelo manual produtivo. |
| Inventário / Logística | 70% | `stock_movements` é ledger canónico; `product_variants.stock` continua por consolidar. |
| Loja | 60% | Falta lifecycle completo produto → stock → encomenda → pagamento → fiscal → cancelamento/devolução/reposição. |
| Comunicação | 60% | Falta pipeline assíncrono persistente com attempts, retry, idempotência e provider IDs. |
| Relatórios | 40% | Área menos madura; construir apenas depois de estabilizar fontes de verdade. |
| PWA / Mobile | 55% | Falta matriz QA sistemática Android/iOS/tablet/desktop, com atenção a scroll, overflow, fixed e `100vh`. |
| Importação de recibos antigos | 60% | Falta corpus real representativo e regression dataset idempotente. |

---

## 5. Principais riscos técnicos vivos

### H0 — Produção / Disaster Recovery

Produção, PostgreSQL e backups locais continuam no mesmo domínio de falha da Oracle VM.

Pendências críticas:

- backup off-site cifrado;
- retenção 7 diários / 4 semanais / 6–12 mensais;
- checksum;
- restore test periódico;
- RPO/RTO formalizados;
- alerta de backup falhado ou demasiado antigo.

### Deploy

H0.1a removeu defaults produtivos dos scripts, introduziu host key SSH pinned e adicionou gates de segurança no CI.

Permanece aberto H0.1b:

- deploy ainda trabalha diretamente no working tree produtivo;
- migrations e backend podem ser aplicados antes de uma falha posterior;
- falta arquitetura `releases/`, `shared/`, `current -> release`;
- falta rollback automático para release anterior quando tecnicamente seguro.

### Segurança / CI

H0.1a passa a incluir:

- `composer audit --locked --no-interaction --format=json`, com todos os advisories reportados no summary e gate de CI apenas para severidade `critical` nesta fase;
- `npm audit --audit-level=critical`;
- validação obrigatória das variáveis produtivas;
- `ORACLE_VM_KNOWN_HOSTS` como fonte pinned de identidade SSH;
- `StrictHostKeyChecking=yes`;
- Access Control `critical_count > 0` como falha de deployment.

A primeira execução do novo `composer audit` encontrou 34 advisories em 12 packages e falhou por comportamento default do Composer, que devolve exit code não-zero perante qualquer advisory. Como existem advisories `high` e `medium` em dependências da linha Laravel 11/Symfony/Guzzle/CommonMark que não podem ser todos eliminados sem trabalho de atualização dedicado, H0.1a passa a usar baseline explícito: tudo é visível e contabilizado, mas apenas `critical` bloqueia a CI. A remediação de `high`/`medium` deve ser tratada numa sprint própria de dependências, com análise de compatibilidade e sem upgrade major implícito.

No primeiro deployment de `main` após H0.1a, a configuração SSH pinned foi validada e o deploy técnico concluiu, mas a auditoria produtiva de Access Control devolveu `critical_count=92`. O workflow original aplicava o gate no mesmo passo que recolhia o relatório, fazendo com que o `upload-artifact` seguinte fosse skipped e eliminando a evidência necessária à análise. A correção H0.1a.1 separa agora `collect → upload(always) → gate`, preservando sempre o JSON de auditoria antes de reprovar o job.

Pelo contrato atual de `access:audit-readiness`, findings críticos só podem resultar de: schema de Access Control incompleto; conta com acesso explícito sem `UserType` resolvido; ou rota administrativa mutável sem o `module.access` correspondente. A distribuição real dos 92 findings deve ser determinada exclusivamente pelo relatório produtivo antes de alterar severidades ou permissões.

Pendências para H1:

- remediação planeada dos advisories Composer high/medium existentes;
- PHPStan / Larastan;
- TypeScript typecheck explícito;
- lint;
- testes frontend;
- E2E/browser;
- accessibility básica;
- CodeQL/SAST;
- dependency review.

### Dados / legacy

Principais consolidações em aberto:

- Família/EE para uma fonte relacional canónica;
- `product_variants.stock` para ledger canónico;
- estruturas legacy do Desportivo;
- compatibilidades antigas de Eventos;
- modularização de `routes/web.php` sem alterar URLs.

---

## 6. Prioridades recomendadas

| Ordem | Sprint | Objetivo |
|---:|---|---|
| 1 | H0.1a | Hardening imediato CI/CD: secrets obrigatórios, SSH host pinning, dependency audits e gates críticos. |
| 2 | H0.1a.1 | Preservar artefacto de Access Control antes do gate e classificar os 92 findings críticos produtivos. |
| 3 | H0.1b | Releases atómicas, `current` symlink, shared state, healthcheck e rollback. |
| 4 | H0.2 | Disaster Recovery: backup externo cifrado, retenção, checksum, restore test, alertas e RPO/RTO. |
| 5 | H1 | QA transversal e dependency remediation: typecheck, lint, Larastan/PHPStan, frontend tests, E2E, accessibility, mobile matrix e advisories não críticos. |
| 6 | H2 | Consolidação estrutural: Família/EE, stock variantes, legacy e rotas modulares. |
| 7 | H3 | Fecho Desportivo ponta a ponta. |
| 8 | H4 | Decisão e fecho Fiscal. |
| 9 | H5 | Loja + Logística lifecycle completo. |
| 10 | H6 | Comunicação assíncrona e futura integração Redes. |
| 11 | H7 | Portal/PWA/mobile. |
| 12 | H8 | Reporting consolidado. |
| 13 | H9 | Website: header/footer, notícias e polish final. |

### Próximo bloco após H0.1a

Antes de H0.1b, fechar H0.1a.1: obter e classificar o relatório produtivo de Access Control, corrigindo findings reais ou a semântica da auditoria com base em evidência concreta. Depois H0.1b deve continuar isolado numa alteração própria porque mexe no modelo de deployment e layout produtivo.

---

## 7. Histórico vivo de atualizações

O histórico detalhado até à consolidação de 2026-08-20 está preservado em:

`docs/history/ESTADO_VIVO_DESENVOLVIMENTO_ATE_2026-08-20.md`

| Data | Módulo | Desenvolvimento / análise | Evidência | Percentagem antes | Percentagem depois | Pendências |
|---|---|---|---|---:|---:|---|
| 2026-08-21 | Infraestrutura / Access Control | H0.1a.1: o primeiro deploy com hardening concluiu configuração SSH e deploy técnico, mas o gate Access Control encontrou 92 críticos. Corrigido o workflow para separar recolha, upload incondicional do relatório e gate posterior, garantindo que o JSON produtivo fica disponível mesmo quando existem críticos. A classificação dos 92 findings fica pendente do artefacto; não alterar severidades nem permissões sem essa evidência. | `.github/workflows/ci.yml`, `app/Console/Commands/AccessControl/AccessControlReadinessAuditCommand.php`, `docs/ESTADO_VIVO_DESENVOLVIMENTO.md` | Base técnica 82% | Base técnica 82% | Executar CI/deploy com o workflow corrigido, descarregar o artefacto `access-control-production-readiness-*`, agrupar findings por `code` e corrigir causas reais antes de fechar H0.1a.1. |
| 2026-08-20 | Infraestrutura / CI/CD | H0.1a: removidos IP/user/path produtivos hardcoded; parâmetros de deploy passam a ser obrigatórios; identidade SSH passa a usar `ORACLE_VM_KNOWN_HOSTS` pinned com `StrictHostKeyChecking=yes`; adicionados dependency audits; auditoria de Access Control passa a reprovar o deployment quando `critical_count > 0`; scripts de deploy passam a validar parâmetros explicitamente. A primeira CI revelou 34 advisories Composer em 12 packages; o gate foi corrigido para reportar todos e bloquear apenas severidade `critical`, mantendo high/medium como dívida explícita para remediação dedicada. Documento vivo consolidado, preservando integralmente o histórico anterior em arquivo. | `.github/workflows/ci.yml`, `bin/deploy-vm.mjs`, `bin/remote-deploy-backend.sh`, `docs/ESTADO_VIVO_DESENVOLVIMENTO.md`, `docs/history/ESTADO_VIVO_DESENVOLVIMENTO_ATE_2026-08-20.md`, PR #181 | Base técnica 80% | Base técnica 82% | Configurar/confirmar `ORACLE_VM_USER`, `ORACLE_VM_HOST`, `ORACLE_VM_APP_DIR`, `ORACLE_VM_SSH_KEY` e `ORACLE_VM_KNOWN_HOSTS` no GitHub antes do primeiro deploy de `main`; remediar advisories high/medium numa sprint de dependências; concluir H0.1b com releases atómicas e rollback; depois H0.2 DR. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`
