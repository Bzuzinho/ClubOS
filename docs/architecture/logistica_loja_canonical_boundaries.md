# Logística e Loja — fronteiras e fonte de verdade

## Decisão

O ClubOS mantém **dois módulos de experiência**, porque respondem a tarefas, permissões e ritmos diferentes:

- **Logística**: inventário físico, entradas/saídas/reservas, compras, requisições, empréstimos, custo e fornecedor;
- **Loja**: publicação, preço de venda, imagens comerciais, variantes comerciais, destaques, carrinho e encomendas.

Os módulos não mantêm catálogos nem stocks próprios. Partilham um único núcleo canónico: `products`, `item_categories`, `product_variants` e o ledger `stock_movements`.

Juntar tudo num único ecrã aumentaria a carga cognitiva e misturaria permissões operacionais com comerciais. Separar também os dados criaria sincronizações, stock divergente e artigos duplicados. A solução adotada é, portanto, **separação de UX com dados canónicos comuns**.

## Ownership dos campos

| Dado / ação | Responsável | Regra |
|---|---|---|
| Código, nome, categoria | Configurações / Logística | `categoria_id` referencia `item_categories`; `categoria` existe apenas como mirror transitório |
| Estado global do artigo | Configurações / Logística | Um artigo globalmente inativo não pode ser publicado nem operado |
| Gestão e mínimo de stock | Configurações / Logística | A Loja apresenta estes dados, mas não os altera |
| Stock físico e reservado | Ledger da Logística | Nenhuma edição absoluta na Loja; todos os deltas passam por `StockLedgerService` |
| Fornecedor e último custo | Logística / Compras | `ultimo_custo` é atualizado pelas compras e suporta a valorização de inventário |
| Preço base de referência | Configurações / Logística | `preco`; não é apresentado como preço de venda |
| Preço de venda | Loja | `preco_venda`, com fallback histórico controlado para `preco` |
| Publicação | Loja | Exige simultaneamente `ativo`, `visible_in_store` e `allow_sale` |
| Destaques | Loja | `loja_hero_items` é a única fonte ativa; o booleano legacy `products.destaque` não participa no runtime |
| Variantes | Loja + Logística | Metadados na Loja; stock por variante apenas pelo ledger da Logística |
| Encomendas | Loja | Sequência monotónica: pendente → aprovada → preparada → entregue; cancelamento apenas antes da entrega |

O formulário “Novo produto” da Loja pode criar a ficha canónica por conveniência, sempre com stock zero e capacidades internas conservadoras. Depois da criação, nome, código e categoria ficam bloqueados na Loja e passam a ser mantidos em Configurações / Logística.

## Invariantes aplicadas

1. Catálogo público, detalhe, carrinho e checkout usam a mesma elegibilidade `sellable`: ativo + visível + venda permitida.
2. Métricas da Loja usam a mesma elegibilidade; artigos sem gestão de stock não são classificados como sem stock.
3. Baixo stock usa stock disponível (`stock - stock_reservado`) e ignora artigos sem gestão de stock.
4. A Loja nunca escreve `stock`, `stock_reservado`, `stock_minimo`, `track_stock`, `ativo`, `allow_request` ou `allow_loan`.
5. A despublicação/inativação de um produto desativa automaticamente os respetivos destaques.
6. O registo manual de movimentos aceita uma variante e atualiza variante + agregado na mesma transação.
7. Enquanto requisições, empréstimos e compras não tiverem `product_variant_id`, artigos com variantes ficam excluídos desses fluxos e o backend rejeita bypasses.
8. A valorização usa `ultimo_custo`, nunca o preço de venda. A migration inicializa categorias canónicas e últimos custos históricos conhecidos.
9. Alterações do produto invalidam os caches de Configurações, Financeiro e Dashboard.

## Compatibilidade e evolução

- `products.categoria` e `products.destaque` permanecem temporariamente no schema para compatibilidade e rollback, mas deixaram de ser fontes de decisão.
- `item_categories.contexto` permanece como metadado legacy e não divide o catálogo: uma categoria ativa com produtos publicáveis é apresentada na Loja independentemente do contexto em que foi criada.
- `products.variant_options` deixou de ter consumidores runtime; variantes estruturadas vivem em `product_variants`.
- `/portal/loja` continua como redirect para `/loja`; a antiga página e controller paralelo foram removidos.
- Suporte de variantes em compras, requisições e empréstimos exige primeiro adicionar `product_variant_id` aos respetivos itens e snapshots. Até esse lote, o sistema falha fechado em vez de movimentar stock agregado incorretamente.

## Critério de conclusão

O lote só fica operacionalmente concluído após migrations e testes Laravel em PostgreSQL, build frontend, E2E dos formulários responsivos, CI verde e audit de stock pós-deploy. Até lá, o código está implementado mas não deve ser descrito como integrado ou produtivo.
