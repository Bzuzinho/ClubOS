# Estado Vivo de Desenvolvimento — ClubOS

> Fonte de verdade funcional e técnica do projeto ClubOS.
>
> Estado consolidado em 2026-08-24.
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
| Prontidão operacional | ~79% |
| Arquitetura backend | Boa |
| Testes backend | Fortes |
| Frontend / E2E / mobile QA | Insuficientes |
| Infraestrutura / Disaster Recovery | H0.1 e H0.2 concluídos operacionalmente em produção |

Stack produtiva: Laravel 11, PHP 8.3, React 19 + TypeScript, Inertia, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 89% | H0.1a/H0.1b/H0.2 concluídos em produção. H1.1 concluiu dependency remediation compatível e QA ratchets; H1.4–H1.12 reduziram progressivamente a dívida TypeScript para 16 erros/1 ficheiro. Permanecem o lote final isolado de Banco, Laravel major, migração de `xlsx` e hardenings residuais. |
| Website público / construtor | 86% | Renderer, snapshots, publicação e dados dinâmicos avançados. Faltam header/footer globais, notícias completas e validação runtime multi-viewport. |
| Autenticação / Access Control | 78% | Auditoria e gates produtivos ativos. Zero findings críticos e zero rotas mutáveis sem `module.access`. Permanecem 83 warnings de capability granular. |
| Dashboard / entrada por perfil | 70% | Funcional, com leituras canónicas financeiras. Falta QA final por perfil e viewport. |
| Portal atleta / família | 63% | Estrutura funcional. Falta fecho mobile/PWA, UX e validação sistemática. |
| Membros / Pessoas | 85% | Normalização avançada. Família/EE ainda mantém múltiplas representações históricas a consolidar. |
| Família / EE / educandos | 70% | Gestão funcional existe; falta fonte relacional única e cutover progressivo do legacy. |
| Desportivo global | 70% | Principal frente funcional por fechar: Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup. |
| Planeamento desportivo | 65% | Base sólida; falta fechar UX, integrações e reporting. |
| Treinos / presenças / Cais | 70% | Núcleo funcional forte; falta consolidar fluxo ponta a ponta e QA operacional. |
| Competições / resultados | 63% | Estrutura funcional; falta integração final, reporting e remoção legacy. |
| Eventos | 75% | Lifecycle, recorrência, convocatórias e integrações corrigidos. Falta remover estruturas antigas e criar contract tests Eventos ↔ Desportivo. |
| Financeiro geral | 89% | Maduro; CRUDs legacy de transações/categorias aposentados e antigo `Financeiro/Edit` converge para o fluxo canónico. Prioridade: preservar invariantes e evitar novas fontes de verdade. |
| Fiscal | 65% | Workflow manual/controlado existe; falta decidir provider real ou formalizar definitivamente o modelo manual produtivo. |
| Inventário / Logística | 70% | `stock_movements` é ledger canónico; `product_variants.stock` continua por consolidar. |
| Loja | 60% | Falta lifecycle completo produto → stock → encomenda → pagamento → fiscal → cancelamento/devolução/reposição. |
| Comunicação | 60% | Falta pipeline assíncrono persistente com attempts, retry, idempotência e provider IDs. |
| Relatórios | 40% | Área menos madura; construir apenas depois de estabilizar fontes de verdade. |
| PWA / Mobile | 55% | H1.4–H1.12 reduziram a dívida TypeScript de 132 erros/55 ficheiros para 16 erros/1 ficheiro e apertaram sucessivamente o ratchet anti-regressão. Continuam em falta lint, unit/component tests, E2E, acessibilidade e matriz Android/iOS/tablet/desktop. |
| Importação de recibos antigos | 60% | Falta corpus real representativo e regression dataset idempotente. |

---

## 4. H0 — Production Hardening

### H0.1a — CI/CD e segurança SSH — concluída

Implementado e validado em produção:

