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

## 4. H6c — adapters e lifecycle dos providers

Email, SMS e push usam adapters explícitos que devolvem o mesmo contrato: sucesso, provider, identificador externo e erro normalizado. SMS e push propagam a chave idempotente no header `Idempotency-Key`; uma resposta HTTP de sucesso sem identificador da mensagem é tratada como falha, porque não permitiria correlacionar callbacks nem provar a entrega. Push continua fail-closed enquanto endpoint, token e destinatários não estiverem configurados.

Os providers podem comunicar `delivered`, `failed` e `read` em `POST /api/webhooks/communication/{provider}`. O endpoint aceita apenas `email`, `sms` e `push`, exige os headers `X-ClubOS-Timestamp` e `X-ClubOS-Signature`, valida HMAC-SHA256 sobre `timestamp.payload`, rejeita mensagens expiradas e aplica throttling. Se o secret do provider não existir, responde `503` em vez de aceitar callbacks sem autenticação.

`communication_provider_events` conserva a identidade externa, tipo, timestamps, hash canónico do payload, correlação e resultado de processamento. O payload bruto não é guardado. A chave `provider + external_event_id` torna retries idempotentes; reutilizar a mesma identidade com conteúdo diferente responde `409`. Eventos ainda sem destinatário ficam `unmatched` e visíveis no audit para reconciliação, sem inventar uma entrega.

As transições são monotónicas: `read` implica `delivered`; eventos repetidos não escrevem novamente; `failed` nunca faz recuar um destinatário já `delivered` ou `read`. Depois de uma transição válida, os agregados entrega e campanha são recalculados pelo mesmo pipeline canónico. A ativação real de cada webhook depende do respetivo secret e da configuração no fornecedor; o código não cria credenciais nem presume providers ativos.

## 5. Semântica de estados

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

## 6. Idempotência e limites

As chaves são aplicadas em três níveis:

1. origem funcional → campanha;
2. campanha + canal → entrega;
3. entrega + identidade do destinatário → destinatário.

Uma repetição após sucesso é neutra. SMS recebe a chave no pedido externo e email conserva um `Message-ID` estável. Ainda assim, nenhum sistema distribuído pode prometer exactly-once depois de o provider aceitar uma mensagem e antes de o ClubOS persistir a resposta; o contrato é at-least-once com deduplicação local e chave propagada ao provider.

O lote não faz backfill das campanhas e entregas históricas. Linhas anteriores ficam classificadas como legado pelo audit e continuam legíveis. Uma campanha histórica agendada e vencida exige revisão e reagendamento explícito, que lhe atribui uma chave H6a; esta barreira impede o deploy de enviar mensagens antigas inadvertidamente.

## 7. H6d — publicação em redes sociais

Facebook e Instagram entram como canais explícitos da mesma `communication_campaign`. O separador `Comunicação > Redes` cria publicações imediatas ou agendadas, mas não envia a partir do controller: materializa o segmento técnico, os canais e a campanha canónica, entregando depois o trabalho ao `ProcessCommunicationCampaignJob`. Entregas, destinatário social, tentativas, backoff, identificador externo e consolidação de estado reutilizam integralmente H6a–H6c.

As credenciais são geridas em `Definições > Notificações > Redes sociais`. `access_token`, `app_secret` e `webhook_verify_token` usam casts cifrados Laravel, nunca são devolvidos ao frontend e um campo secreto vazio preserva o valor existente. Cada rede só fica pronta com ativação explícita, ID externo e access token; o botão de validação consulta a Graph API e confirma que o ID devolvido coincide com a conta configurada.

Facebook publica texto e ligação através do edge oficial `/{page-id}/feed`. Instagram executa o fluxo oficial em duas fases `/{ig-user-id}/media` e `/{ig-user-id}/media_publish`, exigindo uma URL HTTPS pública para a imagem. A versão Graph API é configurável por conta. Ambos os adapters exigem um ID externo no sucesso e falham fechados quando a conta está ausente ou incompleta.

Os callbacks `GET|POST /api/webhooks/meta/{provider}` implementam challenge/verify token e `X-Hub-Signature-256`. O payload bruto não é persistido: `social_network_events` guarda apenas campos mínimos, hash, identidade externa, correlação e resultado. Eventos de lifecycle que tragam ID e estado normalizável convergem no mesmo `CommunicationProviderEventService`; duplicados são neutros. O Website permanece independente e não conhece tokens, campanhas ou chamadas Meta.

## 8. Próximos lotes H6

