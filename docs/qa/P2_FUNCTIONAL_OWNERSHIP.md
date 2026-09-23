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


## P2.3 — Marketing: planeamento vs. Comunicação: execução

### Owners funcionais

- **Marketing** gere o plano da campanha: objetivo/descrição, tipo de ação, datas, estado, orçamento, alcance estimado e notas.
- **Comunicação** gere a execução/distribuição: destinatários/segmentos, canais, conteúdos, agendamento, dispatch, entregas, retries e alertas.
- Um plano de Marketing não representa um envio efetuado; uma campanha de Comunicação não é o orçamento/brief de Marketing.

### Decisão deste lote

A UI passa a tornar a fronteira explícita:
- Marketing usa “Planos de Campanha” e identifica que os envios são executados em Comunicação;
- Comunicação descreve as suas campanhas como campanhas de envio/execução.

Os modelos e controllers continuam separados (`MarketingCampaign` vs. `CommunicationCampaign`). Contract tests impedem dependência cruzada direta entre os dois controllers.

Não existe migration, fusão de tabelas, sincronização automática ou criação de relação implícita entre planos e envios neste lote.


## P2.4 — Membros: consulta financeira vs. Financeiro: mutação

### Owner operacional

- **Financeiro** é o único owner de criação/edição/eliminação de movimentos financeiros e respetivos itens.
- **Membros > Ficha > Financeiro** configura apenas atributos do membro (plano de mensalidade, centro de custo, desconto) e consulta o histórico/conta corrente.

### Problema encontrado

A ficha ainda consumia `club-movimentos` e `club-movimento-itens` através do KV genérico e expunha edição de itens de inscrições em Eventos. Estas chaves não têm adapter canónico para `Movement`/itens financeiros, pelo que a alteração gravava uma fonte paralela no `KeyValueStore`.

### Decisão deste lote

- a ficha mantém a leitura do histórico KV legado para não ocultar dados antigos;
- essa secção passa a estar explicitamente identificada como histórico legado e read-only;
- são removidos setters, diálogo de edição e ações de mutação no `FinancialTab`;
- `PUT/DELETE` de `club-movimentos`, `club-movimento-itens` e do alias histórico `club-movimento-items` passam a ser bloqueados no backend;
- a leitura dessas chaves exige `membros.ficha.financeiro:view`;
- movimentos canónicos continuam a ser lidos do payload `Movement` entregue pelo `MembrosController`.

Sem migration, backfill ou eliminação de histórico neste lote.