- `ORACLE_VM_USER`, `ORACLE_VM_HOST`, `ORACLE_VM_APP_DIR`, `ORACLE_VM_SSH_KEY` e `ORACLE_VM_KNOWN_HOSTS` obrigatórios;
- sem defaults produtivos no deploy canónico;
- `StrictHostKeyChecking=yes` e host key pinned;
- `composer audit` reporta advisories e bloqueia severidade `critical`;
- `npm audit --audit-level=critical`;
- auditorias produtivas preservadas como artefactos antes dos gates;
- findings críticos de Access Control bloqueiam o deployment.

Baseline Composer registado durante H0.1: 34 advisories em 12 packages, sem `critical`. H1.1 demonstrou remediação compatível para 3 advisories residuais, todos em `laravel/framework` 11; a eliminação total requer upgrade major planeado.

### H0.1a.2 — Access Control — concluída

A primeira auditoria produtiva revelou 92 findings críticos, todos `mutating_admin_route_without_module_guard`:

- Configurações: 57;
- Financeiro: 33;
- Desportivo: 1;
- Membros: 1.

Após correção e nova auditoria produtiva:

- `critical_count=0`;
- `mutating_routes_without_module_guard_count=0`;
- `unresolved_user_type_count=0`;
- schema pronto;
- 83 warnings não críticos: 57 Configurações, 25 Financeiro, 1 Desportivo.

A auditoria foi repetida depois do cutover atómico H0.1b e manteve zero críticos.

### H0.1b — Releases atómicas — concluída e validada em produção

Implementação integrada através das PRs #186 e #188.

Layout produtivo:

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

Garantias validadas:

- release construída fora do path servido;
- SHA promovido tem de coincidir com `origin/main`;
- `public/build` produzido no runner;
- `.env` e `storage` partilhados;
- migrations têm `migrate --pretend` antes de `migrate --force`;
- migrations produtivas devem seguir expand/contract;
- cutover atómico por symlink;
- PHP-FPM recarregado após cutover;
- rollback automático e manual para `previous`;
- retenção padrão de 5 releases;
- deploy direto antigo desativado;
- Nginx → TLS → PHP-FPM → Laravel `/up` validado com HTTP 200.

A primeira tentativa de cutover abortou de forma segura antes da troca do path por incompatibilidade do healthcheck PHP isolado. O hotfix H0.1b.1 adicionou o front controller de compatibilidade e regression smoke test; a tentativa seguinte concluiu o cutover.

### H0.2 — Disaster Recovery — concluída e validada em produção

Objetivos operacionais:

- RPO inicial: <=24 h;
- RTO inicial: <=2 h;
- backup local PostgreSQL 17 diário;
- off-site cifrado em Cloudflare R2;
- retenção lógica 7 diários / 4 semanais / 12 mensais;
- restore test off-site semanal;
- health monitor diário.

Diagnóstico e hardening local:

- 7 dumps locais consecutivos confirmados;
- dump PG17 validado por SHA256 e `pg_restore --list` PostgreSQL 17;
- `pg_restore` 14 do sistema deixou de ser usado para os dumps PG17;
- restore local real em BD temporária: 208 tabelas, 214 migrations, ~8 s;
- `storage` partilhado medido inicialmente em ~30,5 MB;
- ~35 GB livres na VM no levantamento inicial.

Implementação integrada através da PR #191 e hotfixes H0.2.1/H0.2.2/H0.2.3:

- arquivo off-site inclui dump, checksum, `.env`, `storage/app/public` e manifesto;
- cifragem client-side GPG AES-256 antes do upload;
- transporte S3-compatible via `rclone`;
- upload relido e comparado por SHA256 antes de ser marcado como sucesso;
- restore test sempre numa BD PostgreSQL 17 temporária, nunca sobre produção;
- health thresholds: 26 h local, 30 h off-site e 8 dias para restore test;
- monitor diário GitHub Actions;
- runbook em `docs/DR_RUNBOOK.md`;
- ativação produtiva automatizada em `.github/workflows/dr-r2-activate.yml` e documentada em `docs/DR_R2_ACTIVATION.md`.

