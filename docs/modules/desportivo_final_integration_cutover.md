# Desportivo — integração final e cutover

## Estado

A superfície funcional do módulo Desportivo está distribuída por workspaces canónicas dedicadas: Dashboard, Atletas, Estrutura, Planeamento, Biblioteca, Treinos, Cais, Live, Registos, Avaliações, Competições, Convocatórias, Resultados e Análise.

## Encerramento do runtime legacy

O middleware `EnforceSportsLegacyCutover` é a fronteira final para caminhos históricos ainda declarados por compatibilidade.

- `/desportivo/presencas` redireciona para `/desportivo/cais` e preserva `training_id` quando fornecido.
- writes legacy de Presenças devolvem `410 Gone`.
- writes legacy de Treinos (`store`, `agendar`, `update`, `duplicar`, `delete`, presenças/atletas) devolvem `410 Gone`.
- writes legacy de Épocas/Macrociclos/Mesociclos permanecem fechados; Planeamento é o owner operacional.
- Equipas, Membros de equipa, Sessões de formação e Convocatórias legacy continuam redirecionados/fechados pelas regras F7.
- o middleware de cutover corre antes do route model binding, garantindo `410` consistente para endpoints retirados mesmo quando o UUID já não existe.

## Contrato de métricas do Cais

`POST /desportivo/cais/metricas` permanece ativo nesta fase porque continua coberto pelo fluxo canónico de criação de `training_metrics`. Não é tratado como endpoint retirado enquanto esse contrato não for substituído por um equivalente funcional na workspace Cais.

## Compatibilidade

As declarações de rotas e classes legacy podem permanecer temporariamente no repositório enquanto existirem referências históricas, documentação ou testes de migração. Isso não lhes confere autoridade runtime nos fluxos encerrados acima.

Não são removidas tabelas, colunas ou dados históricos nesta fase. Remoção física exige equivalência demonstrável e uma fase de cleanup própria.

## Guard rail

`SportsFinalIntegrationCutoverTest` garante que:

1. writes concorrentes antigos não voltam a ficar acessíveis;
2. Treinos, Cais e o contrato atual de métricas continuam acessíveis;
3. a fronteira final permanece explícita no middleware e antecede o route model binding.

## PR histórica #138

A PR #138 pertence à arquitetura anterior à fundação F0–F7. O seu objetivo foi substituído pelas workspaces Cais (#151) e Live (#152) já integradas. Permanece fechada como `superseded`, sem merge.
