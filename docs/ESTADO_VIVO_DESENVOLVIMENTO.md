# Estado Vivo de Desenvolvimento — ClubOS

> Fonte de verdade funcional e técnica do projeto ClubOS.
>
> Estado consolidado em 2026-08-29.
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
| Implementação funcional global | ~76% |
| Prontidão operacional | ~80% |
| Arquitetura backend | Boa |
| Testes backend | Fortes |
| Frontend / E2E / mobile QA | Baseline automático autenticado ativo; cobertura profunda por fluxo a expandir |
| Infraestrutura / Disaster Recovery | H0.1 e H0.2 concluídos operacionalmente em produção |

Stack produtiva: Laravel 13, PHP 8.3, React 19 + TypeScript, Inertia 2, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 94% | H0.1a/H0.1b/H0.2 concluídos em produção. H1.1 e H1.4–H1.15 fecharam Composer/npm/TypeScript em zero; H1.16 colocou em produção os guard rails de least privilege/verification do R2; H1.17 tornou lint, unit/component, multi-browser/mobile E2E e acessibilidade gates canónicos de CI; H1.18 acrescentou autenticação real e navegação core nos cinco perfis Playwright. A PR #233 tornou o Composer security gate resiliente a falhas transitórias de rede sem enfraquecer o fail-closed. Resta a ação operacional externa de rotação/locks R2 e expansão progressiva da cobertura profunda por fluxo. |
| Website público / construtor | 86% | Renderer, snapshots, publicação e dados dinâmicos avançados. Faltam header/footer globais, notícias completas e validação runtime multi-viewport. |
| Autenticação / Access Control | 78% | Auditoria e gates produtivos ativos. Zero findings críticos e zero rotas mutáveis sem `module.access`. H1.18 cobre rota protegida, intended redirect, login válido/inválido, logout e recuperação de password em Chromium/Firefox/WebKit desktop e Pixel/iPhone. Permanecem 83 warnings de capability granular e falta matriz por perfis não-admin. |
| Dashboard / entrada por perfil | 70% | Funcional, com leituras canónicas financeiras. H1.18 valida Dashboard autenticado admin, overflow e WCAG A/AA; o gate foi estabilizado para auditar o estado final após desaparecer o progress transitório Inertia/NProgress, mantendo todas as regras axe. Falta QA final por restantes perfis e operações específicas. |
| Portal atleta / família | 64% | Estrutura funcional. H2.1–H2.3d fecharam a base relacional Família/EE e removeram as estruturas legacy; falta fecho mobile/PWA, UX e validação sistemática do Portal. |
| Membros / Pessoas | 91% | Normalização avançada. Família/EE está consolidada em `user_guardian` + `familias/familia_user`; H2.3d removeu fisicamente mirrors JSON, `user_relationships`, casts, rotas e classes legacy e deixou um gate produtivo permanente de schema final. |
| Família / EE / educandos | 95% | Estruturalmente fechada: `familias/familia_user` é o agregado familiar canónico e `user_guardian` a relação explícita EE↔educando. Produção confirma as 3 estruturas canónicas presentes, zero estruturas legacy e `ready=true`. Permanecem apenas melhorias funcionais de UX/Portal, não dívida de fonte de verdade. |
| Desportivo global | 70% | Análise transversal read-only consolidada sobre Treino/Cais/Live/Avaliações/Resultados, com proveniência, splits e export CSV; endpoints legacy Performance já retirados do routing runtime na PR #227. Principal frente por fechar: fluxo ponta a ponta, Portal, reporting consolidado e legacy cleanup. |
| Planeamento desportivo | 65% | Base sólida; falta fechar UX, integrações e reporting. |
| Treinos / presenças / Cais | 70% | Núcleo funcional forte; falta consolidar fluxo ponta a ponta e QA operacional. |
| Competições / resultados | 63% | Estrutura funcional; falta integração final, reporting e remoção legacy. |
| Eventos | 75% | Lifecycle, recorrência, convocatórias e integrações corrigidos. H1.18 garante entrada pelo menu em desktop/mobile; falta remover estruturas antigas, criar contract tests Eventos ↔ Desportivo e E2E das operações críticas. |
| Financeiro geral | 89% | Maduro; CRUDs legacy de transações/categorias aposentados e antigo `Financeiro/Edit` converge para o fluxo canónico. H1.18 garante entrada pelo menu em desktop/mobile; prioridade: preservar invariantes, evitar novas fontes de verdade e acrescentar E2E financeiro crítico. |
| Fiscal | 65% | Workflow manual/controlado existe; falta decidir provider real ou formalizar definitivamente o modelo manual produtivo. |
| Inventário / Logística | 70% | `stock_movements` é o ledger canónico e H2.4b integrou o stock de variantes com atualização atómica produto+variante. Falta fechar o lifecycle transversal de Logística e ampliar QA operacional. |
| Loja | 60% | Falta lifecycle completo produto → stock → encomenda → pagamento → fiscal → cancelamento/devolução/reposição. |
| Comunicação | 60% | Falta pipeline assíncrono persistente com attempts, retry, idempotência e provider IDs. |
| Relatórios | 40% | Área menos madura; construir apenas depois de estabilizar fontes de verdade. |
| PWA / Mobile | 62% | TypeScript permanece 0/0. H1.17 introduziu Playwright bloqueante em Chromium/Firefox/WebKit e perfis Pixel 7/iPhone 14; H1.18 acrescentou sessão autenticada, menu, navegação para Membros/Desportivo/Eventos/Financeiro/Configurações, overflow e axe no Dashboard. Falta ampliar a cobertura a workspaces/tabs, tablet, Portal e operações críticas por módulo. |
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

Baseline Composer registado durante H0.1: 34 advisories em 12 packages, sem `critical`. H1.1 reduziu-o para 3 advisories residuais em `laravel/framework` 11; H1.14 concluiu o upgrade para Laravel 13 e reduziu o baseline Composer para 0 advisories.

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

- Composer passou em H1.14 para baseline estrito de 0 advisories; qualquer advisory novo falha o CI;
- npm passou em H1.15 para baseline estrito de 0 vulnerabilidades; qualquer finding novo falha o CI;
- TypeScript começou com teto de 132 erros / 55 ficheiros; qualquer melhoria tem de baixar o baseline no mesmo PR. H1.4 reduziu-o para 123/51, H1.5 para 101/51, H1.6 para 88/44, H1.7 para 66/27, H1.8 para 53/21, H1.9 para 36/10, H1.10 para 25/4, H1.11 para 22/2, H1.12 para 16/1 e H1.13 para o teto final de 0 erros / 0 ficheiros;
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