Validação E2E antes do provider real:

- remote efémero local;
- cifragem → upload → verificação → download → decifragem → restore → health estrito;
- 208 tabelas, 214 migrations, 14 s;
- `local_offsite_e2e=ok`.

A validação E2E revelou e permitiu corrigir três regressões antes da ativação real:

1. variável `dump` usada na mesma declaração `local` sob `set -u`;
2. variável `tier` usada na mesma declaração `local` na retenção;
3. workspace de restore `0700 root` impedia o utilizador `postgres` de atravessar o diretório.

Os hotfixes adicionaram regression tests e guard rails Bash.

#### Ativação Cloudflare R2 real — validada em 2026-08-22

Release da aplicação exercitada:

`547dd6aaacfa69d188258200c8811974d06bdf6e`

A primeira tentativa real chegou ao R2 mas falhou no upload com `403 AccessDenied`. Um probe isolado confirmou:

```text
list_access=ok
write_access=failed
```

Depois de corrigida a permissão do token, o probe confirmou:

```text
list_access=ok
write_access=ok
delete_access=ok
```

Primeiro backup real validado no R2:

```text
archive=clubos-prod-20260822T001929Z.tar.gz.gpg
objects=daily/2026/08/22/clubos-prod-20260822T001929Z.tar.gz.gpg
encrypted_bytes=2011560
```

Restore real a partir desse objeto R2:

```text
restore_seconds=5
public_table_count=208
migration_count=214
storage_file_count=5
```

Health produtivo final:

```text
dr_enabled=yes
local_integrity=ok
offsite_status=ok
restore_test_status=ok
r2_real_validation=ok
```

A aplicação manteve `/up=200` durante a validação.

H0.2 fica **concluída operacionalmente em produção**: os backups persistentes já não dependem exclusivamente do mesmo domínio de falha físico da Oracle VM e existe prova de restauro a partir do provider externo.

Hardening residual, a tratar em H1 sem reabrir H0.2:

- substituir o token atual `Admin Read & Write / All buckets` por `Object Read & Write` limitado exclusivamente ao bucket de backup e repetir o probe `list/write/delete`;
- confirmar documentalmente as regras de Bucket Lock por prefixo `daily`, `weekly` e `monthly`.

---

## 5. H1 — QA transversal e dependency remediation

### H1.1 — Remediação compatível + ratchets — validada na CI #720 / PR #205

Baseline medido na PR de diagnóstico #203:

- Composer: 34 advisories — 0 critical, 8 high, 24 medium, 1 low e 1 sem severity normalizada;
- npm: 12 vulnerabilidades — 0 critical, 9 high, 2 moderate e 1 low;
- TypeScript: 132 erros em 55 ficheiros com `tsc --noEmit`.

A simulação #204 executou updates patch/minor compatíveis, build e PHPUnit antes de alterar os lockfiles:

- Composer: 34 → 3 advisories, todos em `laravel/framework` 11;
- npm: 12 → 1 vulnerabilidade, apenas `xlsx` high e `fixAvailable=false`;
- TypeScript: 132 erros / 55 ficheiros, sem regressão;
- Vite build e PHPUnit: verdes.

H1.1 introduz ratchets permanentes:

- Composer aceita temporariamente apenas o residual Laravel 11, limitado a 3 advisories, 0 critical e no máximo 1 high;
- npm aceita temporariamente apenas `xlsx`, 1 high, sem moderate/low/critical e apenas enquanto `fixAvailable=false`;
- TypeScript começou com teto de 132 erros / 55 ficheiros; qualquer melhoria tem de baixar o baseline no mesmo PR. H1.4 reduziu-o para 123/51, H1.5 para 101/51, H1.6 para 88/44, H1.7 para 66/27, H1.8 para 53/21, H1.9 para 36/10, H1.10 para 25/4, H1.11 para 22/2 e H1.12 para o teto atual de 16 erros / 1 ficheiro;
- contrato detalhado em `docs/qa/H1_BASELINE.md`.

