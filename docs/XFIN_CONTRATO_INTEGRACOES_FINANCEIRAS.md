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