### H1.13 — Fecho TypeScript de Banco — PR #220

Décimo e último lote mensurável do paydown TypeScript H1, isolado no fluxo bancário crítico:

- `BancoTab.tsx` estreitou o helper local `buildRouteUrl` ao contrato realmente usado (`string | number`), evitando um tipo de parâmetros mais largo do que o Ziggy consumido neste fluxo;
- `getCentroCustoName` passou a aceitar explicitamente `null`, alinhando o contrato com `centro_custo_id` dos payloads financeiros;
- foi removida uma opção `preserveScroll` já não aceite pelo contrato atual de `router.reload`, sem alterar a lista de props recarregadas;
- `LancamentoFinanceiro.origem_tipo` passou a reconhecer `movement`, valor real já tratado pelo runtime e pelos fluxos canónicos de movimentos;
- o diálogo de reconciliação partilha o mesmo contrato estreito de `buildRouteUrl`;
- diagnóstico dedicado confirmou `BancoTab.tsx` com 0 erros; a CI #829 confirmou globalmente `tsc --noEmit` com 0 erros / 0 ficheiros e a CI #833 repetiu a validação já com o baseline permanente fixado em 0/0;
- CI #829 validou build Vite, 1764 testes / 9860 assertions, legacy-read guard e PostgreSQL concurrency verdes;
- sem migrations, sem alterações de dados, sem novos endpoints e sem alteração das invariantes de reconciliação, alocação ou pagamento.

Pendências H1 separadas:

1. R2 operacional externo: criar/rotacionar para `Object Read & Write` limitado ao bucket de backup, ativar/verificar Bucket Lock, repetir probe + backup + restore e revogar a credencial Admin antiga;
2. a matriz frontend base e autenticada está fechada em H1.17/H1.18; a cobertura deve agora crescer dentro dos workstreams funcionais, incluindo perfis não-admin, Portal, workspaces/tabs, tablet e operações críticas.

---

### H1.14 — Upgrade para Laravel 13 suportado — PR #222

Objetivo: eliminar a dívida de segurança residual do Laravel 11 sem alterar contratos funcionais nem introduzir mudanças silenciosas de identificadores.

Implementado:

- `laravel/framework` 11 → 13, mantendo PHP 8.3;
- `inertiajs/inertia-laravel` atualizado para a linha 2.x compatível com Laravel 13, mantendo o protocolo/client Inertia atual;
- `laravel/sanctum`, `laravel/tinker`, `laravel/breeze`, `nunomaduro/collision` e PHPUnit atualizados para versões compatíveis;
- os 186 modelos que usavam `HasUuids` passam a usar `HasVersion4Uuids` através de alias local, preservando explicitamente a geração UUIDv4 apesar da mudança de default do framework;
- 12 testes legacy `@test` migrados para atributos `#[Test]` compatíveis com PHPUnit 12;
- regression test permanente garante que novos IDs continuam UUIDv4;
- removido o `post-update-cmd` órfão de `laravel-assets`; package discovery continua validado por `post-autoload-dump` e pela instalação limpa;
- ratchet Composer apertado de 3 advisories residuais para 0 advisories.

Matriz materializada e validada:

- Laravel 13.29.0;
- Inertia Laravel 2.0.25;
- Sanctum 4.3.3;
- Tinker 3.0.2;
- Breeze 2.4.2;
- Collision 8.9.5;
- PHPUnit 12.5.33;
- Composer audit: 0 advisories;
- instalação Composer limpa, Vite build e suite Laravel executados no estado final transformado;
- sem migrations, sem alterações de dados e sem alterações de regras de negócio.

A PR #222 é o gate canónico de integração, incluindo CI transversal e PostgreSQL antes de merge/deploy.

### H1.15 — Fecho npm `xlsx` security debt — PR #223

Objetivo: remover a última vulnerabilidade npm residual sem degradar os importadores de folhas de cálculo de Membros e Financeiro.

Implementado:

- `xlsx` 0.18.5 do npm registry substituído pela release oficial SheetJS CE 0.20.3 vendorizada em `vendor/xlsx-0.20.3.tgz`;
- `package.json` referencia o artefacto local por `file:`, pelo que `npm ci` e o deploy deixam de depender do CDN;
- SHA-256 versionado: `8dc73fc3b00203e72d176e85b50938627c7b086e607c682e8d3c22c02bb99fe8`;
- contrato permanente valida os entrypoints `xlsx` e `xlsx/xlsx.mjs`, checksum e versão runtime;
- round-trips validados para XLSX, XLS, ODS e CSV e leitura HTML preservada para extratos bancários;
- npm audit passou de 1 high residual para 0 vulnerabilidades e o ratchet foi apertado para zero, sem exceções por package;
- TypeScript mantém 0/0 e o build Vite continua verde.

Sem migrations, sem alterações de dados e sem alteração das regras de importação, reconciliação ou negócio.

### H1.16 — R2 least-privilege hardening — PR #224

Preparação técnica integrada e deployada na Oracle VM:

- `scripts/ops/dr/probe-r2-access.sh` prova `list/write/read/delete` com objeto efémero e confirma a eliminação final;
- o workflow de ativação DR bloqueia backup/restore se o probe da credencial falhar;
- `scripts/ops/dr/check-r2-bucket-lock.sh` verifica o control plane Cloudflare em modo read-only, separado das credenciais S3 do data plane;
- os prefixos reais ficam explícitos: `clubos-prod/daily/`, `clubos-prod/weekly/` e `clubos-prod/monthly/`;
- a configuração documentada exige credencial `Object Read & Write` limitada ao bucket de backup e locks compatíveis com as retenções 7d/28d/370d;
- PR #224 merged em `55989937458271b0348c5cd7818c6c83acb171f1`; CI #870 verde com deploy e audits produtivos.

A parte de código está concluída. Continua pendente, fora do repositório, executar a rotação real na conta Cloudflare, configurar/verificar os Bucket Locks, repetir probe + backup + restore e revogar a credencial Admin antiga.

### H1.17 — Frontend QA matrix — PR #225

Baseline transversal implementado como gate real de CI:

