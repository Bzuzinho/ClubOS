# XFIN Contrato de Integracoes Financeiras

## Objetivo

Formalizar os dois contratos financeiros que a auditoria XFIN1 usa como referencia factual, sem alterar comportamento nem dados.

## Contrato 1: Recebivel individual

Fluxo esperado:

Business Source -> Invoice -> Payment -> PaymentAllocation

Regras:

- A origem funcional deve identificar o registo individual e nao apenas o modulo generico.
- A invoice representa a divida individual.
- O pagamento nao substitui a invoice; liquida-a via PaymentAllocation.
- FinancialEntry paralela keyed pela mesma divida deve ser tratada como risco de duplicacao.

## Contrato 2: Movimento financeiro

Fluxo esperado:

Business Source -> Movement -> FinancialSettlementService -> FinancialEntry origem=movement -> PaymentAllocation

Regras:

- O movement representa o facto financeiro operacional.
- A liquidacao deve convergir pelo servico canonico e gerar FinancialEntry com `origem_tipo=movement` e `origem_id=movement.id`.
- Entradas keyed diretamente pela origem de negocio sem ligar ao movement criam risco de contagem paralela.
- Estados pagos, conciliados ou fiscais num movement nao podem coexistir com fluxos destrutivos de refresh/delete/recreate.

## XFIN3: CompetitionRegistration lifecycle canonico

Estado: concluido em 2026-07-08.

### Criacao de inscricao

Fluxo esperado:

CompetitionRegistration -> Invoice(tipo=inscricao, origem_tipo=competition_registration, origem_id=competition_registration.id) -> InvoiceItem

Regras:

- a criacao de `CompetitionRegistration` nao pode criar `FinancialEntry` paralelo;
- o valor efetivo segue prioridade: `valor_inscricao` explicito -> `evento.taxa_inscricao` -> `0`;
- apenas valor efetivo `> 0` cria `Invoice` + `InvoiceItem`;
- valor efetivo `<= 0` nao cria divida nem item financeiro.

### Remocao/cancelamento de inscricao

Fluxo esperado:

- sem invoice: remocao permitida;
- invoice pendente/cancelada sem pagamentos, sem allocations confirmadas e sem documento fiscal/recibo emitido: remocao segura permitida;
- invoice parcial/paga, com `valor_pago > 0`, com `PaymentAllocation` confirmada, com `FiscalDocumentRequest` emitido/numero externo ou com recibo emitido: remocao bloqueada por validacao funcional.

### Compatibilidade legacy

- XFIN3 nao faz backfill;
- XFIN3 nao altera invoices antigas;
- a auditoria continua a reportar origens ambiguas e paralelismos legacy quando existirem.

## XFIN4: SupplierPurchase lifecycle canonico

Estado: runtime concluido em 2026-07-08, sem backfill legacy.

### Criacao de compra

Fluxo esperado:

SupplierPurchase -> Movement(classificacao=despesa, valor_total positivo, origem_tipo=supplier_purchase, origem_id=supplier_purchase.id) -> MovementItem/StockMovement

Regras:

- a criacao de `SupplierPurchase` nao pode criar `FinancialEntry` direto;
- `financial_movement_id` e a ligacao operacional obrigatoria da compra;
- `financial_entry_id` permanece apenas como referencia legacy para leitura historica (nao preencher em compras novas).

### Liquidacao da despesa

Fluxo esperado:

Movement -> FinancialSettlementService::settleMovement() -> FinancialEntry(origem_tipo=movement, origem_id=movement.id, tipo=despesa, valor positivo) -> Payment/PaymentAllocation

Regras:

- `FinancialEntry` da compra nasce apenas no settlement canonico do `Movement`;
- nao criar `FinancialEntry` source-keyed por `stock`/`supplier_purchase` na criacao/update da compra;
- movement e financial entry representam camadas diferentes do mesmo lifecycle, nao factos paralelos.

### Update/Delete com guard financeiro

Regras:

- update/delete de `SupplierPurchase` devem bloquear quando houver impacto financeiro (pago/parcial, allocations/pagamentos confirmados, conciliação, fiscal emitido ou legado paralelo ambiguo);
- update permitido apenas em pendente, reutilizando o mesmo `Movement` (sem delete/recreate);
- delete permitido apenas em pendente e sem impacto financeiro; remover registos operacionais (stock/movement/items) sem destruir `FinancialEntry` legacy automaticamente.

### Compatibilidade legacy

- XFIN4 nao faz backfill;
- XFIN4 nao remove colunas (`financial_entry_id` permanece);
- a auditoria continua a reportar `supplier_purchase_parallel_movement_and_entry`, `supplier_purchase_source_keyed_entry`, `supplier_purchase_orphan_financial_movement_reference`, `supplier_purchase_orphan_financial_entry_reference`, `supplier_purchase_multiple_movements`, `supplier_purchase_multiple_entries` e `negative_expense_movement_value` quando existirem.

