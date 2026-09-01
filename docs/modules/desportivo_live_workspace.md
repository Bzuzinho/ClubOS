# Desportivo — Live / Monitorização fina

## Fronteira funcional

O Cais continua responsável pela operação global dos treinos em curso: presença, logística, material, ocorrências e comportamento. O Live não replica esses fluxos. O Live acompanha a execução desportiva fina de uma sessão concreta.

## Workspace

A workspace `/desportivo/live` mostra os treinos operacionais da data, o treino completo e apenas os atletas cujo `training_athletes.presente` é verdadeiro. Pode receber `training_id`, `training_series_id` e `athlete_ids` como contexto vindo do Cais sem iniciar automaticamente qualquer cronómetro.

O fluxo planeado é atleta(s) → linha → START. As linhas permanecem bloqueadas até existir pelo menos um atleta selecionado. O START cria uma monitorização e uma medição temporal comum. Cada atleta recebe passagens e STOP individuais; em `each_rep`, a ação principal regista diretamente o tempo da repetição e conserva `distancia_m` como distância unitária. STOP GERAL termina apenas os atletas ainda ativos. Uma monitorização pode ser minimizada no cliente sem alterar o runtime e várias monitorizações podem coexistir, desde que um atleta não pertença a duas monitorizações ativas. A faixa fixa de monitorizações permite acompanhar e alternar entre cronómetros independentes.

## Runtime temporal

O runtime usa `sports_live_monitorings`, `sports_live_monitoring_athletes`, `sports_live_measurements`, `sports_live_measurement_athletes` e `sports_live_measurement_events`. START/SPLIT/STOP são eventos imutáveis e recebem `client_event_id` para idempotência. `elapsed_ms` é calculado no dispositivo para não incorporar latência de rede no tempo do atleta.

Para `each_rep`, cada repetição cria uma medição nova e preserva `repeticoes` e `distancia_m` separadamente: a primeira chegada de uma linha `4×50` fica identificada como primeira repetição de `50 m`, nunca como `200 m`. Quando todos os atletas da medição terminam, o backend fecha-a e inicia automaticamente a repetição seguinte; depois da última repetição avança para a próxima linha cronometrada e, no fim do treino temporal, conclui a monitorização e liberta os atletas.

A progressão é serializada por monitorização e medição. Chegadas concorrentes não podem criar duas medições seguintes nem saltar repetições; reenvios com o mesmo `client_event_id` devolvem o estado corrente. Splits e STOP não aceitam um `elapsed_ms` anterior ao último evento do atleta. O payload mantém as medições concluídas, distância unitária, repetição, série e tempos individuais para a UI conservar o histórico durante a progressão.

## Medição livre

Medição livre inicia imediatamente para um atleta e não exige linha. Depois do STOP fica por classificar. A classificação exige distância total e estilo. A distância por segmento é `distância total / (splits + chegada final)`. Resultados classificados ficam preservados mesmo que a monitorização seja depois cancelada.

## Métricas Live

O catálogo `sports_live_metric_definitions` é independente das métricas operacionais do Cais e é gerido em Configuração Desportiva → Métricas Live. Definições usadas historicamente são arquivadas em vez de destruídas; código, tipo e unidade ficam estáveis depois de existir histórico.

Cada Guardar cria um novo `sports_live_metric_records`. O histórico preserva treino, atleta, exercício opcional, definição, valor, unidade snapshot, nota, hora e autor. O popup Live permite consultar o histórico da métrica para aquele atleta dentro do treino atual, criando uma base canónica para estatísticas futuras por treino, exercício, atleta e período.
