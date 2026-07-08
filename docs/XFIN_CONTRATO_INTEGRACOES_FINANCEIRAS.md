# XFIN — Contrato Transversal de Integrações Financeiras

> Frente técnica aberta em 2026-07-08 após auditoria estrutural do ramo `main`.
>
> Objetivo: consolidar as integrações financeiras entre Financeiro, Loja, Eventos/Desportivo, Logística e Patrocínios sem reescrever o núcleo canónico de pagamentos.

---

## 1. Problema estrutural

O ClubOS possui atualmente vários padrões concorrentes de geração financeira:

- `Invoice` isolada;
- `Movement` isolado;
- `Invoice` + `FinancialEntry` criados em paralelo;
- `Movement` + `FinancialEntry` criados em paralelo;
- efeitos financeiros disparados em hooks `created`/`updated` de models;
- lifecycles de edição e eliminação que podem alterar ou remover registos já pagos, conciliados ou fiscalmente emitidos.

O núcleo financeiro recente (`Payment`, `PaymentAllocation`, `FinancialSettlementService`, `CurrentAccountService`) já define uma direção canónica. A frente XFIN deve obrigar os restantes módulos a integrar com esse núcleo através de contratos explícitos.

---

## 2. Contratos canónicos alvo

### 2.1 Recebível individual

Aplica-se quando existe uma dívida atribuída a um utilizador/família.

Exemplos:

- mensalidade;
- inscrição em prova;
- venda de material ao membro;
- requisição logística faturada.

Fluxo alvo:

```text
Business Source
       ↓
Invoice
       ↓
Payment
       ↓
PaymentAllocation
       ↓
estado financeiro / fiscal
```

Regras:

1. A origem da `Invoice` deve identificar inequivocamente o registo funcional que gerou a dívida.
2. Uma origem funcional não deve criar simultaneamente uma `FinancialEntry` paralela para representar a mesma dívida.
3. Pagamentos de invoices passam pelo fluxo canónico de `PaymentAllocationService`/`FinancialSettlementService`.
4. Update/delete da origem deve respeitar o estado financeiro da invoice.
5. Uma invoice paga, parcialmente paga, conciliada ou fiscalmente emitida não pode ser alterada/destruída por um módulo periférico sem reversão canónica explícita.

### 2.2 Movimento financeiro do clube

Aplica-se a receitas/despesas sem dívida individual baseada em invoice.

Exemplos:

- patrocínio;
- compra a fornecedor;
- despesa de convocatória/evento;
- despesa manual.

Fluxo alvo:

```text
Business Source
       ↓
Movement
       ↓
FinancialSettlementService
       ↓
FinancialEntry origem_tipo=movement
       ↓
Payment / PaymentAllocation
       ↓
conciliação / fiscal
```

Regras:

1. O módulo de origem cria ou sincroniza apenas o `Movement` e os seus `MovementItem`.
2. Não deve existir `FinancialEntry` paralelo source-keyed para representar a mesma operação.
3. Quando necessário, a `FinancialEntry` é criada pelo fluxo financeiro canónico com:
   - `origem_tipo = movement`;
   - `origem_id = movements.id`.
4. A origem do `Movement` deve identificar o registo funcional mais específico possível.
5. Movimentos liquidados, conciliados ou fiscalmente emitidos não podem ser apagados/recriados por hooks de models.

---

## 3. Convenção monetária

A XFIN deve uniformizar a semântica de valores:

- `valor_total`, `valor`, `valor_pago` e `valor_em_aberto` são montantes positivos;
- a natureza financeira é definida por `classificacao`/`tipo` (`receita` ou `despesa`);
- relatórios não dependem do sinal negativo para identificar despesa;
- qualquer legado com valores negativos deve ser auditado e normalizado antes de remover compatibilidade.

---

## 4. Inventário inicial de integrações

| Origem funcional | Registo atual | Contrato alvo | Estado inicial |
|---|---|---|---|
| Mensalidade | `Invoice` | Recebível individual | canónico |
| Loja atual / `LojaEncomenda` | `Movement` na entrega | a validar funcionalmente: recebível ou movimento | estável, contrato ambíguo |
| `Sale` legacy | `Invoice` + `FinancialEntry` em hook | desativar escrita legacy | crítico |
| `CompetitionRegistration` | `Invoice` + `FinancialEntry` em hook | recebível individual | crítico |
| `ConvocationGroup` | `Movement` delete/recreate em hooks | movimento financeiro | crítico |
| `LogisticsRequest` | `Invoice` | recebível individual | criação razoável; lifecycle crítico |
| `SupplierPurchase` | `Movement` + `FinancialEntry` paralelo | movimento financeiro | crítico |
| `SponsorshipMoneyItem` | `Movement` | movimento financeiro | boa base; hardening necessário |
| Despesa manual | `Movement` / fluxo financeiro | movimento financeiro | canónico |

---

## 5. Fases XFIN

### XFIN1 — Auditoria transversal e contrato

Objetivos:

- formalizar contratos acima;
- criar auditoria read-only das origens financeiras;
- identificar duplicados, órfãos e registos fora do contrato;
- não alterar ainda resultados financeiros.

A auditoria deve detetar pelo menos:

