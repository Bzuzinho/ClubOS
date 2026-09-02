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

## 4. Auditoria operacional

`inventory:audit-store-logistics-stock` é read-only e distingue:

- cancelamento sem impacto de stock;
- cancelamento com saída e reposição integralmente equilibradas;
- saída cancelada ainda não reposta;
- reposição superior à saída;
- saídas ausentes, duplicadas ou com quantidade divergente;
- duplicação de saída entre Loja, fatura e requisição logística.
- fatura canónica corretamente ligada à encomenda;
- referência de fatura inexistente, origem/titular/valor divergente ou itens desalinhados;
- encomendas anteriores à H5b ainda sem fatura, classificadas explicitamente como legado e sem backfill automático.

Após cada deploy de `main`, a CI recolhe uma fotografia agregada de produção. O artifact não contém IDs de encomendas, produtos ou utilizadores. Nesta fase de readiness, dívida histórica é medida e preservada como evidência; não é corrigida nem escondida por um gate destrutivo.

## 5. Lacunas deliberadamente ainda abertas

H5b não apresenta como concluído o que pertence aos lotes seguintes:

- a Loja ainda não deriva estado de pagamento das alocações confirmadas;
- o pagamento da fatura já pode usar o `PaymentAllocationService`, mas a encomenda ainda não projeta automaticamente essa liquidação no seu próprio estado;
- o pedido fiscal manual nasce pelo fluxo financeiro canónico quando a fatura fica paga, mas a operação Loja→pagamento→estado ainda será fechada ponta a ponta em H5c;
- devolução posterior à entrega exige contrato financeiro/fiscal explícito antes da reposição de stock;
- a máquina de estados completa deve separar pagamento, preparação, levantamento/entrega, cancelamento e devolução.

## 6. Sequência dos próximos lotes

| Lote | Resultado verificável |
|---|---|
| H5b | Encomenda ligada idempotentemente a `Invoice`, com itens e centro de custo estruturados, sem segunda saída de stock. Implementado; falta evidência produtiva final. |
| H5c | Pagamento confirmado por `PaymentAllocationService` projeta o estado da encomenda e cria o pedido fiscal manual pelo fluxo canónico. |
| H5d | Cancelamento/devolução após efeitos financeiros usa reversão explícita e apenas depois repõe stock; sem apagar histórico. |
| H5e | Lifecycle interno de compras, requisições, entregas e empréstimos de Logística fechado com QA operacional e contratos Desportivo↔core preservados. |

Nenhum destes lotes pode introduzir custos do atleta em descrições textuais: atleta, invoice, centro de custo, evento e requisição mantêm ligações estruturadas e separadas da dívida.

## 7. Evidência produtiva H5a

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