### H1.4 — Primeiro paydown TypeScript — validada na CI #730 / PR #208

Primeiro lote de redução mensurável da dívida TypeScript:

- removidos quatro componentes UI sem consumidores runtime: `ErrorBoundary.tsx`, `ui/chart.tsx`, `ui/input-otp.tsx` e `ui/command.tsx`;
- dois componentes removidos ainda referiam `cmdk` e `input-otp`, dependências já ausentes do projeto;
- `tsc --noEmit` passou de 132 erros / 55 ficheiros para 123 erros / 51 ficheiros;
- `qa/baselines/typescript.json` foi reduzido para `123 / 51`, impedindo regressão acima do novo patamar;
- CI #730 totalmente verde, incluindo dependency ratchets, build, suite Laravel, guard rail legacy e PostgreSQL concurrency;
- sem migrations, sem alterações de dados e sem alteração funcional de negócio.

### H1.5 — Paydown TypeScript de Comunicação — validada na CI #738 / PR #212

Segundo lote mensurável de redução da dívida TypeScript, focado no contrato de canais já consumido pelo módulo Comunicação:

- declarado globalmente `Channel = 'email' | 'sms' | 'push' | 'interno' | 'alert_app'` em `resources/js/types/global.d.ts`;
- removida a maior concentração atual de diagnósticos TS2304 sem alterar runtime, fluxos de negócio, dados, migrations ou dependências;
- `tsc --noEmit` passou de 123 erros / 51 ficheiros para 101 erros / 51 ficheiros, redução de 22 erros;
- `qa/baselines/typescript.json` foi reduzido para `101 / 51`, tornando a melhoria irreversível pelo ratchet sem revisão explícita;
- `docs/qa/H1_BASELINE.md` atualizado com a evolução do baseline;
- CI #738 totalmente verde sobre o head com o novo teto.

### H1.6 — Dead code TypeScript guiado por concentração — validada na CI #756 / PR #213

Terceiro lote mensurável, escolhido após medir os 101 diagnósticos restantes por ficheiro e código:

- `scripts/qa/typecheck-ratchet.mjs` passou a mostrar no log do CI os ficheiros e códigos TypeScript com maior concentração, mantendo o mesmo comportamento de gate;
- removido `resources/js/components/sports/tabs/index.ts`, barrel legacy sem consumidores que ainda exportava sete tabs já fisicamente removidas;
- removidos seis componentes UI sem consumidores runtime: `carousel.tsx`, `drawer.tsx`, `form.tsx`, `resizable.tsx`, `sonner.tsx` e `sidebar.tsx`;
- estes componentes mantinham referências a dependências ou hooks já ausentes (`embla-carousel-react`, `vaul`, `react-hook-form`, `react-resizable-panels`, `next-themes`, `@/hooks/use-mobile`), pelo que reinstalar packages apenas para os manter seria reintroduzir dívida;
- `tsc --noEmit` passou de 101 erros / 51 ficheiros para 88 erros / 44 ficheiros;
- `qa/baselines/typescript.json` foi reduzido para `88 / 44` no mesmo lote;
- sem alteração de runtime funcional, migrations ou dados; build e PostgreSQL concurrency foram validados durante a medição, ficando a CI final limpa da PR como gate antes do merge.

### H1.7 — Normalização do contrato Inertia PageProps — validada na CI #760 / PR #214

Quarto lote mensurável, dirigido ao maior padrão transversal remanescente (`TS2344`):

