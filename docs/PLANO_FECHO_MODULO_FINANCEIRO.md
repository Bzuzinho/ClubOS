# Plano de Fecho do Módulo Financeiro — ClubOS

> Documento operacional para fechar o módulo Financeiro por sprints.
>
> Este plano complementa `docs/ESTADO_VIVO_DESENVOLVIMENTO.md` e deve ser consultado antes de qualquer desenvolvimento financeiro.

---

## 1. Regra principal

O objetivo não é continuar a acrescentar funcionalidades soltas ao Financeiro.

O objetivo é fechar o módulo com:

- verdade financeira única;
- fluxos canónicos;
- testes automáticos;
- testes manuais reais;
- feedback validado pelo utilizador;
- atualização permanente dos documentos vivos.

Antes de qualquer sprint, consultar obrigatoriamente:

```txt
AGENTS.md
docs/ESTADO_VIVO_DESENVOLVIMENTO.md
docs/PLANO_FECHO_MODULO_FINANCEIRO.md
LOGICA_FUNCAO_LARAVEL.md
ESTADO_IMPLEMENTACAO_LARAVEL.md
```

---

## 2. Ciclo obrigatório de cada sprint

Cada sprint deve seguir sempre este ciclo:

1. Ler documentos vivos e código atual.
2. Implementar correções/desenvolvimentos da sprint.
3. Criar ou atualizar testes automáticos.
4. Executar validações técnicas.
5. Criar lista de testes manuais para o utilizador executar.
6. O utilizador testa e dá feedback.
7. Analisar feedback.
8. Corrigir regressões ou ajustar regras.
9. Atualizar documentos vivos.
10. Só depois avançar para a sprint seguinte.

Não avançar para a sprint seguinte se existirem falhas críticas abertas na sprint atual.

---

## 3. Tipos de teste obrigatórios

### 3.1. Testes automáticos

Sempre que possível, criar testes em:

```txt
tests/Feature/Financeiro
```

Devem cobrir:

- regras de negócio;
- proteção contra regressões;
- validações de permissões quando aplicável;
- estados financeiros;
- pagamentos;
- conciliações;
- pedidos fiscais;
- importação de recibos;
- bloqueios de operações perigosas.

### 3.2. Testes manuais orientados

Cada sprint deve terminar com um bloco chamado:

```txt
Testes manuais para o utilizador
```

Estes testes devem indicar:

- onde clicar;
- que dados usar;
- resultado esperado;
- sinais de erro;
- que screenshots ou mensagens recolher em caso de falha.

### 3.3. Feedback do utilizador

Depois dos testes manuais, o feedback deve ser registado no documento vivo ou no resumo da sprint com:

- teste realizado;
- resultado observado;
- resultado esperado;
- erro encontrado;
- screenshot ou mensagem, se existir;
- decisão: corrigir agora, adiar, mudar regra ou considerar validado.

---

## 4. Validações técnicas padrão

No final de cada sprint, executar sempre que possível:

```bash
composer dump-autoload
php artisan migrate --pretend
php artisan test --filter=Financeiro
npm run build
```

Nas sprints de fecho final ou quando houver alteração estrutural:

```bash
php artisan test
```

Se algum comando não for executado, isso deve ser indicado no resumo final da sprint e em `docs/ESTADO_VIVO_DESENVOLVIMENTO.md`.

---

# 5. Sprints de fecho

---

## Sprint F1 — Verdade financeira canónica

### Objetivo

Garantir que pagamentos, liquidações, conciliações e alterações de estado financeiro passam pelos serviços canónicos:

- `FinancialSettlementService`
- `PaymentAllocationService`
- `MonthlyInvoiceStatusService`

### Testes automáticos mínimos

Criar testes para validar:

- uma mensalidade não pode ser marcada como paga por update direto;
- uma fatura paga cria `Payment` e `PaymentAllocation`;
- uma linha bancária só fica conciliada após alocação canónica;
- uma tentativa de escrita direta perigosa é bloqueada;
- movimentos não são liquidados por alteração direta de estado.

### Testes manuais para o utilizador

1. Entrar no módulo Financeiro > Mensalidades.
2. Escolher uma mensalidade pendente.
3. Tentar alterar para paga pelo fluxo normal da interface.
4. Confirmar que abre fluxo de pagamento e não apenas muda o estado visualmente.
5. Confirmar que após pagar aparecem:
   - estado pago;
   - valor pago correto;
   - valor em aberto zero;
   - pedido fiscal criado.
6. Entrar na tab Banco.
7. Escolher uma linha bancária não conciliada.
8. Conciliar com uma fatura.
9. Confirmar que a linha passa a conciliada ou parcial conforme o valor.

