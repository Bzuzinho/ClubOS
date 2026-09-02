# H5 — Contrato ponta a ponta Loja + Logística

## 1. Fronteiras do domínio

- **Loja** é o canal real de compra para membros, atletas, encarregados de educação e restantes utilizadores autenticados.
- **Logística** gere internamente artigos, fornecedores, compras, requisições, empréstimos e movimentos de stock.
- `products`/`product_variants` formam o catálogo comum e `stock_movements` é o único ledger de stock.
- Loja e Logística podem partilhar catálogo e stock, mas não podem representar a mesma saída física duas vezes.
- Pagamento, alocação, reconciliação e pedido fiscal continuam sob os boundaries canónicos de Financeiro; o contrato fiscal produtivo mantém-se `manual_wintouch`.

## 2. Estado implementado em H5a

O checkout cria `loja_encomendas` e respetivos itens com snapshots de descrição, quantidade e preço. A saída física é registada imediatamente pelo `StockLedgerService`, com origem `store_order_item`, dentro da mesma transação da encomenda.

O cancelamento anterior à entrega passa a obedecer ao seguinte contrato:

1. a encomenda é bloqueada para atualização;
2. cada item é reconciliado com o respetivo movimento de saída;
3. uma saída única e coerente é compensada por um movimento `return` com a mesma origem;
4. produto e variante são repostos atomicamente pelo ledger;
5. só depois a encomenda transita para `cancelado`;
6. repetir o mesmo cancelamento não cria nova reposição;
7. uma encomenda cancelada é terminal e não pode ser reativada;
8. uma encomenda entregue também é terminal até existir o fluxo explícito de devolução H5d.

Se o histórico de stock estiver duplicado, desalinhado ou sobrecompensado, o cancelamento falha fechado e não altera nem o estado nem o stock. Encomendas sem impacto de stock — por exemplo, artigos sem tracking — podem ser canceladas sem criar movimentos artificiais.

Uma encomenda com fatura canónica ainda virgem pode ser cancelada: a fatura fica logicamente `cancelado`, com saldo em aberto zero, e só depois o stock é reposto. Se existir pagamento, alocação, conciliação, recibo ou pedido fiscal, o fluxo falha fechado; a reversão financeira/fiscal explícita pertence a H5d.

## 3. Estado implementado em H5b

A submissão de uma encomenda com valor positivo cria, na mesma transação, uma única `Invoice` canónica:

- `origem_tipo=store_order` e `origem_id=loja_encomendas.id`;
- `loja_encomendas.fatura_id` aponta para essa fatura;
- o titular é `target_user_id` quando a compra é para um perfil familiar e `user_id` nos restantes casos;
- tipo `material`, saldo integralmente em aberto e vencimento a 15 dias;
- itens copiados dos snapshots imutáveis da encomenda;
- centro de custo do titular resolvido pelo contrato canónico, apenas quando existe um centro único ou um peso máximo sem empate.

O índice parcial único sobre a origem `store_order`, em conjunto com o lock da encomenda, torna repetível a preparação financeira sem criar outra fatura ou outros itens. A fatura nasce oculta, recebe todos os itens e só depois é publicada, evitando comunicar uma obrigação incompleta.

A saída física continua a existir exclusivamente em `stock_movements` com origem `store_order_item`. Entregar uma encomenda já ligada à fatura deixa de criar o `Movement` de receita legado. Esse caminho permanece apenas para encomendas históricas sem `fatura_id`, sem alterar automaticamente o passado.

## 4. Estado implementado em H5c

A encomenda expõe um estado composto, sem duplicar a verdade financeira em `loja_encomendas`:

- `estado` continua exclusivamente logístico;
- `financeiro.estado_pagamento`, `valor_pago` e `valor_em_aberto` são derivados da `Invoice` ligada;
- `financeiro.estado_fiscal` é derivado do pedido fiscal dessa fatura;
- `PaymentAllocationService` continua a ser o único boundary que transforma alocações confirmadas em saldo parcial/pago e cria o pedido fiscal na transição para pago;
- `manual_wintouch` significa que o pedido fica para emissão externa e o ClubOS recebe depois o número/documento emitido.

Uma alocação parcial não cria pedido fiscal. Ao completar o valor, nasce um único pedido `receipt` para o provider `wintouch`; repetir o serviço canónico não duplica o pedido. A emissão manual atualiza a projeção para `emitido`. Nenhuma destas operações muda implicitamente o estado logístico, cria `Movement` de receita ou volta a baixar stock.

## 5. Auditoria operacional

`inventory:audit-store-logistics-stock` é read-only e distingue:

- cancelamento sem impacto de stock;
- cancelamento com saída e reposição integralmente equilibradas;
- saída cancelada ainda não reposta;
- reposição superior à saída;
- saídas ausentes, duplicadas ou com quantidade divergente;
- duplicação de saída entre Loja, fatura e requisição logística;
- fatura canónica corretamente ligada à encomenda;
- referência de fatura inexistente, origem/titular/valor divergente ou itens desalinhados;
- encomendas anteriores à H5b ainda sem fatura, classificadas explicitamente como legado e sem backfill automático.
- coerência entre saldo/estado da fatura e a soma das alocações confirmadas;
- faturas de Loja pagas com pedido fiscal Wintouch criado, ausente ou contratualmente divergente;
- pedidos fiscais criados antes da liquidação integral.