- o helper `PageProps<T>` deixou de exigir que todas as interfaces de página declarem uma index signature artificial, mantendo `Record<string, unknown>` apenas no contrato efetivo entregue ao `usePage`;
- seis `usePage` locais com interfaces próprias passaram a declarar explicitamente a compatibilidade com o contrato Inertia sem alterar props, dados ou comportamento runtime;
- foram eliminados os 22 diagnósticos `TS2344` medidos na H1.6;
- `tsc --noEmit` passou de 88 erros / 44 ficheiros para 66 erros / 27 ficheiros;
- `qa/baselines/typescript.json` foi reduzido para `66 / 27` no mesmo lote;
- CI #760 verde no código: Composer/npm ratchets, TypeScript 66/27, build Vite, 1764 testes / 9860 assertions, legacy members guard e PostgreSQL concurrency.

### H1.8 — Normalização de reload Inertia e compatibilidade ES2020 — validada na CI #769 / PR #215

Quinto lote mensurável, focado em padrões transversais de baixo risco e sem tocar no fluxo bancário crítico:

- removidas opções redundantes `preserveState` / `preserveScroll` dos `router.reload()`, comportamento já implícito pelo Inertia;
- duas utilizações de `replaceAll('_', ' ')` foram substituídas por `split('_').join(' ')`, semanticamente equivalentes e compatíveis com o target TypeScript atual;
- corrigida apenas a inferência de tipo de `badgeVariant` em Comunicação e do estado `MemberTab` em Membros;
- `resources/js/Pages/Financeiro/BancoTab.tsx` ficou explicitamente fora do lote e mantém os mesmos 16 diagnósticos;
- `tsc --noEmit` passou de 66 erros / 27 ficheiros para 53 erros / 21 ficheiros;
- `qa/baselines/typescript.json` foi reduzido para `53 / 21` no mesmo lote;
- CI #769 integral verde: Composer/npm ratchets, TypeScript 53/21, build Vite, PHPUnit, legacy members read guard e PostgreSQL concurrency.

### H1.9 — Alinhamento de contratos TypeScript por domínio — validada na CI #790 / PR #216

Sexto lote mensurável, focado em contratos locais e partilhados de baixo risco, sem alterar regras de negócio ou o fluxo bancário crítico:

- alinhados tipos opcionais e payloads já existentes em Eventos, Desportivo, Loja, Marketing, Perfil e Portal;
- normalizado `tempo_oficial` para string no adapter de resultados de competição, preservando o contrato consumido pelo frontend;
- explicitadas propriedades já consumidas (`attendanceRate`, `epoca_id`, `ordem`) e estados literais sem alterar runtime;
- `resources/js/Pages/Financeiro/BancoTab.tsx` permaneceu totalmente fora do lote e mantém 16 diagnósticos;
- `tsc --noEmit` passou de 53 erros / 21 ficheiros para 36 erros / 10 ficheiros;
- o código foi validado na CI #790 com build Vite, 1764 testes / 9860 assertions, legacy members read guard e PostgreSQL concurrency verdes;
- `qa/baselines/typescript.json` é apertado para `36 / 10` no fecho da PR para tornar a melhoria irreversível pelo ratchet.

### H1.10 — Fecho dos residuais TypeScript de baixo risco — PR #217

Sétimo lote mensurável, limitado a correções de contrato/tipagem sem tocar nos fluxos financeiros críticos de Banco e Faturas:

- `Configuracoes/Index.tsx` deixou de enviar props obsoletas ao workspace Desportivo já desacoplado, declarou `cor` já consumida no contrato de configuração e removeu uma chamada `defaults` não suportada pelo `InertiaFormProps` instalado;
- o calendário de treinos passou a aceitar o contrato mínimo que realmente consome, sem exigir um `Training` completo;
- dois imports diretos para o módulo inexistente `@/lib/types` passaram a usar o contrato partilhado `@/types`;
- o fallback de observações médicas passou a preservar apenas strings em caso de parse inválido;
- o fallback de carregamento dos gráficos financeiros usa `globalThis.setTimeout/clearTimeout`, evitando apenas o estreitamento incorreto de `window` para `never`;
- `tsc --noEmit` passou de 36 erros / 10 ficheiros para 25 erros / 4 ficheiros;
- permanecem 16 erros em `Financeiro/BancoTab.tsx`, 6 em `Financeiro/FaturasTab.tsx`, 1 em `Financeiro/request.ts` e 2 em `Portal/Communications.tsx`;
- os dois erros do Portal são funções realmente ausentes (`setSelectedCategory` e `openItem`) e ficam para validação funcional, não para uma correção cega de tipagem;
- sem migrations, sem alterações de dados e sem alteração intencional das regras de negócio.

