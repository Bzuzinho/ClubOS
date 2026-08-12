# Desportivo F5 — contrato com Financeiro

Data: 2026-08-12  
Estado: fundação F5

## Ownership fechado

Desportivo é proprietário de `Competition`, `Prova` e `CompetitionRegistration`. Financeiro é o único proprietário da persistência financeira resultante dessas inscrições.

O Desportivo não cria, altera ou elimina diretamente `Invoice`, `InvoiceItem`, `Movement`, `FinancialEntry`, `PaymentAllocation`, referências fiscais ou conciliações. A integração passa exclusivamente por `App\Contracts\Financeiro\CompetitionFinanceGateway`.

## Política financeira da competição

A tabela `competition_finance_policies` guarda a política por clube + competição.

- `payer_mode=club` é o default para novas competições;
- `payer_mode=athlete` ativa uma cobrança individual;
- modos previstos: `none`, `fixed`, `per_race`, `mixed`, `manual` e `age_group`;
- `cost_center_id` explícito tem prioridade; sem ele, Financeiro usa o centro canónico do membro apenas quando a resolução é inequívoca;
- relay/estafeta não gera automaticamente dívida individual.

A criação canónica de `Competition` cria imediatamente a política `club/none` através do gateway Financeiro.

## Obrigação agregada

`competition_financial_obligations` é a identidade financeira canónica por:

`club_id + competition_id + user_id`

Várias provas do mesmo atleta na mesma competição atualizam a mesma obrigação e a mesma invoice. `competition_registrations.fatura_id` é mantido temporariamente como projeção de compatibilidade e deixa de ser a relação financeira autoritativa.

O campo `Invoice.origem_tipo=competition_registration` é preservado como alias de compatibilidade XFIN até F7; a relação autoritativa passa a ser `competition_financial_obligations.invoice_id`. Quando a prova usada como `origem_id` é removida e a invoice é mutável, o alias muda para outra inscrição ativa sem recriar a invoice.

## Lifecycle

A sincronização é transacional e idempotente.

- adicionar uma prova recalcula a obrigação agregada;
- remover uma prova recalcula a mesma invoice;
- remover a última prova elimina a invoice apenas se o lifecycle financeiro ainda for aberto;
- pagamento parcial/pago, `PaymentAllocation` confirmada, alocação bancária confirmada, documento fiscal emitido/externo ou recibo bloqueiam alteração destrutiva;
- um bloqueio Financeiro faz rollback da alteração da inscrição no Desportivo.

## Migração e compatibilidade

A migration é expand-first e não destrutiva.

- competições existentes recebem política F5;
- `Event.taxa_inscricao > 0` é convertido conservadoramente em `payer_mode=athlete + charge_mode=per_race`, reproduzindo a semântica XFIN3 antiga;
- `Event.centro_custo_id` é copiado para a política quando existente;
- uma única invoice legacy pode ser ligada à obrigação;
- múltiplas invoices legacy para atleta + competição ficam `manual_review` e não são agregadas automaticamente;
- IDs e invoices históricas são preservados.

Existe um adaptador runtime limitado para objetos legacy/directos que ainda não tenham política F5. Esse adaptador pode ler a taxa antiga do Event apenas para preservar compatibilidade; competições criadas pelo lifecycle canónico nunca dependem desse fallback.

## Fora de âmbito

- geração automática do custo do clube enquanto `Movement` sem configuração financeira explícita;
- UI definitiva para editar políticas financeiras de competição;
- remoção física de `competition_registrations.fatura_id`;
- normalização final do alias `Invoice.origem_tipo` (F7);
- Comunicação/Logística (F6).
