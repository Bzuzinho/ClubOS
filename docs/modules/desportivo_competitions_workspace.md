# Desportivo — Competições

## Fronteira funcional

Competições é o master do ciclo competitivo. O módulo Eventos recebe uma projeção da competição através de `competition_event_projections`; não é uma segunda fonte de verdade para nome, datas, local ou estado da competição.

Fluxo funcional:

`Competição → Programa → Convocatória → Inscrições → Financeiro → Resultados`

- **Competição**: lifecycle e dados competitivos.
- **Programa**: provas (`provas`) pertencentes à competição.
- **Convocatória**: reutiliza o domínio existente de Eventos/Convocatórias e liga exclusivamente pelo `event_id` da projeção canónica.
- **Inscrições**: `competition_registrations`, atleta × prova.
- **Financeiro**: `competition_finance_policies` e `competition_financial_obligations`; faturação/pagamento continuam propriedade do Financeiro.
- **Resultados**: resultados desta competição; a vista transversal permanece responsabilidade do mini-módulo Resultados.

## Cutover

`GET /desportivo/competicoes` é resolvido para `SportsCompetitionWorkspaceController` no boot final das rotas, substituindo a renderização legacy sem remover ainda a compatibilidade histórica.

O detalhe contextual é obtido por:

`GET /desportivo/competicoes/{competition}/workspace`

A nova workspace nunca associa competição, Evento ou Convocatória por título/data. A relação é:

`competition.id → competition_event_projections.competition_id → event_id → convocation_groups.evento_id`

## Read model

`SportsCompetitionWorkspaceService` agrega, sem copiar dados:

- `competitions`;
- `competition_event_projections`;
- `provas`;
- `convocation_groups` / `convocation_athletes`;
- `competition_registrations`;
- `competition_finance_policies`;
- `competition_financial_obligations`;
- `results`;
- `team_results`.

A identidade visível dos atletas é resolvida por `MemberIdentityDisplayResolver`.

## Lifecycle e readiness

Os estados persistidos continuam os estados canónicos já suportados pelo `CompetitionLifecycleService`:

- `scheduled`;
- `completed`;
- `cancelled`;
- `archived`.

`readiness` é derivado para a UI e não cria um segundo lifecycle:

- `ready`: sem pendências estruturais conhecidas;
- `attention`: projeção de Evento ou obrigação financeira requer atenção;
- `closed`: competição concluída, cancelada ou arquivada.

## Integrações

A criação/edição da competição continua a passar pelo API canónico `/api/desportivo/competitions`, que usa `CompetitionLifecycleService`, sincroniza a projeção de Eventos e garante a política financeira default.

O programa reutiliza `/api/provas`. Inscrições continuam a usar as actions canónicas existentes de `CompetitionRegistrationController`, preservando o lifecycle financeiro.

## Guard rails

- não criar Event master em paralelo;
- não pesquisar Convocatórias por nome/data;
- não derivar o estado persistido apenas da data atual;
- não copiar inscrições, obrigações ou resultados para uma tabela de workspace;
- não escrever diretamente em faturas/movimentos a partir de Competições;
- sempre respeitar `club_id` no master Competition e nas obrigações financeiras.