### Resultado esperado

A interface pode permitir iniciar o pagamento, mas nunca deve simplesmente mudar estado sem criar o rasto financeiro canónico.

---

## Sprint F2 — Correção de bugs técnicos críticos

### Objetivo

Corrigir falhas técnicas já identificadas:

- `ilike` hardcoded;
- variável `$operator` não capturada;
- validação de ZIP/importação de recibos;
- apagar faturas inseguro;
- tipo de fatura forçado a `mensalidade`;
- lançamentos locais falsos no frontend;
- `limit(1)` perigoso em eager loading.

### Testes automáticos mínimos

Criar testes para validar:

- pesquisa de extratos funciona sem depender de PostgreSQL;
- pesquisa de sugestões bancárias não rebenta com variável indefinida;
- importação sem ZIP e sem diretoria devolve erro de validação controlado;
- fatura paga não pode ser apagada;
- fatura com pedido fiscal não pode ser apagada;
- fatura manual preserva o tipo escolhido;
- fatura de tipo diferente de mensalidade não entra no fluxo especial de mensalidade.

### Testes manuais para o utilizador

1. Criar fatura manual de tipo diferente de mensalidade, por exemplo inscrição ou material.
2. Confirmar que a fatura aparece com o tipo correto.
3. Editar essa fatura e confirmar que continua com o mesmo tipo.
4. Tentar apagar fatura pendente sem pagamentos: deve permitir.
5. Tentar apagar fatura paga: deve bloquear.
6. Tentar importar recibos sem ZIP e sem diretoria: deve mostrar erro claro.
7. Pesquisar no banco por descrição/referência: não deve gerar erro.

### Resultado esperado

Operações perigosas bloqueadas e erros técnicos eliminados.

---

## Sprint F3 — Mensalidades e conta corrente

### Objetivo

Fechar geração, pagamento, pagamento parcial, vencimento, reabertura e crédito de mensalidades.

### Testes automáticos mínimos

Criar testes para validar:

- gerar mensalidade para um utilizador;
- gerar mensalidades para todos;
- não duplicar períodos;
- mensalidades futuras ficam ocultas quando configurado;
- vencidas passam para vencido;
- pagamento parcial fica parcial;
- pagamento parcial não cria pedido fiscal;
- pagamento total cria pedido fiscal;
- reabrir mensalidade paga remove pagamento e pedido fiscal pendente;
- bloquear reabertura se existir documento fiscal externo;
- pagamento com excedente cria crédito só quando a regra permitir.

### Testes manuais para o utilizador

1. Escolher um atleta com plano de mensalidade.
2. Gerar mensalidades para um período curto.
3. Confirmar que não duplica mensalidades já existentes.
4. Pagar parcialmente uma mensalidade.
5. Confirmar estado parcial e valor em aberto correto.
6. Pagar o restante.
7. Confirmar estado pago e pedido fiscal criado.
8. Reabrir uma mensalidade paga sem número Wintouch.
9. Confirmar que deixa reabrir.
10. Marcar pedido fiscal como emitido com número Wintouch.
11. Tentar reabrir novamente.
12. Confirmar que bloqueia.

### Resultado esperado

Mensalidades ficam consistentes do início ao fim.

---

## Sprint F4 — Banco e conciliação bancária

### Objetivo

Fechar alocação manual e assistida de linhas bancárias a faturas e movimentos.

### Testes automáticos mínimos

Criar testes para validar:

- conciliar extrato com uma fatura;
- conciliar extrato com várias faturas;
- conciliar extrato parcialmente;
- continuar conciliação de extrato parcial;
- impedir alocação acima do valor disponível;
- criar crédito com destino explícito;
- bloquear crédito sem destino explícito;
- confirmar sugestão cria `Payment`, `PaymentAllocation` e `MapaConciliacao`;
- alocação manual cria `Payment`, `PaymentAllocation` e `MapaConciliacao`;
- histórico/alias é criado após conciliação confirmada.

### Testes manuais para o utilizador

1. Entrar na tab Banco.
2. Escolher uma linha bancária com valor igual a uma mensalidade.
3. Conciliar com essa mensalidade.
4. Confirmar estado reconciled/conciliado.
5. Escolher uma linha bancária com valor superior a uma mensalidade.
6. Alocar apenas uma parte.
7. Confirmar que fica parcial e com valor por conciliar.
8. Alocar o restante a outra mensalidade.
9. Confirmar que passa a conciliado.
10. Testar sugestão automática e confirmar sugestão.
11. Confirmar que o sistema aprende alias para futuras sugestões.

