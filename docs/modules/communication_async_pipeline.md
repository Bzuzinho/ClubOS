# Comunicação — pipeline assíncrono persistente

## 1. Fonte de verdade

O pipeline reutiliza o domínio já existente. `communication_campaigns` é a outbox canónica; `communication_deliveries` fixa uma execução por campanha/canal; `communication_delivery_recipients` fixa o destinatário e o contacto usados nessa execução; `communication_delivery_attempts` conserva cada tentativa.

Não existe uma segunda fila funcional em tabelas paralelas. A queue Laravel transporta jobs e pode usar `database` ou Redis, mas o estado de negócio permanece sempre nas tabelas canónicas PostgreSQL. Perder ou repetir um job não pode apagar o histórico nem criar outra entrega lógica.

## 2. H6a — fundação implementada

- campanhas automáticas podem guardar `source_type`, `source_id` e `idempotency_key` estruturados;
- uma campanha/canal cria uma única entrega através de chave determinística;
- cada destinatário tem uma chave determinística, máximo de três tentativas e lease de processamento;
- cada tentativa guarda estado, provider, ID externo quando disponível, erro normalizado e próxima retentativa;
- email usa `Message-ID` determinístico e SMS envia `Idempotency-Key` ao provider HTTP;
- alertas internos guardam o ID real do `InAppAlert` como referência do provider;
- push falha fechado enquanto não existir provider real, em vez de simular sucesso por existir um token;
- `communication:dispatch-due` enfileira campanhas H6a agendadas vencidas, retentativas vencidas e leases abandonadas;
- campanhas históricas sem `idempotency_key` nunca são disparadas automaticamente; o audit assinala-as para revisão explícita;
- o scheduler executa o dispatcher a cada minuto com exclusão mútua;
- a ligação Redis de queue passa a existir na configuração Laravel e usa `after_commit=true`; o transporte ativo continua dependente do ambiente;
- a vista de Execução apresenta tentativas, destinatários em retry e tentativas esgotadas;
- `communication:audit-async-pipeline` mede schema, legado, backlog, retries, referências de provider e anomalias sem escrever dados.

## 3. H6b — cutover das automações

As automações de faturas, movimentos, convocatórias de Eventos, requisições e compras de Logística, bem como o gateway de publicação Desportivo, deixam de executar entregas no processo que originou o evento. O processo funcional apenas avalia as preferências, fixa destinatários/canais numa campanha idempotente e publica `ProcessCommunicationCampaignJob` depois do commit.

A campanha é escrita na mesma transação do evento de negócio quando o produtor já está dentro de uma transação. Se essa transação fizer rollback, campanha e job são descartados em conjunto. Preferências desligadas continuam a impedir a criação da campanha; preferências ativas ficam materializadas nos canais da campanha antes de esta entrar na queue.

O dispatcher recupera também campanhas automáticas sem qualquer entrega quando ficaram em `rascunho` sem pedido de dispatch ou em `em_processamento` há mais de dez minutos. Esta recuperação usa a mesma chave da campanha e o job único, sem criar outra entrega lógica.

O envio individual explícito da interface mantém execução direta; este lote altera apenas produtores automáticos e o gateway Desportivo. Mensagens internas e alertas operacionais específicos que não usam `CommunicationAutomationService` conservam o seu lifecycle próprio.

## 4. Semântica de estados

| Agregado | Estado | Significado |
|---|---|---|
| Campanha | `agendada` | Aguarda `scheduled_at`; ainda não foi entregue à queue. |
| Campanha | `em_processamento` | Foi enfileirada ou tem destinatários com retry pendente. |
| Campanha | `enviada` | Terminou com pelo menos uma entrega concluída; falhas parciais ficam visíveis nas entregas. |
| Campanha | `falhada` | Terminou sem qualquer entrega bem-sucedida ou o job esgotou tentativas por exceção técnica. |
| Entrega | `processing` | Existem destinatários pendentes ou retentativas futuras. |
| Entrega | `completed` | Todos os destinatários terminaram com sucesso. |
| Entrega | `partial` | Existem sucessos e falhas terminais. |
| Entrega | `failed` | Não existem sucessos e as falhas são terminais. |