- invoices não-mensalidade pagas que não entram nos relatórios atuais;
- `Movement` e `FinancialEntry` paralelos para a mesma origem funcional;
- `FinancialEntry` source-keyed que deveria ser movement-keyed;
- invoices/movements sem origem quando a origem é obrigatória;
- múltiplos registos financeiros para origens esperadas como 1:1;
- origem funcional inexistente;
- referências financeiras órfãs (`fatura_id`, `movimento_id`, `financial_movement_id`, `financial_entry_id`);
- valores negativos em `Movement.valor_total`;
- `Invoice.valor_total`, `valor_pago`, `valor_em_aberto` incoerentes;
- registos pagos/conciliados/fiscais associados a sources atualmente mutáveis/destrutíveis.

Entregáveis:

- `FinancialIntegrationAuditService`;
- comando Artisan read-only `finance:audit-integrations`;
- resumo por módulo/origem;
- `--fail-on-critical` para CI/validação;
- testes feature da auditoria.

### XFIN2 — Relatórios e convenção de sinais

Objetivos:

- eliminar a dependência exclusiva de `Invoice.tipo = mensalidade` nos relatórios;
- garantir que todas as invoices pagas elegíveis entram uma única vez;
- impedir duplicação entre `FinancialEntry` e `Movement`;
- uniformizar despesas como montantes positivos classificados como `despesa`;
- preservar compatibilidade temporária apenas quando auditável.

### XFIN3 — CompetitionRegistration

Objetivos:

- retirar efeitos financeiros do `Model::booted()`;
- criar Action/Service transacional de inscrição;
- origem da invoice por `competition_registration`/ID da inscrição;
- não criar dívida financeira de valor zero;
- definir update/delete/cancelamento seguro da inscrição;
- bloquear destruição de dívida paga/conciliada/fiscal ou exigir reversão canónica.

### XFIN4 — SupplierPurchase

Objetivos:

- manter `Movement` como representação financeira da compra;
- deixar de criar `FinancialEntry` paralelo source-keyed;
- usar `FinancialSettlementService` para liquidação;
- migrar/auditar registos existentes;
- proteger update/delete após pagamento/conciliação/fiscal.

### XFIN5 — LogisticsRequest

Objetivos:

- impedir mutação direta insegura de invoices faturadas;
- recalcular snapshots apenas quando financeiramente permitido;
- bloquear delete destrutivo de invoices com impacto financeiro;
- retirar inferência textual de centro de custo por `escalao`;
- usar fonte canónica/resolver apropriado.

### XFIN6 — ConvocationGroup / Eventos

Objetivos:

- retirar geração financeira dos hooks do model;
- eliminar estratégia delete/recreate de movimentos;
- sincronizar o movimento através de Action/Service explícito;
- usar `origem_tipo = convocation_group` e `origem_id = group.id`;
- bloquear alterações destrutivas depois de settlement/conciliação/fiscal;
- retirar `movimento_id` do payload externo de KeyValue.

### XFIN7 — Patrocínios

Objetivos:

- tornar `syncMoneyItem()` atómico;
- garantir idempotência por money item;
- usar origem específica `sponsorship_money_item`;
- proteger delete/status lifecycle após integração financeira;
- reparar integrações parciais sem duplicar movements.

### XFIN8 — Sale legacy

Objetivos:

- confirmar ausência de escrita operacional;
- impedir novos efeitos financeiros automáticos em `Sale::booted()`;
- manter apenas leitura/backfill histórico enquanto necessário;
- remover o model hook financeiro após auditoria.

### XFIN9 — Regressão transversal end-to-end

Cenários mínimos:

1. mensalidade → pagamento parcial → pagamento total → reabertura canónica;
2. inscrição em prova → dívida → pagamento → tentativa de cancelamento;
3. encomenda loja → entrega → dívida/movimento → pagamento;
4. logistics request → invoice → pagamento → tentativa de edição/delete;
5. supplier purchase → movement → settlement → tentativa de update/delete;
6. convocatória → despesa → alteração pré-pagamento → settlement → tentativa de alteração;
7. sponsorship money item → movement → retry idempotente → settlement;
8. relatórios e dashboard sem duplicação e com saldos coerentes.

---

## 6. Ordem de execução

1. XFIN1 — auditoria read-only;
2. XFIN2 — fonte de reporte e sinais;
3. XFIN3 — inscrições em provas;
4. XFIN4 — compras a fornecedor;
5. XFIN5 — requisições logísticas;
6. XFIN6 — convocatórias/eventos;
7. XFIN7 — patrocínios;
8. XFIN8 — Sale legacy;
9. XFIN9 — regressão transversal.

Cada fase deve ser entregue em slice pequeno, com testes e atualização do estado vivo. Não fazer alterações diretas no núcleo canónico de pagamentos sem evidência de necessidade.

---

## 7. Estado da frente

| Fase | Estado |
|---|---|
| XFIN1 | em execução |
| XFIN2 | pendente |
| XFIN3 | pendente |
| XFIN4 | pendente |
| XFIN5 | pendente |
| XFIN6 | pendente |
| XFIN7 | pendente |
| XFIN8 | pendente |
| XFIN9 | pendente |

Próximo passo técnico: implementar o `FinancialIntegrationAuditService` e o comando `finance:audit-integrations` sem qualquer escrita em base de dados.
