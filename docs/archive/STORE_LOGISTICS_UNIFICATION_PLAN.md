# Loja + Logistica + Financeiro: Plano de Unificacao

## Objetivo

Construir um unico dominio de artigos para suportar, de forma uniforme:

- Loja para utilizadores no portal
- administracao da Loja
- gestao de stock
- requisicoes de material
- encomendas a fornecedores
- fornecedores
- faturacao ao utilizador
- pagamentos a fornecedores
- ligacao ao Financeiro e conciliacao bancaria

O objetivo funcional nao e ter uma Loja separada da Logistica. O objetivo e ter um unico catalogo de artigos e um unico motor de stock, com canais diferentes sobre a mesma base de dados.

## Decisao Principal

### Fonte unica de verdade para artigos

A tabela canonica deve passar a ser `products`.

Razoes:

- `stock_movements` aponta para `products`
- `supplier_purchase_items` aponta para `products`
- `equipment_loans` aponta para `products`
- `logistics_request_items` aponta para `products`
- compras a fornecedor ja atualizam stock e criam registos financeiros sobre `products`

A stack nova de Loja (`loja_produtos`, `loja_encomendas`, `loja_carrinhos`) nao deve continuar como base permanente. Deve ser tratada como transitoria e migrada para o catalogo canonico.

## Arquitetura Alvo

### 1. Catalogo de artigos

#### `products`

Tabela canonica para qualquer artigo fisico ou vendavel.

Campos nucleares recomendados:

- `id`
- `codigo`
- `nome`
- `descricao`
- `categoria_id` ou categoria normalizada equivalente
- `supplier_id` principal
- `preco_venda`
- `custo_reposicao` ou `ultimo_custo`
- `stock`
- `stock_reservado`
- `stock_minimo`
- `ativo`
- `visible_in_store`
- `allow_sale`
- `allow_request`
- `allow_loan`
- `track_stock`
- `imagem`

Notas:

- `visible_in_store` controla se aparece no portal
- `allow_sale` separa artigos vendaveis de artigos apenas requisitaveis
- `allow_request` cobre material interno
- `allow_loan` cobre emprestimos
- `track_stock` evita hacks tipo `gere_stock` apenas no modulo Loja

### 2. Variantes de artigo

#### `product_variants` ou extensao do modelo atual de variantes

Hoje as variantes existem so em `loja_produto_variantes`. No alvo, as variantes devem ser genericas.

Campos recomendados:

- `id`
- `product_id`
- `nome`
- `sku`
- `tamanho`
- `cor`
- `atributos_json` opcional
- `preco_extra`
- `stock`
- `stock_reservado`
- `ativo`

Regra:

- stock e reserva podem existir ao nivel da variante quando o artigo as usar
- se nao houver variantes, o stock fica apenas em `products`

### 3. Movimentos de stock

#### `stock_movements`

Continua a ser o razao unico de stock.

Tipos de movimento alvo:

- `purchase_entry`
- `manual_entry`
- `sale_reservation`
- `request_reservation`
- `loan_exit`
- `sale_delivery`
- `request_delivery`
- `loan_return`
- `reservation_release`
- `stock_adjustment`
- `stock_loss`

Regra:

- nao deve haver multiplas logicas de decremento espalhadas por Loja e Logistica
- qualquer alteracao de stock deve gerar um movimento auditavel
- o stock atual deve ser coerente com o saldo dos movimentos ou pelo menos reconciliavel com eles

### 4. Saidas de artigos para utilizadores

#### Documento unico recomendado: `article_orders`

Em vez de manter `loja_encomendas` e `logistics_requests` como dominos independentes, o alvo deve ser um unico documento operacional de saida de artigos.

Campos recomendados:

- `id`
- `numero`
- `request_type` com valores `sale`, `request`, `loan`
- `channel` com valores `portal`, `admin`, `internal`
- `requester_user_id`
- `target_user_id`
- `status`
- `subtotal`
- `total`
- `financial_document_id` ou `invoice_id`
- `notes`
- `approved_at`
- `delivered_at`
- `created_by`
- `updated_by`

Tabela de linhas:

- `article_order_items`
- `article_id`
- `article_variant_id`
- `descricao_snapshot`
- `quantidade`
- `preco_unitario`
- `total_linha`

Estados recomendados:

- `draft`
- `submitted`
- `approved`
- `reserved`
- `invoiced`
- `ready`
- `delivered`
- `cancelled`

Regras de negocio:

- `sale`: gera fatura ao utilizador
- `request`: pode ou nao gerar fatura, conforme politica do clube
- `loan`: nao gera fatura por defeito; gera controlo de devolucao

Se a unificacao para documento unico for demasiado grande nesta fase, a regra minima e:

- `loja_encomendas` e `logistics_requests` passam ambos a usar `products`
- ambos passam a usar o mesmo motor de movimentos de stock
- ambos passam a usar o mesmo contrato de integracao financeira