## XFIN5: LogisticsRequest faturada com lifecycle financeiro protegido

Estado: runtime concluido em 2026-07-08, sem backfill legacy.

### Faturacao da requisicao

Fluxo esperado:

LogisticsRequest -> Invoice(tipo=material, origem_tipo=logistics_request, origem_id=logistics_request.id) -> InvoiceItem

Regras:

- faturacao de `LogisticsRequest` deve usar origem canónica `logistics_request` (nao `stock`);
- `centro_custo_id` da invoice deve ser resolvido de forma canónica via `MemberCostCenterResolver` (sem heuristicas por texto);
- quando existir centro de custo unico (ou top-único por peso), usar esse centro;
- quando houver ambiguidade/empate sem prioridade explícita, usar `centro_custo_id=null`.

### Update/Delete da requisicao faturada

Fluxo esperado:

- update/delete permitido apenas em estado financeiro seguro (pendente/vencido sem pagamentos/alocacoes/conciliacao/fiscal/recibo);
- update permitido deve sincronizar a mesma invoice e seus itens (sem delete/recreate de invoice) e recomputar snapshot: `valor_pago=0`, `valor_em_aberto=valor_total`, `estado_pagamento=pendente|vencido`, limpeza de campos de pagamento;
- delete permitido deve limpar apenas pedidos fiscais pendentes e remover invoice/itens;
- delete/update devem bloquear se houver sinais de lifecycle fechado.

Sinais de bloqueio mínimo:

- `estado_pagamento` parcial/pago ou `valor_pago > 0`;
- `PaymentAllocation` confirmada e/ou `Payment` confirmado associado;
- conciliação confirmada (`mapa_conciliacao.status=confirmado`);
- `FiscalDocumentRequest` emitido ou documento externo registado;
- sinais de recibo na invoice (`numero_recibo`, `recibo_emitido_em`, ligação importada/pdf).

### Compatibilidade legacy

- XFIN5 nao faz backfill;
- XFIN5 nao remove colunas nem apaga registos financeiros fechados;
- a auditoria continua a reportar riscos legacy/canonicidade em `logistics_requests` por códigos explícitos:
	- `logistics_request_orphan_invoice_reference`
	- `logistics_request_missing_invoice`
	- `logistics_invoice_orphan_source`
	- `logistics_invoice_total_mismatch`
	- `logistics_invoice_user_mismatch`
	- `logistics_paid_invoice_mutable_lifecycle`
	- `logistics_allocated_invoice_mutable_lifecycle`
	- `logistics_fiscal_invoice_mutable_lifecycle`

## XFIN6: ConvocationGroup lifecycle financeiro canónico

Estado: runtime concluido em 2026-07-09, sem backfill legacy.

### Contrato canónico de origem

Fluxo esperado:

ConvocationGroup -> Movement(classificacao=despesa, valor_total positivo, origem_tipo=convocation_group, origem_id=convocation_group.id) -> FinancialSettlementService -> FinancialEntry(origem_tipo=movement, origem_id=movement.id) -> Payment/PaymentAllocation

Regras:

- `ConvocationGroup` nao pode conter side effects financeiros automaticos em hooks de model;
- sync financeiro deve ser explicito, transacional, idempotente e com reutilizacao do mesmo `Movement` quando mutavel;
- `movimento_id` e ligacao interna e nao pode ser aceite como input autoritativo de payload externo;
- update administrativo sem delta financeiro deve ser permitido mesmo com lifecycle protegido;
- update financeiro e delete devem bloquear quando houver estado parcial/pago, allocations/pagamentos confirmados, conciliação confirmada ou vinculo fiscal/documental.

### Convencao monetaria e mutabilidade

Regras:

- `Movement.classificacao=despesa` com `valor_total` positivo;
- `MovementItem.total_linha` positivo;
- custo calculado `<= 0` nao cria movement novo;
- quando existir movement canónico mutável e o novo total for `<= 0`, pode remover movement/items apenas em estado seguro (sem impacto financeiro);
- nunca fazer delete/recreate do `Movement` em update normal: manter o mesmo `id` e apenas sincronizar campos/itens.

### Compatibilidade legacy e auditoria

Regras:

