# Contrato operacional fiscal produtivo

## Decisão H4

O modo fiscal produtivo do ClubOS é `manual_wintouch`.

O ClubOS não emite documentos dentro do Wintouch. O operador emite o documento no software certificado e confirma depois no ClubOS o número, a série, a data e, quando aplicável, os restantes identificadores externos. Esta decisão é deliberada: a integração disponibilizada para o Wintouch é uma DLL/.NET orientada a Windows e não constitui uma API HTTP confirmada que possa ser executada com segurança pelo Laravel/Linux.

Não existe chamada à DLL a partir do ClubOS, instalação de runtime Windows no servidor ou simulação de provider remoto.

## Fontes de verdade e sequência

1. `Invoice`, `Payment` e `PaymentAllocation` mantêm o estado financeiro canónico.
2. Uma fatura apenas gera pedido fiscal quando fica totalmente paga.
3. `FiscalDocumentRequest` representa a obrigação operacional de emissão e conserva o snapshot fiscal do membro.
4. O pagamento não recebe nem exige `numero_recibo`.
5. O operador emite no Wintouch e usa `Tratar manualmente` ou `finance:record-external-fiscal-receipt` para confirmar no ClubOS o documento externo já emitido.
6. A confirmação atualiza `FiscalDocumentRequest` e `Invoice.numero_recibo` na mesma transação.

O pedido usa `MemberFiscalDataResolver`: `dados_pessoais` é a fonte primária e `users` permanece apenas como fallback compatível.

## Regras irreversíveis

- Sem número ou ID externo, o pedido ainda pode ser eliminado e a reversão do pagamento remove-o por soft delete.
- Com número ou ID externo, o pedido não pode ser eliminado e o pagamento/fatura não pode ser reaberto diretamente.
- Cancelar/anular exige documento externo registado, preserva a sua identidade e exige motivo.
- Campos estruturais e identidade documental nunca são alterados pelo endpoint genérico de update.
- Pedidos são idempotentes por fatura, provider e tipo documental enquanto estiverem ativos.
- Pagamentos com várias alocações não fundem as obrigações fiscais de faturas diferentes.

## Fronteira de automação

`FISCAL_OPERATION_MODE=manual_wintouch` e `FISCAL_PROVIDER=wintouch` são os defaults explícitos. Mesmo que um adapter seja registado acidentalmente, `finance:process-fiscal-document-requests --apply` recusa a emissão automática neste modo.

Uma futura integração só pode ativar `provider_api` num lote próprio que inclua:

- API HTTP estável e documentada;
- adapter que implemente `FiscalDocumentProviderAdapter`;
- gestão de credenciais e timeouts fora do domínio do browser;
- idempotency key e reconciliação de respostas ambíguas;
- testes de contrato, segurança e rollback;
- mudança deliberada da configuração, do gate produtivo e deste documento.

## Operação diária

- A fila `Emissão Fiscal` lista pedidos pendentes, em tratamento, emitidos, com erro e cancelados.
- `Tratar manualmente` é a ação produtiva normal após emissão externa no Wintouch.
- `finance:preflight-fiscal-document-issue` valida readiness sem escrever.
- `finance:record-external-fiscal-receipt` suporta registo manual controlado, dry-run e confirmação explícita.
- `finance:audit-fiscal-documents` investiga cadeias, diferenças e anomalias.
- `finance:audit-fiscal-operational-readiness` produz apenas contagens agregadas, não altera dados e bloqueia o fecho produtivo se configuração, schema, rotas ou findings críticos violarem este contrato.

Warnings e pedidos pendentes representam trabalho operacional da fila; findings críticos representam incoerência de integridade e bloqueiam o gate.

## Rollback

Este lote não cria migration nem altera dados. O rollback é a reversão do commit. Pedidos e documentos existentes permanecem intactos.