### 5. Compras a fornecedor

#### Manter `suppliers`, `supplier_purchases`, `supplier_purchase_items`

Esta area ja esta bem orientada e deve permanecer como espinha do abastecimento.

Melhorias recomendadas:

- snapshot do fornecedor continua a existir
- cada linha referencia `product_id` canonico
- a compra cria:
  - entrada de stock
  - movimento financeiro de despesa
  - lancamento financeiro
  - eventual documento para conciliacao

### 6. Financeiro

#### Recebimentos a clientes/utilizadores

Manter `invoices` e `invoice_items` para faturacao a utilizadores.

Cada venda de artigo deve gerar:

- `invoices`
- `invoice_items` com referencia ao `product_id`
- `origem_tipo = article_order`
- `origem_id = <id do pedido>`

#### Pagamentos a fornecedores

Manter:

- `movements`
- `movement_items`
- `financial_entries`

Cada compra a fornecedor deve gerar:

- despesa financeira
- registo contabilistico/lancamento
- ligacao por `origem_tipo` e `origem_id`

#### Conciliacao bancaria

A conciliacao deve operar sobre documentos financeiros canonicos, nunca sobre tabelas da Loja ou da Logistica diretamente.

Ou seja:

- vendas conciliam `invoices` e respetivos recebimentos
- compras conciliam `movements` ou `financial_entries` associados ao fornecedor

## Regras de Negocio Unificadas

### Venda ao utilizador no portal

1. Utilizador escolhe artigo visivel na Loja
2. Sistema cria pedido do tipo `sale`
3. Sistema reserva stock
4. Sistema gera fatura
5. Sistema marca pagamento quando liquidado
6. Sistema entrega artigo e baixa stock reservado

### Requisicao de material

1. Utilizador ou colaborador submete pedido do tipo `request`
2. Admin aprova
3. Sistema reserva stock
4. Se aplicavel, gera fatura ou debito interno
5. Na entrega, converte reserva em saida definitiva

### Compra a fornecedor

1. Registo da compra
2. Entrada de stock em `stock_movements`
3. Criacao de despesa em Financeiro
4. Associacao ao fornecedor
5. Pagamento posterior conciliado via banco

### Emprestimo

1. Pedido do tipo `loan`
2. Saida temporaria de stock
3. Registo de devolucao prevista
4. Devolucao repoe stock

## Problemas Atuais a Corrigir Antes da Unificacao Final

### 1. Duplicacao de catalogo

Hoje existem dois catalogos:

- `products`
- `loja_produtos`

Isto cria risco de divergencia em:

- preco
- stock
- codigos
- fornecedores
- integracao financeira

### 2. Duplicacao de pedidos

Hoje existem pelo menos dois modelos operacionais para saida de artigos:

- `loja_encomendas`
- `logistics_requests`

E ainda restos de uma stack legacy com:

- `store_orders`
- `store_cart_items`

### 3. Logica de stock inconsistente na Logistica

O fluxo atual de Logistica mistura reserva e baixa fisica demasiado cedo. O motor alvo deve separar claramente:

- reservar
- libertar reserva
- entregar
- devolver

### 4. Loja nova sem integracao financeira completa

A stack nova de Loja ainda nao e espinha fiavel do dominio porque a integracao financeira ainda esta incompleta.

## Matriz Manter / Fundir / Remover

| Area | Tabela / conceito atual | Decisao alvo | Observacao |
| --- | --- | --- | --- |
| Catalogo | `products` | Manter | Base canonica dos artigos |
| Catalogo | `loja_produtos` | Fundir em `products` | Nao deve ficar como catalogo paralelo |
| Variantes | `loja_produto_variantes` | Fundir / renomear para variante generica | Variantes devem ser independentes da Loja |
| Categorias | `item_categories` | Manter | Normalizar uso com contexto unificado |
| Loja visual | `loja_hero_items` | Manter com ajuste | Pode manter-se como configuracao de frontend da Loja |
| Carrinho | `loja_carrinhos` | Manter temporariamente | Migrar para `products` antes de decidir forma final |
| Carrinho | `loja_carrinho_itens` | Manter temporariamente | Idem |
| Vendas/Requisicoes | `loja_encomendas` | Fundir em documento unico ou migrar para `products` | Nao manter isolada sobre `loja_produtos` |
| Vendas/Requisicoes | `loja_encomenda_itens` | Fundir | Mesma regra |
| Requisicoes internas | `logistics_requests` | Manter no curto prazo, fundir no medio prazo | Continuar apenas se migrar para catalogo unico |
| Requisicoes internas | `logistics_request_items` | Manter no curto prazo, fundir no medio prazo | Ja usa `products` |
| Stock | `stock_movements` | Manter | Razão unico de stock |
| Emprestimos | `equipment_loans` | Manter | Usa `products`, coerente com operacao interna |
| Fornecedores | `suppliers` | Manter | Entidade canonica |
| Compras | `supplier_purchases` | Manter | Espinha de abastecimento |
| Compras | `supplier_purchase_items` | Manter | Ja alinhada a `products` |
| Faturacao clientes | `invoices` | Manter | Documento canonico de venda/faturacao |
| Linhas de fatura | `invoice_items` | Manter | Referencia artigo faturado |
| Despesas/receitas | `movements` | Manter | Documento financeiro operacional |
| Lancamentos | `financial_entries` | Manter | Base para controlo financeiro e conciliacao |
| Loja legacy | `store_orders` | Remover apos migracao | Codigo legado |
| Loja legacy | `store_order_items` | Remover apos migracao | Codigo legado |
| Loja legacy | `store_cart_items` | Remover apos migracao | Codigo legado |