- XFIN6 nao faz backfill nem migracao automatica de origens legacy `evento`;
- XFIN6 nao altera automaticamente movimentos pagos/conciliados/fiscais legacy;
- a auditoria continua explicita para riscos legacy em convocatorias, incluindo códigos:
	- `convocation_group_orphan_movement_reference`
	- `convocation_group_missing_financial_movement`
	- `convocation_group_ambiguous_event_origin`
	- `convocation_group_multiple_movements`
	- `convocation_group_non_specific_movement_origin`
	- `convocation_group_settled_movement_mutable_lifecycle`
	- `convocation_group_allocated_movement_mutable_lifecycle`
	- `convocation_group_reconciled_movement_mutable_lifecycle`
	- `convocation_group_fiscal_movement_mutable_lifecycle`
	- `convocation_movement_orphan_source`
	- `negative_expense_movement_value`

	## XFIN7: SponsorshipMoneyItem lifecycle financeiro canónico

	Estado: runtime concluido em 2026-07-09, sem backfill legacy.

	### Contrato canónico de origem

	Fluxo esperado:

	SponsorshipMoneyItem -> Movement(classificacao=receita, valor_total positivo, origem_tipo=sponsorship_money_item, origem_id=sponsorship_money_item.id) -> FinancialSettlementService -> FinancialEntry(origem_tipo=movement, origem_id=movement.id) -> Payment/PaymentAllocation

	Regras:

	- `SponsorshipMoneyItem` nao pode depender de `origem_tipo=sponsorship` ou `origem_id=sponsorship.id` como origem primária de cada prestação;
	- sync financeiro deve ser transacional, idempotente e com reutilizacao do mesmo `Movement` quando o item canónico ja existir;
	- `Movement.classificacao=receita` com `valor_total` positivo;
	- `MovementItem.valor_unitario` e `MovementItem.total_linha` positivos;
	- update/delete do item devem bloquear quando houver estado parcial/pago, allocations/pagamentos confirmados, conciliação confirmada ou vinculo fiscal/documental;
	- retry de integração parcial canónica deve reparar `financial_movement_id` e `SponsorshipIntegration` sem criar novo `Movement`;
	- delete de sponsorship deve ser transacional, nunca apagar `Movement` diretamente por origem legacy ambígua e nunca destruir dados financeiros fechados.

	### Compatibilidade legacy e auditoria

	Regras:

	- XFIN7 nao faz backfill nem migracao automatica de origens legacy `patrocinio`/`sponsorship`;
	- XFIN7 nao altera automaticamente movimentos pagos/conciliados/fiscais legacy;
	- a auditoria continua explicita para riscos legacy em patrocinios, incluindo códigos:
		- `sponsorship_money_item_orphan_movement_reference`
		- `sponsorship_generated_without_movement`
		- `sponsorship_pending_with_existing_movement`
		- `sponsorship_failed_with_existing_movement`
		- `sponsorship_non_specific_movement_origin`
		- `sponsorship_multiple_movements_for_money_item`
		- `sponsorship_shared_origin_between_money_items`
		- `sponsorship_movement_value_mismatch`
		- `sponsorship_movement_wrong_classification`
		- `sponsorship_settled_movement_mutable_lifecycle`
		- `sponsorship_allocated_movement_mutable_lifecycle`
		- `sponsorship_reconciled_movement_mutable_lifecycle`
		- `sponsorship_fiscal_movement_mutable_lifecycle`

## XFIN8.1: Fecho de validacao do shutdown legacy Sale

Estado: validacao concluida em 2026-07-09, sem alteracoes de runtime e sem backfill.

### Guardas do auditor legacy Sale

Comando:

`php artisan finance:audit-legacy-sales`

Regras fechadas em XFIN8.1:

- `--fail-on-parallel-finance` e guard semantico:
	- `EXIT_CODE=1` quando existir pelo menos um finding `legacy_sale_parallel_invoice_and_entry`;
	- `EXIT_CODE=0` quando nao existir finding de paralelismo.
- `--fail-on-operational-write` e guard semantico:
	- `EXIT_CODE=1` quando `summary.operational_write_paths_count > 0`;
	- `EXIT_CODE=0` quando `summary.operational_write_paths_count = 0`.

### Scanner read-only de referencias Sale

`LegacySaleCodeReferenceScanner` classifica referencias `Sale::` em dois grupos:

- `operational_write_paths`: paths com assinaturas de escrita (`create`, `updateOrCreate`, `insert`, `upsert`, `update`, `delete`, `destroy`, `truncate`);
- `operational_read_paths`: referencias restantes de leitura/relacao.

Regras:

- scanner estritamente read-only (sem side effects e sem alteracao de dados);
- varrimento limitado a `app/` e `routes/` para suportar guard operacional deterministico;
- saida incluida no payload JSON em `code_references` e contadores agregados no `summary`.

### Contrato de validacao de testes XFIN8.1

- `LegacySaleShutdownTest` sem incompletes/skips (`0 incomplete`, `0 skipped`);
- fixture factual de paralelismo legacy (`Sale` + `Invoice stock` + `FinancialEntry stock`) validando finding `legacy_sale_parallel_invoice_and_entry`;
- regressao operacional da Loja continua canonicamente em `StoreOrderRevenueMovementTest` (sem duplicacao de fluxo no shutdown test).