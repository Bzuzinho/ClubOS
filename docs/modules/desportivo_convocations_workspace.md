# Desportivo · Convocatórias

## Fronteira funcional

- `ConvocationGroup` é a convocatória operacional publicada pelo clube.
- `ConvocationAthlete` guarda atribuição técnica do atleta (provas/estafetas), não é a fonte de verdade da resposta.
- `EventConvocation` é a fonte canónica para resposta do membro: pendente/confirmado/recusado, data, justificação e transporte.
- O Evento é sempre a origem canónica. Quando o Evento é projeção de uma Competição, a associação é resolvida por `competition_event_projections.event_id`.
- Comunicação entrega a publicação; Financeiro trata o movimento dos custos.

## Workspace

Rota: `/desportivo/convocatorias`.

Vistas transversais:

1. Convocatórias
2. Respostas
3. Logística
4. Histórico

Consulta de uma convocatória:

- Resumo
- Atletas e provas
- Respostas
- Logística
- Publicação
- Custos

## Criação

Wizard em cinco passos:

1. Evento/competição
2. Atletas desportivamente ativos
3. Provas/estafetas por atleta
4. Logística e custos, incluindo centro de custo
5. Revisão e opção de guardar rascunho ou criar e publicar

A criação persiste diretamente em `convocation_groups`, `convocation_athletes` e `event_convocations`; o workspace novo não usa as chaves KV legacy como fonte operacional.

## Publicação

O lifecycle existente continua a ser usado: `draft` / `published`, `publication_version`, `published_fingerprint`, `published_at` e `published_by`.

Alterações nos campos de publicação de uma convocatória publicada devolvem o grupo a draft através de `ConvocationGroupPublicationObserver`.

Cada publicação fica também materializada em `sports_convocation_publications`, append-only por `convocation_group_id + version`, com snapshot do conteúdo técnico, destinatários e resultado da comunicação.

## Custos

A workspace reutiliza `SyncConvocationGroupFinancialMovementAction`; não cria um financeiro paralelo. O movimento mantém as proteções existentes contra alterações após liquidação, conciliação ou emissão documental.

## Compatibilidade

Até existir uma permissão desportiva dedicada para Convocatórias, a workspace utiliza as permissões já provisionadas de `eventos.convocatorias` dentro do módulo Desportivo.