## Mapa por Modulo

### Portal Loja

Deve consumir:

- `products`
- `product_variants`
- `article_orders` ou equivalente temporario migrado

Nao deve ter:

- tabela propria de stock
- tabela propria de catalogo
- integracao financeira propria fora do dominio comum

### Admin Loja

Deve gerir:

- visibilidade dos artigos na Loja
- precos de venda
- destaque / hero / merchandising visual
- estados de pedidos do utilizador

### Logistica

Deve gerir:

- stock
- movimentos
- compras a fornecedores
- emprestimos
- requisicoes internas

Mas sempre sobre o mesmo artigo canonico.

### Financeiro

Deve ser o unico sitio de verdade para:

- faturas ao utilizador
- despesas a fornecedor
- recebimentos
- pagamentos
- conciliacao

## Plano de Execucao Recomendado

### Fase 1

- Congelar criacao de novas tabelas exclusivas da Loja
- Declarar `products` como catalogo canonico
- Definir campos em falta em `products`
- Desenhar tabela generica de variantes

### Passo 1 tecnico detalhado

Objetivo deste passo:

- preparar o catalogo canonico sem quebrar o frontend atual
- deixar `products` pronto para passar a suportar Loja e Logistica
- criar compatibilidade temporaria para migracao faseada

Fora de escopo neste passo:

- trocar o frontend da Loja para `products`
- remover `loja_produtos`
- mudar ja o fluxo de faturacao da Loja
- fundir `loja_encomendas` com `logistics_requests`

#### 1. Congelamento de dominio

Regra imediata para desenvolvimento novo:

- nenhum desenvolvimento novo deve adicionar campos de negocio em `loja_produtos`
- nenhum modulo novo deve apontar para `loja_produtos` como fonte principal
- qualquer nova regra de artigo deve ser pensada primeiro para `products`

#### 2. Migrations recomendadas

##### Migration A: alinhar `products` para catalogo unico

Criar migration do tipo `add_store_and_logistics_fields_to_products_table` com os campos que faltarem em `products`.

Campos a garantir:

- `categoria_id` ou FK equivalente para `item_categories`
- `preco_venda`
- `custo_reposicao` ou `ultimo_custo`
- `stock_minimo`
- `visible_in_store`
- `allow_sale`
- `allow_request`
- `allow_loan`
- `track_stock`
- `imagem`

Notas:

- se alguns destes campos ja existirem com nomes equivalentes, o passo deve normalizar e nao duplicar
- esta migration nao remove nada de `loja_produtos`
- o catalogo canonico nao deve guardar FK direta para tabelas legacy; a ponte tecnica deve ficar fora de `products`

##### Migration B: criar variantes genericas

Criar `create_product_variants_table`.

Campos recomendados:

- `id`
- `product_id`
- `nome`
- `sku`
- `tamanho`
- `cor`
- `atributos_json`
- `preco_extra`
- `stock`
- `stock_reservado`
- `ativo`

Indices recomendados:

- unique em `sku` quando preenchido
- indice em `product_id, ativo`

##### Migration C: opcional de apoio a backfill

Se houver risco de mapeamento ambiguo, criar tabela tecnica `product_catalog_migrations` ou equivalente.

Campos:

- `legacy_source`
- `legacy_id`
- `product_id`
- `product_variant_id`
- `migrated_at`
- `notes`

Decisao aplicada neste repositorio:

- o mapeamento legado fica nesta tabela tecnica
- `products` e `product_variants` nao carregam colunas `legacy_*`
- os servicos de backfill e resolucao conhecem a tabela tecnica, nao o modelo canonico

#### 3. Backfill de dados

Criar um comando ou job dedicado, por exemplo `catalog:backfill-store-products-into-products`.

Responsabilidades:

- copiar artigos de `loja_produtos` para `products` quando ainda nao existirem
- reutilizar `products` existentes quando o codigo do artigo ou SKU permitir match seguro
- preencher `legacy_loja_produto_id`
- copiar variantes de `loja_produto_variantes` para `product_variants`
- preencher `legacy_loja_produto_variante_id`
- gerar relatorio final com:
  - artigos criados
  - artigos associados por match
  - variantes criadas
  - conflitos que exigem decisao manual

Regra de seguranca:

- se houver colisao de codigo, SKU ou slug com dados divergentes, o comando nao deve decidir sozinho
- esses casos devem sair em relatorio para validacao manual

#### 4. Contrato de servicos a introduzir ja

Mesmo sem mudar ja todo o runtime, este passo deve deixar definido o contrato do dominio canonico.

Servicos recomendados:

- `CanonicalProductCatalogService`
- `CanonicalProductVariantService`
- `StockMovementService` ou adaptacao do servico atual para interface comum

Responsabilidades minimas:

- obter artigo canonico por `product_id`
- resolver artigo canonico a partir de `loja_produto_id` durante a transicao
- obter variante canonica por `product_variant_id` ou `loja_produto_variante_id`
- expor regras de disponibilidade e flags de canal

Importante:

- neste passo os servicos podem coexistir com `LojaStockService`
- a troca efetiva dos controllers e actions fica para a fase seguinte

#### 5. Ordem de execucao recomendada

1. Inventariar os nomes de coluna atuais em `products` para evitar duplicacao semantica.
2. Criar migration A para completar `products`.
3. Criar migration B para `product_variants`.
4. Criar, se necessario, migration C de mapeamento tecnico.
5. Implementar comando de backfill em modo dry-run.
6. Executar dry-run em base de desenvolvimento e rever conflitos.
7. Executar backfill real apenas depois de validar o relatorio.
8. Produzir documento curto com as correspondencias `loja_produtos -> products`.

#### 6. Artefactos concretos esperados neste repositorio

Ficheiros esperados neste passo:

- novas migrations em `database/migrations`
- comando Artisan em `app/Console/Commands`
- servicos canonicos em `app/Services`
- eventual relatorio tecnico em `docs/`

Ficheiros que nao devem ser alterados neste passo, salvo necessidade tecnica minima:

- paginas React da Loja
- controllers do portal da Loja
- controllers de Logistica
- integracao financeira runtime

#### 7. Criterios de aceitacao do passo 1

O passo 1 fica concluido quando:

- `products` suporta semanticamente o catalogo unico
- existe tabela generica de variantes
- existe mecanismo confiavel de mapear `loja_produtos` para `products`
- existe comando de backfill com dry-run e relatorio de conflitos
- nenhuma tabela antiga foi ainda removida
- o sistema continua estavel porque o runtime principal ainda nao mudou

#### 8. Resultado esperado no fim do passo 1

No fim deste passo, o repositorio fica com a base tecnica pronta para a migracao funcional da Loja.

Ou seja:

- a verdade canonica ja esta preparada
- a migracao de dados ja tem caminho definido
- a mudanca de frontend e de servicos de negocio pode avancar na fase 2 sem improviso de schema

### Fase 2

- Migrar frontend e admin da Loja de `loja_produtos` para `products`
- Passar carrinho e encomendas a referenciar `products`
- Substituir `LojaStockService` por servico comum de stock

### Fase 3

- Ligar venda da Loja a `invoices` e `invoice_items`
- Ligar compras a fornecedor a `movements` e `financial_entries`
- Garantir `origem_tipo` e `origem_id` uniformes

### Fase 4

- Fundir ou alinhar `loja_encomendas` com `logistics_requests`
- Eliminar `store_orders` e restantes restos legacy
- Preparar descontinuação de `loja_produtos`

### Fase 5

- Limpeza final de tabelas redundantes
- testes de reconciliacao de stock
- testes de faturacao e conciliacao

## Decisoes Concretas Recomendadas para Este Repositorio

1. `products` fica como tabela mestre de artigos.
2. `loja_produtos` deixa de ser alvo para desenvolvimento novo.
3. `loja_produto_variantes` deve ser evoluida para variante generica e ligada a `products`.
4. `stock_movements` continua como motor unico de stock.
5. `supplier_purchases` e `suppliers` mantem-se.
6. `invoices`, `movements` e `financial_entries` mantem-se como espinha financeira.
7. `loja_encomendas` e `logistics_requests` devem convergir funcionalmente, mesmo que a fusao fisica fique para segunda fase.
8. `store_orders` e restantes tabelas legacy devem sair apos a migracao.

## Resultado Esperado

No estado final:

- existe um unico artigo
- existe um unico stock
- existe uma unica politica de preco por artigo e variante
- existe uma unica ligacao entre operacao e Financeiro
- a Loja e a Logistica deixam de competir por dados
- o portal mostra apenas um canal sobre o mesmo dominio