### Resultado esperado

Banco fica operacional e coerente com faturas, pagamentos e conciliação.

---

## Sprint F5 — Movimentos financeiros, despesas e receitas manuais

### Objetivo

Fechar movimentos financeiros sem competir com faturas/pagamentos canónicos.

### Testes automáticos mínimos

Criar testes para validar:

- criar despesa manual;
- criar receita manual;
- impedir marcar movimento como pago por update direto;
- liquidar movimento pelo serviço canónico;
- conciliar movimento com linha bancária;
- movimento parcial fica `pago_parcial`;
- movimento totalmente pago fica `pago`;
- documento obrigatório em falta altera estado documental;
- documento validado atualiza estado documental.

### Testes manuais para o utilizador

1. Criar uma despesa manual.
2. Confirmar que aparece em movimentos.
3. Tentar marcar como paga diretamente, se a interface permitir.
4. Confirmar que obriga fluxo de liquidação/conciliação.
5. Conciliar a despesa com uma linha bancária.
6. Confirmar estado financeiro e estado de conciliação.
7. Anexar documento, se aplicável.
8. Confirmar estado documental.

### Resultado esperado

Movimentos são operacionais, mas não criam uma segunda verdade financeira.

---

## Sprint F6 — Emissão fiscal manual / Wintouch

### Objetivo

Fechar fila manual de emissão fiscal enquanto não existir API real Wintouch Cloud.

### Regras finais

- Botão `Tratar manualmente`: inserir número Wintouch, série, data e notas.
- Botão `Cancelar/Anular`: só disponível se existir número Wintouch.
- Botão `Apagar`: só disponível se não existir número Wintouch.
- Estados: por tratar, recibo emitido, erro de dados, cancelado/anulado.

### Testes automáticos mínimos

Criar testes para validar:

- pedido fiscal criado quando fatura fica paga;
- pedido com NIF em falta fica erro de dados;
- marcar como emitido exige número externo;
- pedido emitido grava número Wintouch;
- pedido com número externo não pode ser apagado;
- pedido sem número externo pode ser apagado;
- pedido com número externo pode ser cancelado/anulado;
- fatura com documento externo não pode ser reaberta.

### Testes manuais para o utilizador

1. Pagar uma mensalidade.
2. Ir à tab Emissão Fiscal.
3. Confirmar que aparece pedido por tratar.
4. Abrir `Tratar manualmente`.
5. Inserir número Wintouch.
6. Confirmar que passa a recibo emitido.
7. Confirmar que deixa de aparecer botão apagar.
8. Confirmar que aparece botão cancelar/anular.
9. Tentar reabrir a mensalidade.
10. Confirmar que bloqueia por já existir documento fiscal.

### Resultado esperado

Fila fiscal manual fica operacional e segura.

---

## Sprint F7 — Importação de recibos antigos

### Objetivo

Fechar importação assistida de PDFs antigos de recibos.

### Testes automáticos mínimos

Criar testes para validar:

- criar batch por ZIP;
- criar batch por diretoria pendente;
- falhar sem ZIP e sem diretoria;
- detetar duplicado por hash;
- detetar duplicado por número de recibo;
- match por NIF;
- match por número de sócio;
- match por valor e mês;
- commit marca fatura como paga;
- commit associa recibo PDF;
- commit concilia movimento bancário;
- commit deixa banco parcial quando sobra valor;
- commit grava alias.

### Testes manuais para o utilizador

1. Preparar 3 a 5 PDFs reais de recibos antigos.
2. Incluir pelo menos:
   - um recibo com NIF claro;
   - um recibo com nome apenas;
   - um recibo de mensalidade já existente;
   - um recibo duplicado;
   - um recibo cujo movimento bancário tem valor superior à mensalidade.
3. Importar por ZIP ou pasta.
4. Confirmar extração dos dados.
5. Corrigir manualmente atleta/fatura/movimento quando necessário.
6. Fazer commit.
7. Confirmar fatura paga, recibo associado, PDF acessível e banco parcial/conciliado.

### Resultado esperado

Importação antiga permite arrancar histórico sem partir a verdade financeira.

---

## Sprint F8 — Limpeza de legado e rotas perigosas

### Objetivo

Remover ou bloquear rotas e fluxos antigos que ainda possam criar inconsistência.

### Testes automáticos mínimos

Criar testes para validar:

- rota antiga não marca extrato conciliado diretamente;
- rota antiga não marca movimento pago diretamente;
- rota antiga não marca mensalidade paga diretamente;
- frontend não referencia rotas removidas;
- fluxos novos continuam funcionais.