- H6e: QA operacional profundo, métricas/SLA e fecho produtivo do módulo.

## 9. Evidência produtiva H6a

O deploy do merge `da108ba6d4df33a216c5584ce28f8a3fe939ce67` aplicou a migration e emitiu o sinal de reinício da queue. O audit produtivo confirmou o schema completo, zero críticos, zero retries vencidos/em espera, zero destinatários esgotados e zero leases abandonadas. A queue ativa nesse ambiente é `database`; a ligação Redis está definida, mas um eventual cutover deve validar primeiro o processo Supervisor e não é pressuposto por este lote.

Existem 65 campanhas, 123 entregas e 7991 destinatários históricos classificados como legado, sem backfill. Uma das campanhas históricas está agendada e vencida: conta como uma ação operacional, mas permanece bloqueada contra disparo automático até revisão e reagendamento explícitos.

## 10. Evidência produtiva H6b

PR #311 foi validada pela CI #1109 e integrada no merge `407ee6f825bbdadba27b6d02f95d2bba18a802c8`. A CI #1110 repetiu Laravel, PostgreSQL concorrente e browser QA no commit de `main`, fez deploy na Oracle VM e recolheu o artifact `communication-async-pipeline-readiness-407ee6f825bbdadba27b6d02f95d2bba18a802c8` (ID `9897116395`, `sha256:e947ad5bac55e2f8544347b21d4952189e2061312d6a019aca3ccbb49a3f9cf2`).

O audit `h6b-communication-automation-cutover-audit-v2` confirmou schema pronto, queue ativa `database`, zero campanhas automáticas sem pedido de dispatch, zero outbox automática por recuperar, zero retries, esgotamentos ou leases abandonadas e zero críticos. Não existiam ainda campanhas automáticas H6b no instante do deploy; as 65 campanhas, 123 entregas e 7991 destinatários permanecem classificados como legado e não foram alterados. A única campanha legacy agendada continua excluída do dispatcher automático e requer revisão explícita.

## 11. Evidência produtiva H6c

PR #313 foi validada pela CI #1113 e integrada no merge `6cd2103e0897ac8f7909676e9e728e134826e709`. A CI #1114 repetiu Laravel, PostgreSQL concorrente e browser QA, aplicou a migration na Oracle VM e recolheu o artifact `communication-async-pipeline-readiness-6cd2103e0897ac8f7909676e9e728e134826e709` (ID `9900110232`, `sha256:84ff2204e3459f6b6da72080bfe4c38022e090b4aaabf36a1ed8300db4b04283`).

O audit `h6c-communication-provider-lifecycle-audit-v3` confirmou as cinco tabelas e todos os campos esperados, zero eventos aplicados, ignorados ou sem correlação, zero críticos e `no_data_changed=true`. Os secrets de webhook para email, SMS e push estão ausentes no ambiente produtivo; por desenho, os três endpoints respondem fail-closed até a configuração ser feita em simultâneo no ClubOS e no provider. O histórico permanece em 65 campanhas, 123 entregas e 7991 destinatários legacy, com a única agenda legacy excluída do dispatcher automático.

## 12. Evidência produtiva H6d

PR #315 foi validada pela CI #1117 e integrada no merge `cbc879eb320a42a691b3fd1c1a14be50b2810c00`. A CI #1118 repetiu PostgreSQL concorrente, Laravel, frontend, TypeScript, build e browser QA multi-browser/mobile, concluiu o deploy na Oracle VM e recolheu o artifact `communication-async-pipeline-readiness-cbc879eb320a42a691b3fd1c1a14be50b2810c00` (ID `9903209342`, `sha256:775cc80bdf23f167fda7063f573e53b155597101cd759d1a570548008039b649`).

O audit `h6d-social-network-publishing-audit-v4` confirmou as sete tabelas, todos os campos sociais e todas as interpretações de segurança e fronteira, com `schema_ready=true`, `critical_count=0` e `no_data_changed=true`. A queue produtiva permanece `database`. Antes da ativação operacional existem zero contas sociais, zero contas prontas, zero campanhas sociais e zero eventos sociais; este estado é deliberado e mantém publicação e callbacks fail-closed até serem adicionadas e validadas as credenciais Meta nas Definições.

O histórico mantém 65 campanhas, 123 entregas e 7991 destinatários legacy sem backfill. A única campanha legacy agendada continua excluída do dispatcher automático e explica o único warning/ação do audit; requer revisão explícita, sem relação com a prontidão H6d.
