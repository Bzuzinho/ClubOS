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

Uma encomenda que já tenha `fatura_id` também não pode atravessar este cancelamento stock-only. A reversão financeira/fiscal explícita pertence a H5d e tem de ocorrer antes da reposição.

## 3. Auditoria operacional

`inventory:audit-store-logistics-stock` é read-only e distingue:

- cancelamento sem impacto de stock;
- cancelamento com saída e reposição integralmente equilibradas;
- saída cancelada ainda não reposta;
- reposição superior à saída;
- saídas ausentes, duplicadas ou com quantidade divergente;
- duplicação de saída entre Loja, fatura e requisição logística.

Após cada deploy de `main`, a CI recolhe uma fotografia agregada de produção. O artifact não contém IDs de encomendas, produtos ou utilizadores. Nesta fase de readiness, dívida histórica é medida e preservada como evidência; não é corrigida nem escondida por um gate destrutivo.

## 4. Lacunas deliberadamente ainda abertas

H5a não apresenta como concluído o que ainda é placeholder:

- `LojaFinanceiroService::prepareForOrder()` ainda não cria a fatura canónica;
- a entrega cria atualmente um `Movement` de receita, não uma `Invoice` integrada com `PaymentAllocationService`;
- a Loja ainda não deriva estado de pagamento das alocações confirmadas;
- a emissão fiscal manual ainda não está ligada a uma fatura de Loja;
- devolução posterior à entrega exige contrato financeiro/fiscal explícito antes da reposição de stock;
- a máquina de estados completa deve separar pagamento, preparação, levantamento/entrega, cancelamento e devolução.

## 5. Sequência dos próximos lotes

| Lote | Resultado verificável |
|---|---|
| H5b | Encomenda ligada idempotentemente a `Invoice`, com itens e centro de custo estruturados, sem segunda saída de stock. |
| H5c | Pagamento confirmado por `PaymentAllocationService` projeta o estado da encomenda e cria o pedido fiscal manual pelo fluxo canónico. |
| H5d | Cancelamento/devolução após efeitos financeiros usa reversão explícita e apenas depois repõe stock; sem apagar histórico. |
| H5e | Lifecycle interno de compras, requisições, entregas e empréstimos de Logística fechado com QA operacional e contratos Desportivo↔core preservados. |

Nenhum destes lotes pode introduzir custos do atleta em descrições textuais: atleta, invoice, centro de custo, evento e requisição mantêm ligações estruturadas e separadas da dívida.

## 6. Evidência produtiva H5a

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
