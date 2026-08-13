# Desportivo — Treinos funcional

Data: 2026-08-13
Status: implementação funcional após Estrutura, Planeamento e Biblioteca

## Responsabilidade

Treinos é a workspace operacional das **sessões concretas** já existentes em `trainings`.

Fronteiras obrigatórias:

- **Planeamento** decide quando, para quem, onde e com que conteúdo a sessão acontece;
- **Biblioteca** cria e versiona conteúdo técnico reutilizável;
- **Treinos** verifica prontidão, expõe o snapshot aplicado, gere lifecycle pré-execução e entrega a sessão ao Cais;
- **Cais** regista presença, logística e exceções durante a execução;
- **Monitorização** mede a execução e tempos.

Treinos não contém um segundo builder de Biblioteca, não cria recorrências, não gere periodização e não regista assiduidade.

## Fonte de verdade

`trainings` continua a ser a única entidade canónica de ocorrência.

A workspace lista apenas sessões com data. Registos legacy sem data não são tratados como biblioteca nem como sessão operacional.

A leitura é tenant-scoped por `club_id` e reutiliza as relações canónicas:

- `training_plan_version_id` e snapshot em `training_series`;
- `training_session_groups` e pistas canónicas;
- `training_athletes` como roster preparado;
- Local → Piscina/Área → Pista;
- recorrência e chave da ocorrência;
- contexto Época → Macro → Meso → Micro;
- `schedule_conflicts_snapshot` e `schedule_review_required`.

## Readiness

Prontidão é **derivada**, não é um novo estado de negócio.

Estados de apresentação:

- `ready`: sessão operacionalmente pronta;
- `attention`: falta informação não bloqueante, está em draft ou existe versão mais recente de plano;
- `decision`: conflito blocker/decision_required, nomeadamente encerramento de recurso;
- `closed`: sessão concluída ou cancelada.

Verificações:

1. conteúdo técnico;
2. participantes preparados;
3. treinador responsável;
4. local + piscina/área;
5. pistas dos grupos;
6. conflitos/encerramentos;
7. versão do plano;
8. publicação.

Uma nova versão da Biblioteca nunca é aplicada silenciosamente. A ocorrência mantém o seu snapshot até decisão explícita.

## Lifecycle de cancelamento

Cancelamento não apaga a sessão.

Uma sessão cancelada guarda:

- `session_status = cancelled`;
- `cancelled_at`;
- `cancelled_by`;
- `cancellation_reason` obrigatório.

Sessões canceladas passam a ser fechadas para alterações de Planeamento, grupos, plano e conclusão, preservando o histórico tal como uma sessão concluída.

## Atualização explícita de versão

Treinos pode aplicar uma versão mais recente **apenas à ocorrência aberta**.

A versão tem de pertencer ao mesmo `training_plan` já aplicado. A operação usa `TrainingSessionPlanService`, substitui o snapshot em `training_series` e grava revisão auditável.

Atualizações em lote de sessões futuras continuam a pertencer ao fluxo explícito já existente e nunca decorrem automaticamente da criação de uma nova versão.

## Adaptação apenas desta sessão

O treinador pode adaptar o snapshot técnico da ocorrência sem alterar a Biblioteca.

A adaptação:

- exige motivo;
- preserva bloco, rondas, repetições, distância unitária, zona, estilo, saída, descanso, material e `timing_mode`;
- mantém `8×50` como oito repetições de 50 m;
- recalcula `volume_planeado_m`, incluindo rondas do bloco;
- grava `source = session_override` nas séries;
- mantém a referência da versão de plano de origem na sessão;
- cria entrada em `training_session_content_revisions` com snapshots before/after.

`saida` continua a significar send-off (`@1:30`) e `intervalo` descanso (`c/15"`).

## Auditoria

`training_session_content_revisions` regista:

- aplicação explícita de outra versão;
- adaptação exclusiva da ocorrência;
- versão de origem quando aplicável;
- motivo;
- snapshot anterior e posterior;
- autor e momento.

A linha temporal da workspace combina esta auditoria com aplicação de plano, cancelamento, conclusão e `training_schedule_exceptions`.

## Handoffs

### Editar planeamento

Alterações de data, hora, contexto de periodização, grupos, treinador, local, piscina e pistas pertencem ao Planeamento.

### Abrir no Cais

A sessão é aberta diretamente no Cais por `training_id`. O Cais recebe o roster e o snapshot já preparados.

Presenças não são alteradas em Treinos.

### Alterações durante a sessão

Mudanças operacionais durante a execução continuam a ser registadas em `training_schedule_exceptions`; Treinos não reescreve retroativamente o planeamento histórico.

## Compatibilidade

A estratégia é expand-first:

- nenhuma sessão histórica é apagada;
- a UI legacy `DesportivoTreinosTab` pode permanecer temporariamente no código, mas `/desportivo/treinos` passa a usar a workspace canónica própria;
- o endpoint canónico é servido depois das rotas legacy, seguindo o mesmo padrão de cutover de Planeamento e Biblioteca.
