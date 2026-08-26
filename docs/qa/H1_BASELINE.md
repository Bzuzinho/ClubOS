# H1 — Baseline de QA e Dependências

Data de referência: 2026-08-22.

## Baseline inicial medido

O diagnóstico H1 foi executado sobre o `main` antes de qualquer remediação desta sprint.

- Composer: 34 advisories — 0 critical, 8 high, 24 medium, 1 low e 1 sem severity normalizada;
- npm: 12 vulnerabilidades — 0 critical, 9 high, 2 moderate e 1 low;
- TypeScript: 132 erros em 55 ficheiros com `tsc --noEmit`;
- build Vite: já verde no CI existente;
- PHPUnit: suite completa verde no CI existente.

## H1.1 — remediação compatível

Foi primeiro simulada num runner limpo e só depois aplicada aos lockfiles.

Atualizações Composer compatíveis:

- `guzzlehttp/guzzle` → 7.15.3;
- `guzzlehttp/psr7` → 2.13.0;
- `league/commonmark` → 2.10.0;
- `psy/psysh` → 0.12.24;
- componentes Symfony vulneráveis → série 7.4 corrigida;
- `laravel/framework` mantém-se em Laravel 11 nesta sprint.

Atualizações npm compatíveis:

- `axios` → ^1.19.0;
- `postcss` → ^8.5.26;
- `vite` → ^6.4.3;
- `npm audit fix` sem `--force` apenas sobre o lockfile;
- `xlsx` mantém-se temporariamente em ^0.18.5.

A simulação concluiu com build e PHPUnit verdes e sem aumentar a dívida TypeScript.

Resultado esperado/provado antes da aplicação:

- Composer: 34 → 3 advisories, todos em `laravel/framework` 11;
- npm: 12 → 1 vulnerabilidade, apenas `xlsx` high sem `fixAvailable`;
- TypeScript: 132 erros / 55 ficheiros, sem regressão.

## Ratchets introduzidos

### Composer

O CI aceita temporariamente no máximo:

- 3 advisories no total;
- todos exclusivamente em `laravel/framework`;
- 0 critical;
- no máximo 1 high.

Qualquer advisory noutro pacote, novo critical ou crescimento acima deste baseline falha o CI.

A eliminação integral do residual Laravel exige uma migração planeada para Laravel 12+ e não é misturada com este lote de patches/minors.

### npm

O CI aceita temporariamente apenas:

- `xlsx` como único pacote vulnerável;
- 1 vulnerabilidade total;
- 0 critical;
- no máximo 1 high;
- 0 moderate e 0 low;
- `fixAvailable=false`.

Se surgir uma correção npm para `xlsx`, o ratchet falha para forçar a remoção da exceção. Se surgir qualquer outro pacote vulnerável, o CI também falha.

`xlsx` é atualmente usado pelo importador de membros para leitura de `.xlsx`, `.xls` e `.csv`; a sua substituição será tratada como migração funcional separada para preservar o fluxo de importação.

### TypeScript

O baseline inicial foi versionado em `qa/baselines/typescript.json` com 132 erros / 55 ficheiros. O ratchet é descendente: sempre que uma alteração reduz a dívida, o teto tem de ser apertado no mesmo PR.

Evolução validada:

