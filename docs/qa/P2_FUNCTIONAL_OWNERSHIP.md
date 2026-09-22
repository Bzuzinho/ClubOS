# P2 — Ownership funcional e redundâncias

Data de abertura: 2026-09-22.

## Objetivo

Eliminar portas funcionais concorrentes sem alterar silenciosamente fontes de verdade, dados ou regras de negócio. Cada ação deve ter um owner operacional único; áreas auxiliares podem sugerir, configurar ou auditar, mas não replicar a decisão transacional.

## P2.1 — Banco: aprendizagem vs. conciliação

### Owner operacional

- **Financeiro > Banco** é o único owner da decisão de conciliar/alocar uma linha bancária.
- A liquidação continua no fluxo canónico de pagamentos/alocações (`PaymentAllocationService` / `FinancialSettlementService`).
- A conciliação manual e a confirmação de sugestões convergem no mesmo motor canónico.
- O endpoint legacy de catalogação/conciliação direta já permanece descontinuado.

### Área auxiliar

`Configurações > Financeiro > Aprendizagem e Auditoria` não concilia movimentos nem liquida faturas.

Responsabilidades permitidas:
- consultar aliases aprendidos;
- desativar/reativar um alias;
- consultar/limpar rejeições de sugestões;
- auditar conciliações e exportar auditoria.

### Decisão deste lote

O CRUD HTTP administrativo de aliases (`store`, `update`, `destroy`) não tinha consumidores runtime no frontend e constituía uma segunda porta mutável sem workflow operacional. É retirado do routing e do controller.

A criação de aliases continua a existir apenas dentro dos fluxos canónicos que possuem contexto:
- aprendizagem após conciliação/pagamento confirmado;
- aprendizagem/importação de recibos quando aplicável.

Desativar/reativar permanece disponível porque controla apenas a participação do alias em sugestões futuras; não reescreve pagamentos nem conciliações passadas.

## Guard rails

- `BankReconciliationAliasManagementTest` exige apenas `index`, `deactivate` e `reactivate` no routing público autenticado.
- `WebRouteTopologyAuditTest` impede regressão dos endpoints CRUD retirados.
- `BankReconciliationManualMemberEditingContractTest` fixa a separação textual entre aprendizagem/auditoria e conciliação operacional.

Sem migrations, backfill ou alteração automática de dados neste lote.