- ESLint sobre `resources/js` com `no-debugger`, `no-unreachable` e `react-hooks/rules-of-hooks` bloqueantes;
- Vitest + jsdom + Testing Library + user-event + jest-dom para unit/component tests;
- baseline inicial cobre `InputError` e `Checkbox` por comportamento/interação, sem snapshots;
- Playwright executa Chromium, Firefox e WebKit em desktop, mais Pixel 7/Chromium e iPhone 14/WebKit;
- axe-core bloqueia findings `serious`/`critical` WCAG A/AA no `/login`;
- contrato E2E também impede overflow horizontal no login em toda a matriz;
- `frontend-browser-qa` é job separado e o deploy produtivo passa a depender de `validate`, browser QA e PostgreSQL concurrency;
- a introdução do lint encontrou e corrigiu um contrato real de Rules of Hooks em `useResource`, sem consumidores ativos nem alteração de endpoints/dados;
- CI técnica #875 validou Composer 0, npm 0, SheetJS contract, TypeScript 0/0, lint, Vitest, build, suite Laravel, legacy guard, browser QA e PostgreSQL concurrency.

Sem migrations, sem alterações de dados e sem alteração intencional da UI. O baseline fecha a lacuna estrutural de tooling; a cobertura funcional deve crescer por risco dentro de Dashboard, Portal, Membros, Financeiro, Desportivo, Eventos e Website.

### H1.18 — QA autenticada e navegação base — integrada via PR #228

Expansão do mesmo gate Playwright, sem pipeline paralelo:

- seeder E2E determinístico e protegido por `APP_ENV=testing`, com cinco utilizadores administrativos isolados, um por projeto Playwright;
- rota protegida, intended redirect, login válido, credenciais inválidas, logout e recuperação de password exercitados em sessão Laravel real;
- Dashboard autenticado validado para overflow horizontal e axe WCAG A/AA;
- menu autenticado exercitado para Membros, Desportivo, Eventos, Financeiro e Configurações em Chromium/Firefox/WebKit desktop e Pixel 7/iPhone 14;
- contraste do token primário/sidebar corrigido para `#0066CC` sem silenciar a regra axe;
- CI #900 validou a sincronização com a PR #227 e CI #901 repetiu todos os gates na PR não-draft de integração;
- PR #228 merged em `34ad7cb1b59c79b946584aa6fd58c908b8fd4154`.

A H1.18 fecha a lacuna de autenticação e navegação core no baseline transversal. Não equivale a QA completa dos módulos: ficam para os workstreams funcionais os perfis não-admin, Portal, workspaces/tabs, tablet e operações críticas.

### H1.19 — Resiliência dos gates Composer e accessibility — integrada em 2026-08-29

Dois problemas de CI foram resolvidos sem reduzir o rigor dos gates:

- PR #233: `composer audit` repete apenas falhas técnicas/relatórios inválidos até 3 tentativas; qualquer relatório válido com advisory continua a falhar imediatamente e uma indisponibilidade persistente continua fail-closed;
- durante H2.3a, o axe/WebKit apanhou o `#nprogress` transitório do Inertia com `role="bar"`; o E2E passou a esperar a remoção desse chrome de navegação antes de auditar o Dashboard estável, sem ignorar `aria-roles` nem qualquer regra WCAG;
- CI #924 validou a correção na PR e CI #925 repetiu-a em `main` antes do deploy.

---

## 6. H2 — Consolidação estrutural

### H2.1 — Boundary canónico de escrita Família/EE — PR #230

Concluído e deployado:

- `FamilyRelationshipService` centraliza associação/remoção de EE e gestão de membros do agregado;
- `familias/familia_user` é o agregado familiar canónico;
- `user_guardian` é a relação explícita EE↔educando;
- uma associação de guardian não inventa família quando não existe e não adivinha quando existem várias;
- quando existe exatamente uma família, a projeção para `familia_user` é feita de forma determinística;
- remover guardianship preserva membership familiar e ajusta apenas o papel quando a família é inequívoca;
- regressões cobrem cenários zero/uma/várias famílias.

PR #230 merged em `deee18a7fadb0288efafd67306471e8b8c849075`; CI #905 verde e CI #906 validou/deployou `main` na Oracle VM.

### H2.2 — Neutralização de `user_relationships` legacy — PR #231

Concluído e em produção:

- `RelacoesMembroController` deixou de ler/escrever `user_relationships` e funciona apenas como tombstone explícito `410 Gone` para clientes antigos;
- as rotas canónicas `membros.familia.*` permanecem a superfície suportada;
- `FamilyLegacyRelationshipAuditor` classifica cobertura dos registos legacy por `user_guardian` e família partilhada;
- comando read-only `members:audit-family-legacy-relationships --json --fail-on-uncovered` mede readiness sem apagar dados;
- relações sem projeção canónica e tipos desconhecidos continuam a bloquear cleanup físico.

PR #231 merged em `ef9ae22b820cdd8eec940f1a618ca01707c98897`. A primeira tentativa de `main` encontrou apenas um timeout transitório no endpoint de advisories do Packagist; a PR #233 endureceu o gate sem o enfraquecer e a CI #921 confirmou novamente deploy integral na Oracle VM.

### H2.3a — Staged cutover dos mirrors JSON Família/EE — PR #232

Concluído e deployado em `main`, mas **não autoriza ainda drop físico**:

- o `FamilyRelationshipService` deixou de sincronizar `users.encarregado_educacao` e `users.educandos`;
- os dois mirrors saíram de `User::$fillable`, impedindo reativação por mass assignment;
- os casts permanecem temporariamente para auditoria/histórico;
- `FamilyJsonMirrorAuditor` mede consumidores runtime, pares declarados, cobertura por `user_guardian`, referências inválidas, auto-referências e readiness;
- comando `members:audit-family-json-mirrors --json --fail-on-finding` é read-only;
- fixtures e testes foram alinhados com a pivot real `user_guardian` e com a ausência intencional de sincronização JSON no serviço canónico;
- o source scan identifica ainda consumidores legacy reais, sobretudo `MembrosController` e o fallback de `resources/js/Pages/Membros/Show.tsx`.

PR #232 merged em `c606daeffadfc07b000d1448293379e4d821e13a`; CI #924 totalmente verde na PR e CI #925 totalmente verde em `main`, incluindo PHP, PostgreSQL, multi-browser/mobile E2E, accessibility e deploy na Oracle VM.

O subpasso seguinte foi a H2.3b, que removeu estas leituras/escritas runtime. O audit de dados produtivos continua obrigatório antes de decidir backfill/cleanup físico; não inferir relações ausentes.

### H2.3b — Cutover runtime dos mirrors JSON Família/EE — PR #235