- H1.4: 132 erros / 55 ficheiros → 123 erros / 51 ficheiros;
- H1.5: 123 erros / 51 ficheiros → 101 erros / 51 ficheiros, ao declarar explicitamente o contrato global `Channel` usado pelo módulo Comunicação;
- H1.6: 101 erros / 51 ficheiros → 88 erros / 44 ficheiros, removendo um barrel desportivo obsoleto que exportava sete tabs já eliminadas e seis componentes UI órfãos sem consumidores runtime (`carousel`, `drawer`, `form`, `resizable`, `sonner` e `sidebar`);
- H1.7: 88 erros / 44 ficheiros → 66 erros / 27 ficheiros, normalizando o contrato genérico `PageProps<T>` e os seis `usePage` locais que ainda usavam interfaces sem index signature compatível com Inertia. Foram eliminados os 22 diagnósticos `TS2344` sem alteração de runtime;
- H1.8: 66 erros / 27 ficheiros → 53 erros / 21 ficheiros. O lote removeu opções redundantes de `router.reload()` já implícitas pelo Inertia, substituiu duas utilizações de `replaceAll` por equivalentes compatíveis com o target atual e corrigiu duas inferências locais de tipo sem alterar o comportamento runtime. `Financeiro/BancoTab.tsx` não foi alterado e manteve os mesmos 16 diagnósticos. A CI #769 validou integralmente o ratchet TypeScript, build Vite, PHPUnit, legacy-read guard e PostgreSQL concurrency;
- H1.9: 53 erros / 21 ficheiros → 36 erros / 10 ficheiros. O lote alinhou contratos TypeScript de baixo risco com payloads e estados já existentes em Eventos, Desportivo, Loja, Marketing, Perfil e Portal; normalizou `tempo_oficial` para string no adapter de resultados e tornou explícitas propriedades opcionais já consumidas. Não alterou regras de negócio, migrations ou dados e manteve `Financeiro/BancoTab.tsx` totalmente fora do lote. A CI #790 validou o código antes do aperto final do ratchet, com build Vite, 1764 testes / 9860 assertions, legacy-read guard e PostgreSQL concurrency verdes.
- H1.10: 36 erros / 10 ficheiros → 25 erros / 4 ficheiros. Foram fechados contratos residuais de baixo risco em Configurações, Desportivo e Membros, removidos dois imports diretos para `@/lib/types` inexistente em favor do contrato `@/types`, normalizado o fallback de dados médicos e corrigido apenas o fallback de agendamento do dashboard financeiro. `BancoTab.tsx`, `FaturasTab.tsx`, `Financeiro/request.ts` e as duas ações incompletas de `Portal/Communications.tsx` ficaram fora do lote.
- H1.11: 25 erros / 4 ficheiros → 22 erros / 2 ficheiros. O Portal passou a ter filtro funcional por categoria, lista de comunicações recentes e abertura coerente de cada item, marcando-o como lido antes de seguir para o link associado ou para o detalhe da caixa de entrada. Em `Financeiro/request.ts`, o body do `fetch` passou a preservar diretamente o narrowing de `FormData`, sem alterar payloads, endpoints ou regras financeiras. A execução diagnóstica da PR #218 confirmou exatamente 22 erros em 2 ficheiros, sem qualquer residual em Portal ou `Financeiro/request.ts`.
- H1.12: 22 erros / 2 ficheiros → 16 erros / 1 ficheiro. `Financeiro/FaturasTab.tsx` passou a usar o helper financeiro canónico `getFinanceiroAxiosJsonConfig` nas três chamadas residuais e os payloads manuais usam o `user_id` já validado pelo guard do formulário; uma fatura histórica sem titular é normalizada para valor vazio e continua impedida de persistir até existir utilizador. A CI #817 confirmou Faturas sem diagnósticos, 1764 testes / 9860 assertions, legacy-read guard e PostgreSQL concurrency verdes. Resta apenas `Financeiro/BancoTab.tsx` com 16 diagnósticos.
- H1.13: 16 erros / 1 ficheiro → 0 erros / 0 ficheiros. O lote final de Banco estreitou o helper local de rotas ao contrato efetivamente usado, alinhou `centro_custo_id` opcional/nulo, removeu uma opção obsoleta de `router.reload` e reconheceu `movement` como origem real de lançamento financeiro. A CI #829 confirmou `tsc --noEmit` totalmente limpo, build Vite verde, 1764 testes / 9860 assertions, legacy-read guard e PostgreSQL concurrency verdes, sem alterações de endpoints, migrations, dados ou regras de reconciliação/pagamento.

O ratchet imprime no CI os ficheiros e códigos de erro com maior concentração, para orientar os próximos lotes sem alterar a regra de bloqueio.

Teto atual versionado:

- máximo 0 erros;
- máximo 0 ficheiros afetados.

O CI falha se qualquer destes limites aumentar. Uma redução futura tem obrigatoriamente de baixar novamente o baseline no mesmo PR para impedir regressão.

## Pendências H1

1. Planear Laravel 12+ para remover os 3 advisories residuais do framework.
2. Substituir/migrar `xlsx` preservando o importador de membros.
3. Reduzir o token R2 para `Object Read & Write` limitado ao bucket de backup e confirmar Bucket Lock.
4. Evoluir QA frontend com lint, unit/component tests, E2E, acessibilidade e matriz mobile/desktop.
