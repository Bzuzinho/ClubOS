# Comunicação — pipeline assíncrono persistente

## 1. Fonte de verdade

O pipeline reutiliza o domínio já existente. `communication_campaigns` é a outbox canónica; `communication_deliveries` fixa uma execução por campanha/canal; `communication_delivery_recipients` fixa o destinatário e o contacto usados nessa execução; `communication_delivery_attempts` conserva cada tentativa.

Não existe uma segunda fila funcional em tabelas paralelas. Redis transporta jobs, mas o estado de negócio permanece em PostgreSQL. Perder ou repetir um job não pode apagar o histórico nem criar outra entrega lógica.

## 2. H6a — fundação implementada

- campanhas automáticas podem guardar `source_type`, `source_id` e `idempotency_key` estruturados;
- uma campanha/canal cria uma única entrega através de chave determinística;
- cada destinatário tem uma chave determinística, máximo de três tentativas e lease de processamento;
- cada tentativa guarda estado, provider, ID externo quando disponível, erro normalizado e próxima retentativa;
- email usa `Message-ID` determinístico e SMS envia `Idempotency-Key` ao provider HTTP;
- alertas internos guardam o ID real do `InAppAlert` como referência do provider;
- push falha fechado enquanto não existir provider real, em vez de simular sucesso por existir um token;
- `communication:dispatch-due` enfileira campanhas agendadas vencidas, retentativas vencidas e leases abandonadas;
- o scheduler executa o dispatcher a cada minuto com exclusão mútua;
- a ligação Redis de queue passa a existir na configuração Laravel e usa `after_commit=true`;
- a vista de Execução apresenta tentativas, destinatários em retry e tentativas esgotadas;
- `communication:audit-async-pipeline` mede schema, legado, backlog, retries, referências de provider e anomalias sem escrever dados.

## 3. Semântica de estados

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

## 4. Idempotência e limites

As chaves são aplicadas em três níveis:

1. origem funcional → campanha;
2. campanha + canal → entrega;
3. entrega + identidade do destinatário → destinatário.

Uma repetição após sucesso é neutra. SMS recebe a chave no pedido externo e email conserva um `Message-ID` estável. Ainda assim, nenhum sistema distribuído pode prometer exactly-once depois de o provider aceitar uma mensagem e antes de o ClubOS persistir a resposta; o contrato é at-least-once com deduplicação local e chave propagada ao provider.

O lote não faz backfill das campanhas e entregas históricas. Linhas anteriores ficam classificadas como legado pelo audit e continuam legíveis.

## 5. Próximos lotes H6

- H6b: mover as automações síncronas ainda existentes para a outbox assíncrona sem alterar preferências nem origens;
- H6c: adapters explícitos de email/SMS/push e webhooks autenticados para estados `delivered`, `failed` e `read`;
- H6d: integrar Redes como provider adicional desta infraestrutura, mantendo Website independente e usando apenas APIs oficiais ou exportações autorizadas;
- H6e: QA operacional profundo, métricas/SLA e fecho produtivo do módulo.

Uma futura integração Facebook/Instagram não pode publicar diretamente a partir de controllers nem criar outra tabela de campanhas. Deve entrar por este pipeline e guardar o identificador devolvido pela API oficial.