### Testes manuais para o utilizador

1. Navegar por todos os separadores do Financeiro.
2. Executar os fluxos principais:
   - gerar mensalidade;
   - pagar mensalidade;
   - conciliar banco;
   - criar movimento;
   - emitir recibo manual;
   - importar recibo antigo.
3. Confirmar que nenhum botão antigo ou fluxo duplicado aparece.
4. Confirmar que não há erros de consola/browser.

### Resultado esperado

Módulo deixa de ter caminhos financeiros paralelos perigosos.

---

## Sprint F9 — Dashboard, relatórios e UX final

### Objetivo

Garantir que dashboard e relatórios refletem a verdade financeira canónica.

### Testes automáticos mínimos

Criar testes para validar:

- dashboard calcula dívida corretamente;
- dashboard calcula recebido corretamente;
- pagamentos cancelados não contam;
- relatório de vencidas ignora pagas;
- relatório fiscal separa pendentes, emitidos, erro de dados e cancelados;
- relatórios consideram pagamentos parciais corretamente.

### Testes manuais para o utilizador

1. Abrir Dashboard Financeiro.
2. Comparar totais com mensalidades reais.
3. Confirmar valores:
   - recebido;
   - em aberto;
   - vencido;
   - parcial;
   - pedidos fiscais pendentes;
   - banco por conciliar.
4. Abrir relatórios.
5. Filtrar por período e centro de custo.
6. Confirmar que os números batem com os dados da tab Mensalidades/Banco.
7. Testar em mobile/tablet/PC.

### Resultado esperado

O módulo passa a mostrar números confiáveis para gestão.

---

## Sprint F10 — Testes finais e fecho oficial

### Objetivo

Simular ciclo financeiro real do clube e fechar oficialmente o módulo.

### Testes automáticos mínimos

Criar testes de aceitação para:

- ciclo completo de mensalidade;
- ciclo completo de banco;
- banco parcial;
- várias mensalidades pagas por uma linha bancária;
- crédito por excedente;
- emissão fiscal;
- importação de recibos antigos;
- bloqueios de segurança financeira.

### Testes manuais finais para o utilizador

Executar cenário completo:

1. Criar ou escolher atleta de teste.
2. Atribuir plano de mensalidade.
3. Gerar mensalidades.
4. Criar/importar linha bancária.
5. Conciliar mensalidade.
6. Confirmar pedido fiscal.
7. Marcar recibo Wintouch emitido.
8. Tentar reabrir mensalidade e confirmar bloqueio.
9. Importar recibo antigo.
10. Confirmar associação a atleta, fatura, banco e PDF.
11. Gerar relatório e validar totais.
12. Confirmar que não há erros no browser.

### Resultado esperado

Se testes automáticos e manuais passarem, o Financeiro pode ser marcado como fechado.

---

## 6. Template obrigatório de feedback manual

Depois de cada sprint, o utilizador deve devolver feedback neste formato:

```md
## Feedback manual — Sprint F?

### Teste realizado
Ex: Paguei uma mensalidade parcial pela tab Mensalidades.

### Resultado esperado
Ex: Estado deveria ficar parcial, valor pago 20€, valor em aberto 15€, sem pedido fiscal.

### Resultado observado
Ex: Ficou pago e criou pedido fiscal.

### Evidência
- Screenshot:
- Mensagem de erro:
- URL/ecrã:
- Dados usados:

### Gravidade
- Crítico / Alto / Médio / Baixo
```

Esse feedback deve ser analisado antes da sprint seguinte.

---

## 7. Prompt curta para usar em todas as sprints

```txt
Antes de implementar, lê AGENTS.md, docs/ESTADO_VIVO_DESENVOLVIMENTO.md e docs/PLANO_FECHO_MODULO_FINANCEIRO.md. Esta tarefa faz parte do fecho do módulo Financeiro. Implementa apenas o âmbito da sprint indicada, cria/atualiza testes automáticos, define testes manuais para eu executar, corre as validações possíveis e atualiza o documento vivo com estado, percentagens, ficheiros alterados, testes e pendências.
```

---

## 8. Critério de fecho do módulo Financeiro

O módulo Financeiro só deve ser considerado fechado quando:

- todas as sprints F1 a F10 estiverem concluídas;
- testes automáticos passarem;
- testes manuais principais estiverem validados;
- não existirem fluxos paralelos de pagamento/conciliação;
- documentos vivos estiverem atualizados;
- dashboard e relatórios baterem certo com dados reais;
- emissão fiscal manual estiver segura;
- importação de recibos antigos estiver validada com PDFs reais.