### H1.11 — Portal communications + request transport — PR #218

Oitavo lote mensurável, fechando os últimos residuais fora dos dois grandes fluxos financeiros:

- `Portal/Communications.tsx` ganhou estado real de categoria, lista de comunicações recentes filtrável e abertura funcional de cada item;
- ao abrir um item não lido, o Portal usa o endpoint canónico `portal.communications.read` e só depois segue para o link associado ou para o detalhe da caixa de entrada;
- `Financeiro/request.ts` passou a construir o `BodyInit` com o type guard de `FormData` aplicado diretamente, preservando exatamente o transporte existente;
- diagnóstico completo `tsc --noEmit` passou de 25 erros / 4 ficheiros para 22 erros / 2 ficheiros;
- permanecem exclusivamente 16 erros em `Financeiro/BancoTab.tsx` e 6 em `Financeiro/FaturasTab.tsx`;
- sem migrations, sem alterações de dados e sem alteração das regras financeiras.

### H1.12 — Fecho TypeScript de Faturas — PR #219

Nono lote mensurável, focado exclusivamente no fluxo financeiro de Faturas e mantendo Banco fora do lote:

- três chamadas locais ao helper antigo `getAxiosJsonConfig` passaram a usar o helper financeiro canónico `getFinanceiroAxiosJsonConfig`, já importado e utilizado pelo módulo;
- os dois payloads de persistência manual usam `formData.user_id` depois do guard explícito que impede gravação sem utilizador;
- ao editar uma fatura histórica sem titular, o formulário normaliza `user_id` nulo para vazio e mantém o mesmo bloqueio antes de qualquer persistência;
- `tsc --noEmit` passou de 22 erros / 2 ficheiros para 16 erros / 1 ficheiro;
- `Financeiro/FaturasTab.tsx` ficou sem diagnósticos; permanecem apenas os 16 de `Financeiro/BancoTab.tsx`, reservado ao lote financeiro final;
- CI #817 validou o código com build Vite, 1764 testes / 9860 assertions, legacy-read guard e PostgreSQL concurrency verdes;
- sem migrations, sem alterações de dados, sem novos endpoints e sem alteração das regras financeiras.

Pendências H1 separadas:

1. Laravel 12+ para remover os 3 advisories residuais;
2. migração de `xlsx` preservando o importador de membros `.xlsx/.xls/.csv`;
3. fechar os 16 erros TypeScript restantes em `Financeiro/BancoTab.tsx` num lote financeiro final e isolado;
4. R2 least privilege e confirmação Bucket Lock;
5. lint, unit/component tests, E2E, accessibility e matriz mobile/desktop.

---

## 6. Dívida estrutural prioritária

- Família/EE: convergir `user_guardian`, `familias/familia_user`, `user_relationships` e compatibilidades para uma fonte canónica.
- Inventário: integrar `product_variants.stock` no ledger canónico ou transformar variantes/SKU em entidade física de inventário.
- Desportivo: fechar fluxo Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup.
- Eventos: remover estruturas de compatibilidade sem consumo e criar contract tests com Desportivo.
- Rotas: modularizar `routes/web.php` sem alterar URLs.
- Fiscal: implementar provider real ou formalizar definitivamente o workflow manual como modelo produtivo.
- Frontend QA: typecheck, lint, unit tests, E2E, accessibility e matriz mobile/desktop.
- Access Control: resolver os 83 warnings de capability granular sem reabrir bypasses de módulo.

---

## 7. Prioridades recomendadas

