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


## P2.2 — Resultados: Competições vs. Eventos

### Owners operacionais

- **Desportivo > Competições / Resultados** é o owner dos resultados oficiais competitivos, na cadeia canónica `results → provas → competitions`.
- **Eventos > Resultados** é o owner dos registos associados a Eventos expostos pela compatibilidade `club-resultados-provas`.
- A ficha do membro é uma superfície de consulta dos dois históricos; não é uma terceira porta de escrita.

### Decisão deste lote

`Membros > Desportivo > Resultados` deixa de criar, editar ou eliminar `club-resultados-provas`. Mantém:
- histórico oficial de Competições via `CompetitionHistory`;
- leitura separada de Resultados de Eventos;
- identificação explícita do owner de edição em `Eventos > Resultados`.

O resumo da ficha também passa a chamar estes dados de “Resultados de Eventos”, evitando apresentá-los como se fossem o histórico competitivo oficial.

No backend, `club-resultados-provas` e `club-resultados` continuam legíveis para permissões de Eventos, Desportivo e ficha do membro, mas `edit/delete` ficam reservados a `eventos.resultados`.

Não existe migração, unificação ou eliminação automática de registos neste lote. As duas fontes mantêm ownership semântico distinto.
