# Desportivo — integração final, cutover e cleanup físico

## Estado

A superfície funcional do módulo Desportivo está distribuída por workspaces canónicas dedicadas: Dashboard, Atletas, Estrutura, Planeamento, Biblioteca, Treinos, Cais, Live, Registos, Avaliações, Competições, Convocatórias, Resultados e Análise.

O cutover funcional foi integrado em `main` pela PR #161. A fase seguinte removeu fisicamente o runtime de apresentação legado que já não tinha ownership funcional.

## Encerramento do runtime legacy

O middleware `EnforceSportsLegacyCutover` continua a ser a fronteira para caminhos históricos ainda declarados por compatibilidade.

- `/desportivo/presencas` é encaminhado para Cais e preserva `training_id` quando fornecido.
- writes legacy de Presenças devolvem `410 Gone`.
- writes legacy de Treinos (`store`, `agendar`, `update`, `duplicar`, `delete`, presenças/atletas) devolvem `410 Gone`.
- writes legacy de Épocas/Macrociclos/Mesociclos permanecem fechados; Planeamento é o owner operacional.
- Equipas, Membros de equipa, Sessões de formação e Convocatórias legacy continuam redirecionados/fechados pelas regras F7.
- o middleware de cutover corre antes do route model binding, garantindo `410` consistente para endpoints retirados mesmo quando o UUID já não existe.

## Cleanup físico do runtime de apresentação

Foram removidos do runtime ativo:

- `app/Services/Desportivo/DesportivoPagePayloadBuilder.php`;
- `resources/js/Pages/Desportivo/Index.tsx`;
- wrappers legacy `DashboardTab`, `AthletesTab`, `PlanningTab`, `TrainingLibraryTab`, `TrainingsTab`, `PoolDeckTab`, `CompetitionsTab` e `PerformanceTab` em `resources/js/components/sports/tabs`.

`DesportivoController` deixou de conter queries, mutations, construção de payload ou lógica de negócio. Enquanto as declarações históricas permanecerem no `routes/web.php`, funciona apenas como shell de compatibilidade: GETs delegam diretamente nos controllers das workspaces canónicas, writes retirados abortam com `410` e métricas Cais delegam no controller canónico.

O warmup autenticado e o benchmark do módulo passaram a usar diretamente `SportsDashboardWorkspaceController`; nenhum fluxo de performance mantém o controller antigo como runtime funcional.

## Contrato de métricas do Cais

`GET/POST /desportivo/cais/metricas` continuam ativos porque fazem parte do fluxo operacional ainda coberto pela suite.

A implementação foi transferida para `SportsCaisWorkspaceController` e as rotas foram registadas em `routes/desportivo_cais.php`. A shell antiga apenas delega estes endpoints enquanto as declarações duplicadas do `web.php` não forem retiradas numa edição isolada desse ficheiro monolítico.

## Compatibilidade e dados

Não foram removidas tabelas, colunas, migrations nem dados históricos. Esta fase remove exclusivamente código/runtime de apresentação substituído por workspaces canónicas.

A remoção física de schema continua a exigir auditoria própria sobre dados reais e equivalência demonstrável.

## Guard rails

`SportsFinalIntegrationCutoverTest` garante que:

1. writes concorrentes antigos não voltam a ficar acessíveis;
2. Treinos, Cais e o contrato atual de métricas continuam acessíveis;
3. a fronteira final permanece explícita no middleware e antecede o route model binding.

`SportsLegacyRuntimePhysicalCleanupTest` garante que:

1. `DesportivoPagePayloadBuilder.php` e `Pages/Desportivo/Index.tsx` permanecem fisicamente ausentes;
2. `DesportivoController` não volta a adquirir models/queries do runtime antigo;
3. warmup e benchmark não voltam a executar `DesportivoController`;
4. o contrato de métricas fica owned pelo controller Cais canónico.

## PR histórica #138

A PR #138 pertence à arquitetura anterior à fundação F0–F7. O seu objetivo foi substituído pelas workspaces Cais (#151) e Live (#152) já integradas. Permanece fechada como `superseded`, sem merge.