Concluído e deployado, ainda sem qualquer drop físico:

- `MembrosController::show()` deixou de chamar a reconciliação legacy que podia ler JSON stale e reescrever `user_guardian` ao abrir uma ficha;
- leituras e fallbacks JSON foram removidos do controller e de `Membros/Show.tsx`;
- pedidos antigos que ainda enviam `encarregado_educacao`/`educandos` são tratados como vocabulário DTO e passam por `FamilyRelationshipService`;
- `replaceGuardiansForMember()` e `replaceDependentsForGuardian()` substituem conjuntos de relações de forma canónica, idempotente e sem escrever nos mirrors;
- testes cobrem substituição, deduplicação, idempotência, preservação dos JSON e ficha com dados stale;
- `FamilyJsonMirrorAuditor` passou ao contrato H2.3b e exige zero consumidores runtime.

PR #235 merged em `c8e8a36073b45c5ed49abd7702ec8fd93db83c59`; CI #928 totalmente verde na PR e CI #929 totalmente verde em `main`, incluindo deploy para a Oracle VM.

### H2.3c — Auditorias produtivas Família/EE — concluída

O deploy de `main` passa a recolher, após a release atómica, os relatórios read-only de:

- `members:audit-family-legacy-relationships --json`;
- `members:audit-family-json-mirrors --json`.

Os artifacts são minimizados antes do upload: não incluem identificadores de linhas, utilizadores, snippets ou findings detalhados. O pipeline apenas mede e preserva evidência; não faz backfill nem apaga dados.

PR #236 merged em `1489e01d82e407926edb332b51ae9a248849067b`; CI #930 verde na PR e CI #931 verde em `main`, com deploy completo. Resultados reais de produção:

- `user_relationships`: tabela presente, `0` linhas, `0` uncovered e `0` tipos desconhecidos;
- mirrors JSON: `24` links declarados, `12` pares únicos e `12` pares cobertos por `user_guardian`;
- `0` consumidores runtime, `0` pares uncovered, `0` referências inválidas e `0` self-references;
- ambos os relatórios devolveram `ready_for_physical_cleanup=true`.

Não foi necessário backfill: toda a informação histórica relevante já estava coberta pela representação canónica.

### H2.3d — Contract físico final Família/EE

Com o gate produtivo limpo, o contract final:

- remove por migration `user_relationships`, `users.encarregado_educacao` e `users.educandos`;
- remove casts mortos, `UserRelationship`, `RelacoesMembroController`, rotas tombstone e relações Eloquent legacy;
- retira a última leitura tolerante de `user_relationships` no alerta de menores e do mapa de modelo de Pessoas;
- aposenta os dois auditors de transição e substitui-os por `members:audit-family-final-schema`;
- mantém `user_guardian`, `familias` e `familia_user` como estruturas obrigatórias;
- acrescenta testes de ausência física, rotas canónicas e gate pós-deploy sem dados de linha.

PR #237 merged em `d7701cbe05c62b994643c6c91cf859fc199787a2`; CI #933 totalmente verde na PR e CI #934 totalmente verde em `main`, incluindo PostgreSQL, browser QA e deploy para a Oracle VM. O artifact produtivo `family-final-schema-d7701cbe05c62b994643c6c91cf859fc199787a2` confirmou:

- `3/3` estruturas canónicas presentes (`user_guardian`, `familias`, `familia_user`);
- `0` estruturas legacy presentes;
- `ready=true` e auditoria read-only.

A frente estrutural Família/EE fica encerrada. Alterações futuras nesta área devem preservar estas três estruturas canónicas e tratar apenas evolução funcional/UX, sem reintroduzir mirrors ou relações paralelas.

### H2.4a — Readiness produtiva do stock por variante — concluída

Antes de alterar o ledger ou fazer backfill, `inventory:audit-variant-stock-readiness` recolhe apenas métricas agregadas e read-only sobre:

- variantes ativas, snapshots físicos/reservados e estados inválidos;
- diferença diagnóstica entre o snapshot agregado de `products` e a soma de `product_variants`;
- encomendas históricas com variante e correspondência exata, ausente, duplicada ou com quantidade divergente face às saídas `store_order_item`;
- referências produto↔variante incoerentes e presença/ausência de dimensão de variante em `stock_movements`;
- fronteiras conhecidas de escrita direta no catálogo e na baixa de venda.

O deploy arquiva `variant-stock-production-readiness-*` sem IDs de linhas/utilizadores, sem findings detalhados e sem executar backfill ou alteração de schema. Os resultados produtivos determinam se H2.4b pode estender o ledger de forma determinística ou se exige reconciliação prévia.

PR #239 merged em `6972a7aa9e859afe0764c2242b143aa83d110b84`; CI #938 totalmente verde na PR e CI #939 totalmente verde em `main`, incluindo deploy na Oracle VM. O artifact `variant-stock-production-readiness-6972a7aa9e859afe0764c2242b143aa83d110b84` confirmou em produção:

- `0` variantes, `0` snapshots não-zero/inválidos e `0` produtos com variantes;
- `0` encomendas históricas com variante e, portanto, nenhum exit por reconciliar ou atribuir;
- schema fonte integralmente presente, dimensão `product_variant_id` ainda ausente de `stock_movements` e `ready_for_design=true`;
- `2` fronteiras conhecidas de escrita direta: baixa na venda e edição manual no catálogo.

Não é necessário backfill. H2.4b pode introduzir a dimensão nullable de variante no ledger e migrar as duas fronteiras de escrita com testes de idempotência/concorrência, sem transformação histórica de dados.

### H2.4b — Ledger canónico com dimensão de variante — concluída

O contract H2.4b:

- acrescenta `stock_movements.product_variant_id` nullable, com FK restritiva e índice cronológico;
- faz cada movimento de variante atualizar atomicamente o snapshot agregado do produto e o snapshot da variante;
- preserva variantes pré-ledger através de movimentos de abertura neutros para o agregado, evitando dupla contagem;
- encaminha baixas da Loja e ajustes manuais do catálogo pelo `StockLedgerService`, com idempotência e locks;
- deriva o stock agregado pela soma das variantes quando estas existem e torna esse valor read-only no formulário;
- deixa de apagar variantes com histórico: variantes omitidas são zeradas pelo ledger e desativadas;
- transforma a antiga exceção estática de escrita de variante num erro acionável e reforça o gate produtivo para exigir a coluna e zero writers diretos.

Como a auditoria H2.4a confirmou zero variantes em produção, a migration não necessitou de backfill nem alterou movimentos históricos.