Falhas transitórias do destinatário usam backoff de 1 e 5 minutos entre um máximo de três tentativas; a terceira falha esgota o destinatário. Exceções do job usam backoff de 1, 5 e 15 minutos. Um lease sem conclusão há dez minutos volta a ser elegível, preservando a tentativa anterior para auditoria.

## 5. Idempotência e limites

As chaves são aplicadas em três níveis:

1. origem funcional → campanha;
2. campanha + canal → entrega;
3. entrega + identidade do destinatário → destinatário.

Uma repetição após sucesso é neutra. SMS recebe a chave no pedido externo e email conserva um `Message-ID` estável. Ainda assim, nenhum sistema distribuído pode prometer exactly-once depois de o provider aceitar uma mensagem e antes de o ClubOS persistir a resposta; o contrato é at-least-once com deduplicação local e chave propagada ao provider.

O lote não faz backfill das campanhas e entregas históricas. Linhas anteriores ficam classificadas como legado pelo audit e continuam legíveis. Uma campanha histórica agendada e vencida exige revisão e reagendamento explícito, que lhe atribui uma chave H6a; esta barreira impede o deploy de enviar mensagens antigas inadvertidamente.

## 6. Próximos lotes H6

- H6c: adapters explícitos de email/SMS/push e webhooks autenticados para estados `delivered`, `failed` e `read`;
- H6d: integrar Redes como provider adicional desta infraestrutura, mantendo Website independente e usando apenas APIs oficiais ou exportações autorizadas;
- H6e: QA operacional profundo, métricas/SLA e fecho produtivo do módulo.

Uma futura integração Facebook/Instagram não pode publicar diretamente a partir de controllers nem criar outra tabela de campanhas. Deve entrar por este pipeline e guardar o identificador devolvido pela API oficial.

## 7. Evidência produtiva H6a

O deploy do merge `da108ba6d4df33a216c5584ce28f8a3fe939ce67` aplicou a migration e emitiu o sinal de reinício da queue. O audit produtivo confirmou o schema completo, zero críticos, zero retries vencidos/em espera, zero destinatários esgotados e zero leases abandonadas. A queue ativa nesse ambiente é `database`; a ligação Redis está definida, mas um eventual cutover deve validar primeiro o processo Supervisor e não é pressuposto por este lote.

Existem 65 campanhas, 123 entregas e 7991 destinatários históricos classificados como legado, sem backfill. Uma das campanhas históricas está agendada e vencida: conta como uma ação operacional, mas permanece bloqueada contra disparo automático até revisão e reagendamento explícitos.

## 8. Evidência produtiva H6b

PR #311 foi validada pela CI #1109 e integrada no merge `407ee6f825bbdadba27b6d02f95d2bba18a802c8`. A CI #1110 repetiu Laravel, PostgreSQL concorrente e browser QA no commit de `main`, fez deploy na Oracle VM e recolheu o artifact `communication-async-pipeline-readiness-407ee6f825bbdadba27b6d02f95d2bba18a802c8` (ID `9897116395`, `sha256:e947ad5bac55e2f8544347b21d4952189e2061312d6a019aca3ccbb49a3f9cf2`).

O audit `h6b-communication-automation-cutover-audit-v2` confirmou schema pronto, queue ativa `database`, zero campanhas automáticas sem pedido de dispatch, zero outbox automática por recuperar, zero retries, esgotamentos ou leases abandonadas e zero críticos. Não existiam ainda campanhas automáticas H6b no instante do deploy; as 65 campanhas, 123 entregas e 7991 destinatários permanecem classificados como legado e não foram alterados. A única campanha legacy agendada continua excluída do dispatcher automático e requer revisão explícita.
