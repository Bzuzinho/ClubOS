# Estado Vivo de Desenvolvimento — ClubOS

> Fonte de verdade funcional e técnica do projeto ClubOS.
>
> Estado consolidado em 2026-08-28.
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
| Frontend / E2E / mobile QA | Baseline automático ativo; cobertura funcional a expandir |
| Infraestrutura / Disaster Recovery | H0.1 e H0.2 concluídos operacionalmente em produção |

Stack produtiva: Laravel 13, PHP 8.3, React 19 + TypeScript, Inertia 2, Vite, PostgreSQL 17 local na Oracle VM, Redis, GitHub Actions, Nginx e PHP-FPM.

---

## 3. Grelha viva de funcionalidades

| Módulo / Área | Estado estimado | Estado atual / pendências principais |
|---|---:|---|
| Base técnica / arquitetura | 94% | H0.1a/H0.1b/H0.2 concluídos em produção. H1.1 e H1.4–H1.15 fecharam Composer/npm/TypeScript em zero; H1.16 colocou em produção os guard rails de least privilege/verification do R2; H1.17 tornou lint, unit/component, multi-browser/mobile E2E e acessibilidade gates canónicos de CI. Resta apenas a ação operacional externa de rotação/locks R2 e expansão progressiva da cobertura por fluxo. |
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
| PWA / Mobile | 60% | TypeScript permanece 0/0. H1.17 introduziu Playwright bloqueante em Chromium/Firefox/WebKit e perfis Pixel 7/iPhone 14, com controlo de overflow e axe WCAG A/AA no baseline inicial. Falta ampliar a cobertura aos fluxos autenticados, menus/workspaces, tablet e operações críticas por módulo. |
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
2. a matriz frontend base está fechada em H1.17; a cobertura deve agora crescer dentro dos workstreams funcionais, sem enfraquecer os gates.

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

## 6. Dívida estrutural prioritária

- Família/EE: convergir `user_guardian`, `familias/familia_user`, `user_relationships` e compatibilidades para uma fonte canónica.
- Inventário: integrar `product_variants.stock` no ledger canónico ou transformar variantes/SKU em entidade física de inventário.
- Desportivo: fechar fluxo Planeamento → Treino → Cais → Live → Presenças → Competições → Resultados → Portal → reporting → legacy cleanup.
- Eventos: remover estruturas de compatibilidade sem consumo e criar contract tests com Desportivo.
- Rotas: modularizar `routes/web.php` sem alterar URLs.
- Fiscal: implementar provider real ou formalizar definitivamente o workflow manual como modelo produtivo.
- Frontend QA: baseline automático H1.17 ativo; expandir cobertura autenticada e fluxos críticos por módulo, incluindo scroll/navegação mobile e acessibilidade de componentes complexos.
- Access Control: resolver os 83 warnings de capability granular sem reabrir bypasses de módulo.

---

## 7. Prioridades recomendadas

| Ordem | Sprint | Objetivo |
|---:|---|---|
| 1 | H1 | Código/CI transversal fechado até H1.17: Composer 0, npm 0, TypeScript 0/0 e matriz frontend automática ativa. Fica apenas a ação operacional externa R2; a cobertura QA passa a crescer dentro das sprints funcionais. |
| 2 | H2 | Família/EE, stock variantes, legacy e rotas modulares. |
| 3 | H3 | Fecho Desportivo ponta a ponta. |
| 4 | H4 | Decisão e fecho Fiscal. |
| 5 | H5 | Loja + Logística lifecycle completo. |
| 6 | H6 | Comunicação assíncrona e futura integração Redes. |
| 7 | H7 | Portal/PWA/mobile. |
| 8 | H8 | Reporting consolidado. |
| 9 | H9 | Website: header/footer, notícias e polish final. |

Próximo passo ativo: iniciar H2 pela consolidação Família/EE e respetivas fontes relacionais, mantendo a ação operacional Cloudflare R2 como pendência externa separada. A matriz H1.17 deve ser expandida em cada workstream funcional.

---

## 8. Histórico vivo recente

| Data | Módulo | Desenvolvimento / análise | Evidência | Estado / pendências |
|---|---|---|---|---|
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