PR #241 merged em `f8ce467a51c6d605fc3fb62f0fc64585614e1106`; CI #944 totalmente verde na PR e CI #945 totalmente verde em `main`, incluindo PostgreSQL, browser QA e deploy para a Oracle VM. O artifact produtivo `variant-stock-production-readiness-f8ce467a51c6d605fc3fb62f0fc64585614e1106` confirmou:

- `stock_movements.product_variant_id` presente e schema fonte integralmente detetado;
- `0` writers diretos conhecidos de stock de variante;
- `0` variantes, `0` snapshots inválidos, `0` mismatches agregados e `0` movimentos históricos por reconciliar;
- `ready_for_design=true`, com auditoria read-only e sem backfill.

A frente estrutural de stock por variante fica encerrada. Novas mutações de `product_variants.stock` ou `stock_reservado` devem passar pelo `StockLedgerService` e preservar, na mesma transação, os snapshots do produto e da variante.

### H2.5a — Contract topológico das rotas web — concluída

Antes de extrair novas áreas de `routes/web.php`, o contract H2.5a:

- inventaria a assinatura runtime das rotas web, incluindo método, URI, domínio, nome, action, middleware, constraints, ordem e fallback;
- fixa essa assinatura num hash versionado e bloqueia drift não revisto na CI;
- mede duplicados por método+URI e nomes repetidos sem os corrigir silenciosamente nesta fase;
- inventaria os 18 ficheiros modulares existentes e as três superfícies atuais de registo (`routes/web.php`, `AppServiceProvider` e `bootstrap/app.php`);
- identifica redirects de compatibilidade e consumidores ainda ativos no backend/frontend antes de qualquer aposentação;
- arquiva o relatório completo como artifact da CI, sem alterar URLs, nomes, middleware, ordem ou comportamento.

O baseline estático atual tem `752` linhas, `325` declarações diretas e `51` imports de controllers em `routes/web.php`, além de `23` redirects. A captura runtime fixa `517` rotas web, `491` nomes efetivos e o hash `8bbfac80f53e31147b1b0cc4715540b67370b08011e4a45a91eba704db787c5b`. O fallback público `public.custom-page` é único e ocupa a posição `507`; dez rotas registadas por providers surgem depois dele, pelo que o contract preserva a ordem real e não assume que o fallback é fisicamente o último.

A auditoria não encontrou colisões método+URI no router efetivo, mas sinalizou três candidatos literais no source para classificação antes da extração. Entre eles, as duas declarações `GET /loja` mostram que `store.front.index` é sobrescrito por `loja.index` no lookup nominal; não existe autorização para apagar ou recriar esse alias silenciosamente. Permanecem também três consumidores frontend de redirects legacy (`/marketing` e `/settings`) em `resources/js/Layouts/Spark/AppLayout.tsx`, que devem migrar para os destinos canónicos antes de qualquer aposentação.

PR #243 merged em `358667428714cf8d293c87469a558763e237a531`; CI #950 totalmente verde na PR e CI #951 totalmente verde em `main`, incluindo PostgreSQL, browser QA e deploy para a Oracle VM. O artifact `web-route-topology-358667428714cf8d293c87469a558763e237a531` confirma o mesmo hash e as mesmas contagens após o merge. H2.5a fica encerrada como auditoria read-only: nenhuma rota, URL, middleware ou ordem foi alterada.

### H2.5b — Primeira extração modular de rotas — concluída

O primeiro lote controlado:

- extrai os 22 redirects ingleses de compatibilidade para `routes/web_compatibility.php`, mantendo o `require` na posição original;
- preserva o redirect autenticado `/portal/loja` junto das rotas do Portal, totalizando os mesmos 23 redirects;
- migra os três consumidores first-party de `/marketing` e `/settings` no `Spark/AppLayout` para `/campanhas-marketing` e `/configuracoes`;
- bloqueia em CI qualquer novo consumidor first-party desses redirects;
- classifica os três candidatos literais: `GET /` corresponde a cinco rotas distintas sob prefixes; `GET /loja` e `PUT /configuracoes/clube` são aliases declarados duas vezes, com apenas o último nome resolvido pelo router;
- mantém como condição de aceitação o hash H2.5a, as 517 rotas, os 491 nomes, a ordem, middleware, constraints e fallback.

Nenhum redirect ou alias é aposentado neste lote. A compatibilidade externa permanece disponível enquanto a modularização elimina dependências internas.

PR #245 merged em `3940b30138d823b843d0410bf982cd1791f32150`; CI #954 totalmente verde na PR e CI #955 totalmente verde em `main`, incluindo PostgreSQL, browser QA e deploy para a Oracle VM. O artifact `web-route-topology-3940b30138d823b843d0410bf982cd1791f32150` confirmou em produção de CI: hash H2.5a inalterado, `517` rotas, `491` nomes, `19/19` ficheiros modulares carregados, `23` redirects preservados, `0` consumidores first-party e `3/3` candidatos classificados. `routes/web.php` desceu para `729` linhas e `303` declarações diretas sem alterar comportamento runtime.

### H2.5c — Portal modular e aliases shadowed — concluída

O segundo lote controlado:

- confirma consumo zero de `store.front.index` e `configuracoes.club.update` e remove apenas as duas declarações sobrescritas, preservando `loja.index` e `configuracoes.clube.update` no lookup efetivo;
- extrai Portal, Loja autenticada e Família para `routes/web_portal.php`, carregado na posição original dentro do grupo `auth` + `verified`;
- transforma os dois aliases retirados num inventário permanente e bloqueia em CI qualquer nova referência first-party;
- reduz os candidatos literais para o único caso prefix-scoped `GET /`, já classificado, mantendo os 23 redirects de compatibilidade ativos;
- conserva como condição de aceitação o hash H2.5a, as 517 rotas, os 491 nomes, ordem, middleware, constraints e fallback.

PR #247 merged em `3536f70d31d27f0d512d5293f03b7c33e5f575e4`; CI #958 totalmente verde na PR e CI #959 totalmente verde em `main`, incluindo PostgreSQL, browser QA, deploy para a Oracle VM e audits produtivos pós-deploy. O artifact `web-route-topology-3536f70d31d27f0d512d5293f03b7c33e5f575e4` confirmou: hash H2.5a inalterado, `517` rotas, `491` nomes, `20/20` ficheiros modulares carregados, `23` redirects preservados, `0` referências first-party aos aliases retirados e `1/1` candidato literal classificado. `routes/web.php` desceu para `662` linhas, `271` declarações diretas e `41` imports de controllers sem alterar comportamento runtime.

