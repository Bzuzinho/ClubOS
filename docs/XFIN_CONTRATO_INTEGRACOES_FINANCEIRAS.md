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