| Ordem | Sprint | Objetivo |
|---:|---|---|
| 1 | H1 | H1.1, H1.4–H1.12 concluídos/validados por lotes; fechar o residual TypeScript final de `BancoTab.tsx` em isolamento e avançar com Laravel major, migração de `xlsx`, hardening residual R2 e QA frontend. |
| 2 | H2 | Família/EE, stock variantes, legacy e rotas modulares. |
| 3 | H3 | Fecho Desportivo ponta a ponta. |
| 4 | H4 | Decisão e fecho Fiscal. |
| 5 | H5 | Loja + Logística lifecycle completo. |
| 6 | H6 | Comunicação assíncrona e futura integração Redes. |
| 7 | H7 | Portal/PWA/mobile. |
| 8 | H8 | Reporting consolidado. |
| 9 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo: continuar H1 a partir do teto 16/1. Tratar `Financeiro/BancoTab.tsx` (16 erros) num lote financeiro final e isolado, preservando explicitamente as invariantes de reconciliação, alocação e pagamento. Em paralelo, preparar Laravel major e a migração de `xlsx` sem degradar o runtime atual.

---

## 8. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
| 2026-08-24 | QA / TypeScript / Faturas | H1.12 fechou os 6 diagnósticos de `FaturasTab.tsx`, substituindo helpers residuais e alinhando `user_id` com o guard já existente, reduzindo a dívida de 22 erros/2 ficheiros para 16 erros/1 ficheiro sem tocar em Banco. | PR #219; CI #817; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 16/1. Resta apenas `BancoTab.tsx` com 16 diagnósticos para lote financeiro isolado. |
| 2026-08-24 | QA / TypeScript / Portal + transporte financeiro | H1.11 completou o filtro e abertura de comunicações no Portal e corrigiu o narrowing do body em `Financeiro/request.ts`, reduzindo a dívida de 25 erros/4 ficheiros para 22 erros/2 ficheiros. | PR #218; diagnóstico completo `tsc --noEmit`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 22/2. Restam apenas Banco 16 e Faturas 6. |
| 2026-08-24 | QA / TypeScript / residuais low-risk | H1.10 fechou Configurações, contratos Desportivo/Membros e o fallback de agendamento do dashboard financeiro, reduzindo a dívida de 36 erros/10 ficheiros para 25 erros/4 ficheiros sem tocar em Banco ou Faturas. | PR #217; medição `tsc --noEmit`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 25/4. Restam Banco 16, Faturas 6, Portal 2 e `Financeiro/request.ts` 1. |
| 2026-08-24 | QA / TypeScript / contratos por domínio | H1.9 alinhou contratos TypeScript de baixo risco em Eventos, Desportivo, Loja, Marketing, Perfil e Portal, reduzindo a dívida de 53 erros/21 ficheiros para 36 erros/10 ficheiros sem tocar no fluxo bancário crítico. | PR #216; CI #790; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 36/10. `Financeiro/BancoTab.tsx` mantém 16 diagnósticos e continua reservado a lote próprio. |
| 2026-08-24 | QA / TypeScript / Inertia | H1.8 normalizou `router.reload()`, compatibilidade ES2020 e inferências locais de tipo sem alteração de runtime, reduzindo a dívida TypeScript de 66 erros/27 ficheiros para 53 erros/21 ficheiros. | PR #215; CI #769; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 53/21. `Financeiro/BancoTab.tsx` permaneceu fora do lote e mantém 16 diagnósticos para tratamento isolado. |
| 2026-08-24 | QA / TypeScript / Inertia | H1.7 normalizou o contrato `PageProps`/`usePage` sem alterar runtime e eliminou todos os 22 `TS2344`, reduzindo a dívida TypeScript de 88 erros/44 ficheiros para 66 erros/27 ficheiros. | PR #214; CI #760; `resources/js/types/index.d.ts`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 66/27. H1.8 reduziu posteriormente para 53/21. |
| 2026-08-23 | QA / TypeScript / Dead code | H1.6 mediu a concentração dos diagnósticos, removeu um barrel desportivo obsoleto e seis componentes UI órfãos, reduzindo a dívida TypeScript de 101 erros/51 ficheiros para 88 erros/44 ficheiros. | PR #213; `scripts/qa/typecheck-ratchet.mjs`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 88/44. Nenhuma dependência morta foi reinstalada para preservar componentes sem consumidores. |
| 2026-08-23 | QA / TypeScript / Comunicação | H1.5 declarou o contrato global de canais já usado por Comunicação e reduziu a dívida TypeScript de 123 erros/51 ficheiros para 101 erros/51 ficheiros, apertando novamente o ratchet. | PR #212; CI #738; `resources/js/types/global.d.ts`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 101/51. H1.6 reduziu posteriormente para 88/44. |
| 2026-08-22 | QA / TypeScript | H1.4 removeu quatro componentes UI órfãos e reduziu a dívida TypeScript de 132 erros/55 ficheiros para 123 erros/51 ficheiros, apertando o ratchet para o novo valor. | PR #208; CI #730; merge `6df774c4ef9cdb89f5aea0ea0e35fde50fba9ae1`; `qa/baselines/typescript.json` | Integrado em `main`. Paydown continuado em H1.5/H1.6. |
| 2026-08-22 | QA / Dependências | H1.1 mediu baseline Composer/npm/TypeScript, provou remediação compatível e introduziu ratchets anti-regressão. | PRs diagnóstico #203/#204; PR #205; `docs/qa/H1_BASELINE.md`; `scripts/qa/*`; `qa/baselines/typescript.json` | CI #720 totalmente verde. Residual controlado: 3 advisories Laravel 11, 1 high `xlsx`; baseline TS inicial 132/55, já reduzido por H1.12 para 16/1. |
| 2026-08-22 | Infraestrutura / DR | H0.2 fechada em produção com Cloudflare R2 real: escrita/leitura/eliminação S3 validadas, primeiro backup cifrado remoto criado, restore PG17 a partir do objeto remoto e health estrito verdes. | PR #200; diagnóstico #201; release `547dd6aaacfa69d188258200c8811974d06bdf6e`; arquivo `clubos-prod-20260822T001929Z.tar.gz.gpg`; restore 208 tabelas/214 migrations/5 s; `r2_real_validation=ok` | H0.2 concluída operacionalmente. Residual H1: token de menor privilégio e confirmação Bucket Lock. |
| 2026-08-22 | Infraestrutura / DR | Automatizado o bootstrap produtivo R2 com secrets, SSH pinned, prova backup+restore antes de ativar cron/marker e health estrito. | PR #200; `.github/workflows/dr-r2-activate.yml`; `docs/DR_R2_ACTIVATION.md` | Integrado em `main` e usado com sucesso na ativação real. |
| 2026-08-21 | Infraestrutura / DR | E2E de software fechado com remote efémero; regressões Bash e permissões de restore corrigidas antes do provider real. | PRs #191/#193/#194/#196; E2E 208 tabelas, 214 migrations, 14 s | Validado e posteriormente confirmado contra R2 real. |
| 2026-08-21 | Financeiro / Legacy cleanup | Aposentados CRUDs legacy de transações/categorias e removido placeholder ativo de `Financeiro/Edit`. | PRs #179/#180 | Integrado em `main`, sem migrations nem alteração de dados. |
| 2026-08-21 | Infraestrutura / Deploy | H0.1b fechada com releases atómicas, shared state, `current/previous`, healthchecks e rollback. | PRs #186/#188 | Produção em `atomic-v1`, `/up=200`, rollback target preservado. |
| 2026-08-21 | Access Control / Rotas | Eliminados os 92 gaps críticos de `module.access`. | PR #184; auditoria produtiva | `critical_count=0`; 83 warnings granulares permanecem. |
| 2026-08-21 | Infraestrutura / CI/CD | H0.1a endureceu secrets, SSH pinned, dependency audits e gates críticos. | PRs #181/#182 | Concluído em produção. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`