Após cada deploy de `main`, a CI recolhe uma fotografia agregada de produção. O artifact não contém IDs de encomendas, produtos ou utilizadores. Nesta fase de readiness, dívida histórica é medida e preservada como evidência; não é corrigida nem escondida por um gate destrutivo.

## 6. Lacunas deliberadamente ainda abertas

H5c não apresenta como concluído o que pertence aos lotes seguintes:

- devolução posterior à entrega exige contrato financeiro/fiscal explícito antes da reposição de stock;
- a reversão de uma encomenda paga ou com documento externo não pode apagar alocações nem identidade fiscal;
- o lifecycle interno de compras, requisições, entregas e empréstimos de Logística permanece em H5e.

## 7. Sequência dos próximos lotes

| Lote | Resultado verificável |
|---|---|
| H5b | Encomenda ligada idempotentemente a `Invoice`, com itens e centro de custo estruturados, sem segunda saída de stock. Concluído em produção. |
| H5c | Pagamento confirmado por `PaymentAllocationService` projeta o estado financeiro/fiscal da encomenda e cria o pedido fiscal manual pelo fluxo canónico. Implementado. |
| H5d | Cancelamento/devolução após efeitos financeiros usa reversão explícita e apenas depois repõe stock; sem apagar histórico. |
| H5e | Lifecycle interno de compras, requisições, entregas e empréstimos de Logística fechado com QA operacional e contratos Desportivo↔core preservados. |

Nenhum destes lotes pode introduzir custos do atleta em descrições textuais: atleta, invoice, centro de custo, evento e requisição mantêm ligações estruturadas e separadas da dívida.

## 8. Evidência produtiva H5a

PR #299 foi integrada no merge `7e43de1370b787965be6ce1a5714c9976a35b983`. CI #1079 validou a PR e CI #1080 validou `main`, PostgreSQL concorrente, browser QA multi-browser/mobile/acessibilidade, deploy e auditorias pós-deploy.

O artifact produtivo `store-logistics-lifecycle-readiness-7e43de1370b787965be6ce1a5714c9976a35b983` confirmou:

- schema Loja/Financeiro/Logística/stock completo;
- 1 encomenda e 1 item de Loja medidos;
- 0 cancelamentos desequilibrados;
- 0 saídas físicas ausentes ou duplicadas;
- 0 referências, produtos ou quantidades inválidas;
- 0 findings críticos, warnings ou ações pendentes;
- 1 histórico já corrigido pelo audit B1.4 e reconhecido como clean;
- `read_only=true` e `no_data_changed=true`.

O artifact contém apenas schema e contagens agregadas. Não contém IDs de encomendas, produtos ou utilizadores.

## 9. Evidência produtiva H5b

PR #301 foi integrada no merge `854624bc2f91829555f70c6dbff0a58a6f2c8067`. CI #1084 validou a PR e CI #1085 repetiu todos os gates de `main`, aplicou a migration, fez deploy na Oracle VM e recolheu a auditoria H5b.

O artifact produtivo `store-logistics-lifecycle-readiness-854624bc2f91829555f70c6dbff0a58a6f2c8067` (ID `9858357355`, digest `sha256:b94629d7dc35e59c5c292f95409aabbe80c2ff76d890977c15deaefcbb94395c`) confirmou:

- uma encomenda e um item históricos, explicitamente classificados como legado sem backfill;
- zero referências de fatura inválidas ou em falta perante uma origem canónica já existente;
- zero divergências de origem, titular, valor ou itens;
- zero saídas físicas ausentes ou duplicadas;
- zero findings críticos, warnings ou ações pendentes;
- `canonical_store_invoice_contract_active=true`, `read_only=true` e `no_data_changed=true`.

O valor `canonical_invoice_linked_count=0` é esperado nesta fotografia: não foi criada uma encomenda real em produção apenas para testar o lote. A criação, repetição idempotente, compra familiar com centro de custo, cancelamento virgem, bloqueio com rasto financeiro, liquidação pelo `PaymentAllocationService` e ausência de `Movement`/segunda saída estão cobertos na suite bloqueante.

## 10. Evidência H5c

`StoreOrderPaymentFiscalProjectionTest` cobre o percurso completo sobre uma encomenda real de teste: checkout, projeção pendente, pagamento parcial, liquidação total por duas alocações confirmadas, criação de um único pedido Wintouch, registo manual do documento externo e leitura atualizada nas APIs Loja/Admin. O mesmo teste prova que o estado logístico não muda, existe uma única saída `store_order_item` e não nasce qualquer `Movement`.

O audit `h5c-store-payment-fiscal-audit-v4` será recolhido de forma agregada no primeiro deploy de `main` que contenha H5c; SHA, CI e métricas produtivas serão anexados no fecho operacional.