---

## 7. Dívida estrutural prioritária

- Desportivo: fechar fluxo Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup.
- Eventos: remover estruturas de compatibilidade sem consumo e criar contract tests com Desportivo.
- Rotas: modularizar `routes/web.php` sem alterar URLs.
- Fiscal: implementar provider real ou formalizar definitivamente o workflow manual como modelo produtivo.
- Frontend QA: baseline automático H1.17/H1.18 ativo com autenticação e navegação core; expandir workspaces, operações críticas, perfis não-admin, tablet e Portal sem enfraquecer os gates.
- Access Control: resolver os 83 warnings de capability granular sem reabrir bypasses de módulo.

---

## 8. Prioridades recomendadas

| Ordem | Sprint | Objetivo |
|---:|---|---|
| 1 | H2 | Avançar para legacy transversal e rotas modulares, preservando URLs, nomes e middleware. |
| 2 | H3 | Fecho Desportivo ponta a ponta. |
| 3 | H4 | Decisão e fecho Fiscal. |
| 4 | H5 | Loja + Logística lifecycle completo. |
| 5 | H6 | Comunicação assíncrona e futura integração Redes. |
| 6 | H7 | Portal/PWA/mobile. |
| 7 | H8 | Reporting consolidado. |
| 8 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo ativo: H2.5d — preparar o terceiro lote modular, privilegiando a fronteira coesa de Configurações e preservando o contract H2.5a, ordem, middleware, constraints, nomes efetivos e fallback. A extração deve reduzir novamente imports e declarações diretas sem misturar a segunda fronteira financeira nem aposentar os 23 redirects externos, cuja remoção exige evidência externa própria. Stock por variante e Família/EE estão estruturalmente fechados. A ação operacional Cloudflare R2 permanece pendência externa separada. A matriz H1.17/H1.18 deve ser expandida dentro de cada workstream funcional.

---

