# Desportivo — Cais operacional

A PR6 transforma o Cais num runtime de execução de sessões, sem alterar o planeamento canónico.

## Princípios

- `trainings` continua a ser a única sessão de treino.
- O planeamento define grupos, pistas, local e conteúdo; o Cais executa e regista exceções.
- Ao abrir uma sessão, atletas atribuídos sem ausência previamente registada são assumidos presentes. O treinador classifica apenas exceções.
- Uma sessão pode coexistir com outras sessões abertas; não existe pista, série ou cronómetro global.
- Cronómetros pertencem a atleta ou subgrupo e exigem a identificação do exercício. Não existe limite funcional de cronómetros simultâneos.
- Medições ficam em `training_metrics`, ligadas ao atleta e à série/exercício. Distância total é obrigatória e os splits ficam preservados por medição.
- Em exercícios repetidos, a medição distingue `one_off` de uma repetição numerada.
- Notas, RPE, volume e estado são athlete-based.
- Mudanças operacionais de pista/grupo/local/horário usam `training_schedule_exceptions`; não reescrevem silenciosamente o planeamento.
- Sessões concluídas são imutáveis para o runtime do Cais.

## Offline e concorrência

Campos simples do atleta usam versão e timestamp. Um write offline mais antigo não sobrepõe o servidor; a divergência é guardada em `training_pool_deck_sync_conflicts` para revisão. Writes mais recentes seguem last-write-wins.

Métricas e eventos de cronómetro são append-only e aceitam `client_event_id`, tornando retries offline idempotentes. Cada write guarda o utilizador que o efetuou, permitindo vários treinadores na mesma sessão sem perder autoria.

## Cronómetros

`training_pool_deck_timers` guarda o estado derivado e `training_pool_deck_timer_events` mantém o histórico imutável `start/pause/resume/lap/stop`. A sessão não pode ser fechada enquanto existirem cronómetros `running` ou `paused`.

## Compatibilidade

A tabela legacy `training_athlete_cais_metrics` não é usada pelo novo runtime. `training_metrics` continua a manter os campos antigos (`metrica`, `valor`, `tempo`) como representação de compatibilidade enquanto passa a guardar também exercício, distância, repetição, duração em milissegundos e splits estruturados.

## UI

`/desportivo/cais` permanece a entrada operacional e deixa de depender do chrome global. O frontend carrega o workspace por `/desportivo/cais/runtime/workspace`, podendo apresentar várias sessões em simultâneo. A integração visual final do restante módulo continua fora desta PR.
