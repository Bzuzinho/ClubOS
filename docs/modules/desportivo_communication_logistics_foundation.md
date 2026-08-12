# Desportivo F6 — Comunicação / Logística contracts

Date: 2026-08-12

## Comunicação

A convocatória canónica pode ser preparada e editada sem qualquer envio. O envio passa a existir apenas na ação explícita de publicação.

Fluxo:

`ConvocationGroup -> SportsConvocationPublicationService -> SportsCommunicationGateway -> SportsCommunicationIntent -> Comunicação/Campaign`

Regras:

- criar ou editar `ConvocationGroup` não envia email/alerta;
- a publicação é versionada;
- a mesma versão usa uma chave idempotente única e não cria uma segunda campanha;
- alterações relevantes depois de uma publicação voltam o grupo a `draft` e incrementam a versão;
- mudanças no Event master são incorporadas no fingerprint e geram nova versão na publicação seguinte;
- canais, templates e preferências continuam exclusivamente no módulo Comunicação;
- falha ou supressão do envio fica registada na intent e não reverte/corrompe a convocatória;
- o observer legacy de `EventConvocation` só mantém compatibilidade para registos diretos não geridos por um `ConvocationGroup` canónico.

## Logística

O Desportivo não possui stock nem empréstimos de material do clube.

Fluxos permitidos:

- consulta read-only de disponibilidade através de `SportsLogisticsGateway`;
- pedido explícito de material através do mesmo contrato, materializado como `LogisticsRequest` pelo módulo Logística;
- `source_type`, `source_id` e `idempotency_key` identificam pedidos originados noutro domínio sem criar um segundo lifecycle.

Regras:

- consultar disponibilidade não altera stock;
- criar requisição não cria `StockMovement` nem reduz stock;
- aprovação/entrega/empréstimo e respetivas saídas/retornos continuam a pertencer à Logística/Inventário;
- apenas artigos ativos e `allow_request=true` podem ser requisitados por este contrato;
- retries com a mesma origem/versão reutilizam a mesma `LogisticsRequest`.

## Guard rails F6

Services do Desportivo não podem importar persistência de Comunicação (`CommunicationCampaign`, `CommunicationDelivery`, templates/segments/alerts) nem persistência de Logística/Inventário (`Product`, `StockMovement`, `LogisticsRequest`, `EquipmentLoan`) ou serviços concretos desses módulos. A integração passa pelos contratos em `App\Contracts`.