## 9. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
| 2026-08-29 | Rotas / Portal | H2.5c confirmou zero consumidores dos dois aliases shadowed, retirou as declarações mortas e extraiu Portal/Loja/Família para um segundo módulo, com gate permanente contra reintrodução. | PR #247; CI #958/#959; merge `3536f70d31d27f0d512d5293f03b7c33e5f575e4`; artifact `web-route-topology-3536f70d31d27f0d512d5293f03b7c33e5f575e4` | Integrado e deployado; hash preservado, 517 rotas, 491 nomes, 20/20 módulos, 23 redirects, 0 referências aos aliases retirados e 1/1 candidato classificado. H2.5d avança para Configurações. |
| 2026-08-29 | Rotas / Legacy transversal | H2.5b extraiu os 22 redirects ingleses para um módulo dedicado, migrou todos os consumidores first-party para URLs canónicas e classificou os três candidatos duplicados sem alterar o router efetivo. | PR #245; CI #954/#955; merge `3940b30138d823b843d0410bf982cd1791f32150`; artifact `web-route-topology-3940b30138d823b843d0410bf982cd1791f32150` | Integrado e deployado; hash preservado, 517 rotas, 491 nomes, 19/19 módulos, 23 redirects, 0 consumidores internos e 0 candidatos por classificar. H2.5c avança para aliases shadowed e segundo lote modular. |
| 2026-08-29 | Rotas / Legacy transversal | H2.5a instalou auditoria read-only e contract SHA-256 da topologia web, incluindo ordem, lookup nominal, middleware, constraints, fallback, redirects, consumidores legacy e ficheiros modulares. | PR #243; CI #950/#951; merge `358667428714cf8d293c87469a558763e237a531`; artifact `web-route-topology-358667428714cf8d293c87469a558763e237a531` | Integrado e deployado; 517 rotas, 491 nomes, 18/18 módulos carregados e fallback único. H2.5b pode iniciar a primeira extração controlada, preservando o contract e classificando os candidatos duplicados. |
| 2026-08-29 | Inventário / Loja | H2.4b acrescentou a dimensão nullable de variante ao ledger, tornou produto+variante atómicos, converteu baixas e ajustes manuais para o boundary canónico e eliminou os dois writers diretos. | PR #241; CI #944/#945; merge `f8ce467a51c6d605fc3fb62f0fc64585614e1106`; artifact `variant-stock-production-readiness-f8ce467a51c6d605fc3fb62f0fc64585614e1106` | Integrado e deployado; coluna presente, 0 writers diretos, 0 inconsistências e nenhum backfill necessário. Frente estrutural encerrada; H2.5a avança para legacy transversal e rotas modulares. |
| 2026-08-29 | Inventário / Loja | H2.4a instalou auditoria produtiva agregada/read-only para stock por variante e confirmou ausência total de variantes e histórico associado. | PR #239; CI #938/#939; merge `6972a7aa9e859afe0764c2242b143aa83d110b84`; artifact `variant-stock-production-readiness-6972a7aa9e859afe0764c2242b143aa83d110b84` | Deployado; 0 variantes, 0 incoerências, 2 writers diretos conhecidos e `ready_for_design=true`. H2.4b avança sem backfill. |
| 2026-08-29 | Membros / Família / EE | H2.3d removeu fisicamente `user_relationships` e os mirrors JSON, retirou código/rotas/auditors de transição e instalou o gate permanente `members:audit-family-final-schema`. | PR #237; CI #933/#934; merge `d7701cbe05c62b994643c6c91cf859fc199787a2`; artifact `family-final-schema-d7701cbe05c62b994643c6c91cf859fc199787a2` | Integrado e deployado na Oracle VM; produção confirma 3/3 estruturas canónicas, zero legacy e `ready=true`. Frente estrutural encerrada; prioridade H2 passa para stock por variante. |
| 2026-08-29 | Membros / Família / EE | H2.3c executou em produção os audits minimizados e confirmou readiness integral: `user_relationships` vazio; 12/12 pares JSON cobertos; zero consumers, uncovered, invalid e self-reference. | PR #236; CI #930/#931; merge `1489e01d82e407926edb332b51ae9a248849067b`; artifacts `family-legacy-relationships-production-readiness-*` e `family-json-mirrors-production-readiness-*` | Deployado e aprovado para contract físico; não é necessário backfill. H2.3d remove as estruturas aposentadas e instala o gate final de schema. |
| 2026-08-29 | Membros / Família / EE | H2.3b removeu consumidores runtime dos mirrors JSON, convergiu edição/leitura em `user_guardian`/`familia_user` e eliminou a reconciliação destrutiva ao abrir fichas. | PR #235; CI #928/#929; merge `c8e8a36073b45c5ed49abd7702ec8fd93db83c59`; `FamilyRelationshipService`; `FamilyRuntimeCanonicalCutoverTest`; `FamilyCanonicalBulkReplacementTest` | Integrado e deployado na Oracle VM. H2.3c recolhe agora evidência produtiva minimizada; não existe autorização para drop até os resultados reais estarem limpos. |
| 2026-08-29 | Membros / Família / EE | H2.3a iniciou o cutover dos mirrors JSON: serviço canónico deixa de escrever JSON, mirrors deixam de ser mass-assignable e auditor read-only mede consumidores/cobertura; E2E accessibility estabilizado sem silenciar regras. | PR #232; CI #924/#925; merge `c606daeffadfc07b000d1448293379e4d821e13a`; `FamilyJsonMirrorAuditor`; `AuditFamilyJsonMirrorsCommand`; `tests/e2e/authenticated-access.spec.ts` | Integrado e deployado na Oracle VM; a H2.3b retirou depois os consumidores runtime restantes. |
| 2026-08-29 | CI / Segurança | Retry seguro no Composer audit após timeout transitório do Packagist: repete apenas resposta técnica inválida e continua fail-closed para advisories e indisponibilidade persistente. | PR #233; merge `c68903abefcf1ca5a719f1c421dd1346f42f3cd8`; CI #921 | Integrado e deployado; baseline Composer continua 0 advisories sem bypass de segurança. |
| 2026-08-28 | Membros / Família / EE | H2.2 neutralizou `user_relationships`: runtime legacy responde 410 e auditor read-only mede cobertura pela estrutura canónica. | PR #231; merge `ef9ae22b820cdd8eec940f1a618ca01707c98897`; `FamilyLegacyRelationshipAuditor`; `LegacyMemberRelationshipRuntimeRetirementTest` | Integrado e em produção; limpeza física fica condicionada a auditoria de dados. |
| 2026-08-28 | Membros / Família / EE | H2.1 introduziu boundary canónico de escrita e regras determinísticas entre `user_guardian` e `familia_user`, sem inventar agregados familiares. | PR #230; CI #905/#906; merge `deee18a7fadb0288efafd67306471e8b8c849075`; `FamilyRelationshipServiceCanonicalWriteTest` | Integrado e deployado; base para H2.2/H2.3. |
| 2026-08-28 | QA / Frontend autenticado | H1.18 acrescentou fixtures determinísticas, autenticação/sessão real, recuperação de password, WCAG/overflow no Dashboard e navegação core para Membros, Desportivo, Eventos, Financeiro e Configurações nos cinco perfis Playwright. | PR #228; CI #900/#901; merge `34ad7cb1b59c79b946584aa6fd58c908b8fd4154`; `tests/e2e/authenticated-access.spec.ts`; `docs/qa/FRONTEND_QA_MATRIX.md` | Integrado em `main`; falta cobertura profunda por perfil, workspace, tablet e operações críticas dentro dos workstreams funcionais. |
| 2026-08-28 | Desportivo / Análise | Hardening da workspace transversal: métricas Cais + Live com proveniência separada, agregação de grupos em batch, splits competitivos expansíveis, export CSV e retirada dos endpoints legacy Performance do routing ativo. | PR #227; CI #898; merge `5932732b8777e0055e0e15c101b5eae7bcbb8adb`; `SportsAnalysisWorkspaceService`; `SportsAnalysisWorkspaceFunctionalTest`; `docs/modules/desportivo_analysis_workspace.md` | Integrado em `main`, sem migrations; Análise permanece read-only e o cleanup físico do legacy residual fica para workstream controlado. |
| 2026-08-28 | QA / Frontend | H1.17 criou o baseline automático de lint, unit/component, multi-browser/mobile E2E e acessibilidade, tornando o browser QA dependência do deploy. | PR #225; CI técnica #875; `docs/qa/FRONTEND_QA_MATRIX.md`; `playwright.config.ts`; `vitest.config.ts`; `eslint.config.js` | Tooling transversal fechado; cobertura deve crescer por fluxo/módulo sem enfraquecer os gates. |
| 2026-08-28 | Infraestrutura / DR | H1.16 colocou em produção probes least-privilege e verificação read-only de Bucket Lock, separando data plane S3 de control plane Cloudflare. | PR #224; CI #870; merge `55989937458271b0348c5cd7818c6c83acb171f1`; `scripts/ops/dr/probe-r2-access.sh` | Código/deploy concluído; rotação real e Bucket Locks na conta Cloudflare permanecem pendência operacional externa. |
| 2026-08-27 | QA / Dependências npm | H1.15 substituiu `xlsx` 0.18.5 pela release oficial SheetJS CE 0.20.3 vendorizada, preservando XLSX/XLS/ODS/CSV/HTML e eliminando a última vulnerabilidade npm residual. | PR #223; `vendor/xlsx-0.20.3.tgz`; `scripts/qa/xlsx-import-contract.mjs`; `scripts/qa/npm-audit-ratchet.mjs` | npm audit e ratchet fechados em 0 vulnerabilidades; resta R2 hardening e QA frontend em H1. |
| 2026-08-26 | QA / Framework / Composer | H1.14 elevou Laravel 11 para Laravel 13.29.0, preservou UUIDv4 nos modelos e eliminou os 3 advisories Composer residuais. | PR #222; CI #852/#853; merge `99ba31100620754167053e4251ee0f97da282dc6` | Integrado e deployado na Oracle VM; Composer ratchet fechado em 0 advisories. |
| 2026-08-26 | QA / TypeScript / Banco | H1.13 fechou os 16 diagnósticos finais de `BancoTab.tsx`, alinhando contratos locais de rotas, centros de custo e origem `movement`, reduzindo a dívida TypeScript de 16 erros/1 ficheiro para 0 erros/0 ficheiros sem alterar regras financeiras. | PR #220; CI #829/#833; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Ratchet TypeScript fechado em 0/0; deixa de existir dívida TypeScript aceite no CI. |
| 2026-08-24 | QA / TypeScript / Faturas | H1.12 fechou os 6 diagnósticos de `FaturasTab.tsx`, substituindo helpers residuais e alinhando `user_id` com o guard já existente, reduzindo a dívida de 22 erros/2 ficheiros para 16 erros/1 ficheiro sem tocar em Banco. | PR #219; CI #817; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 16/1. Resta apenas `BancoTab.tsx` com 16 diagnósticos para lote financeiro isolado. |
| 2026-08-24 | QA / TypeScript / Portal + transporte financeiro | H1.11 completou o filtro e abertura de comunicações no Portal e corrigiu o narrowing do body em `Financeiro/request.ts`, reduzindo a dívida de 25 erros/4 ficheiros para 22 erros/2 ficheiros. | PR #218; diagnóstico completo `tsc --noEmit`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 22/2. Restam apenas Banco 16 e Faturas 6. |
| 2026-08-24 | QA / TypeScript / residuais low-risk | H1.10 fechou Configurações, contratos Desportivo/Membros e o fallback de agendamento do dashboard financeiro, reduzindo a dívida de 36 erros/10 ficheiros para 25 erros/4 ficheiros sem tocar em Banco ou Faturas. | PR #217; medição `tsc --noEmit`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 25/4. Restam Banco 16, Faturas 6, Portal 2 e `Financeiro/request.ts` 1. |
| 2026-08-24 | QA / TypeScript / contratos por domínio | H1.9 alinhou contratos TypeScript de baixo risco em Eventos, Desportivo, Loja, Marketing, Perfil e Portal, reduzindo a dívida de 53 erros/21 ficheiros para 36 erros/10 ficheiros sem tocar no fluxo bancário crítico. | PR #216; CI #790; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 36/10. `Financeiro/BancoTab.tsx` mantém 16 diagnósticos e continua reservado a lote próprio. |
| 2026-08-24 | QA / TypeScript / Inertia | H1.8 normalizou `router.reload()`, compatibilidade ES2020 e inferências locais de tipo sem alteração de runtime, reduzindo a dívida TypeScript de 66 erros/27 ficheiros para 53 erros/21 ficheiros. | PR #215; CI #769; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 53/21. `Financeiro/BancoTab.tsx` permaneceu fora do lote e mantém 16 diagnósticos para tratamento isolado. |
| 2026-08-24 | QA / TypeScript / Inertia | H1.7 normalizou o contrato `PageProps`/`usePage` sem alterar runtime e eliminou todos os 22 `TS2344`, reduzindo a dívida TypeScript de 88 erros/44 ficheiros para 66 erros/27 ficheiros. | PR #214; CI #760; `resources/js/types/index.d.ts`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 66/27. H1.8 reduziu posteriormente para 53/21. |
| 2026-08-23 | QA / TypeScript / Dead code | H1.6 mediu a concentração dos diagnósticos, removeu um barrel desportivo obsoleto e seis componentes UI órfãos, reduzindo a dívida TypeScript de 101 erros/51 ficheiros para 88 erros/44 ficheiros. | PR #213; `scripts/qa/typecheck-ratchet.mjs`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 88/44. Nenhuma dependência morta foi reinstalada para preservar componentes sem consumidores. |
| 2026-08-23 | QA / TypeScript / Comunicação | H1.5 declarou o contrato global de canais já usado por Comunicação e reduziu a dívida TypeScript de 123 erros/51 ficheiros para 101 erros/51 ficheiros, apertando novamente o ratchet. | PR #212; CI #738; `resources/js/types/global.d.ts`; `qa/baselines/typescript.json`; `docs/qa/H1_BASELINE.md` | Novo teto 101/51. H1.6 reduziu posteriormente para 88/44. |
| 2026-08-22 | QA / TypeScript | H1.4 removeu quatro componentes UI órfãos e reduziu a dívida TypeScript de 132 erros/55 ficheiros para 123 erros/51 ficheiros, apertando o ratchet para o novo valor. | PR #208; CI #730; merge `6df774c4ef9cdb89f5aea0ea0e35fde50fba9ae1`; `qa/baselines/typescript.json` | Integrado em `main`. Paydown continuado em H1.5/H1.6. |
| 2026-08-22 | QA / Dependências | H1.1 mediu baseline Composer/npm/TypeScript, provou remediação compatível e introduziu ratchets anti-regressão. | PRs diagnóstico #203/#204; PR #205; `docs/qa/H1_BASELINE.md`; `scripts/qa/*`; `qa/baselines/typescript.json` | CI #720 totalmente verde. Residual controlado: 3 advisories Laravel 11, 1 high `xlsx`; baseline TS inicial 132/55, fechado por H1.13 em 0/0. |
| 2026-08-22 | Infraestrutura / DR | H0.2 fechada em produção com Cloudflare R2 real: escrita/leitura/eliminação S3 validadas, primeiro backup cifrado remoto criado, restore PG17 a partir do objeto remoto e health estrito verdes. | PR #200; diagnóstico #201; release `547dd6aaacfa69d188258200c8811974d06bdf6e`; arquivo `clubos-prod-20260822T001929Z.tar.gz.gpg`; restore 208 tabelas/214 migrations/5 s; `r2_real_validation=ok` | H0.2 concluída operacionalmente. Residual H1: token de menor privilégio e confirmação Bucket Lock. |
| 2026-08-22 | Infraestrutura / DR | Automatizado o bootstrap produtivo R2 com secrets, SSH pinned, prova backup+restore antes de ativar cron/marker e health estrito. | PR #200; `.github/workflows/dr-r2-activate.yml`; `docs/DR_R2_ACTIVATION.md` | Integrado em `main` e usado com sucesso na ativação real. |
| 2026-08-21 | Financeiro / Legacy cleanup | Aposentados CRUDs legacy de transações/categorias e removido placeholder ativo de `Financeiro/Edit`. | PRs #179/#180 | Integrado em `main`, sem migrations nem alteração de dados. |
| 2026-08-21 | Infraestrutura / Deploy | H0.1b fechada com releases atómicas, shared state, `current/previous`, healthchecks e rollback. | PRs #186/#188 | Produção em `atomic-v1`, `/up=200`, rollback target preservado. |
| 2026-08-21 | Access Control / Rotas | Eliminados os 92 gaps críticos de `module.access`. | PR #184; auditoria produtiva | `critical_count=0`; 83 warnings granulares permanecem. |
| 2026-08-21 | Infraestrutura / CI/CD | H0.1a endureceu secrets, SSH pinned, dependency audits e gates críticos. | PRs #181/#182 | Concluído em produção. |

---

## Regra final

Implementar sem atualizar contexto cria dívida. Atualizar contexto sem validar código cria ilusão.

O fluxo correto mantém-se:

`implementar → validar → registar`
