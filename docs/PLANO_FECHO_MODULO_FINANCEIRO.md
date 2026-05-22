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

> Estado: tecnicamente concluída em 2026-05-20. Validações automáticas passaram. Validação manual do utilizador ainda pendente.

> Atualização F1.1: a liquidação manual continua permitida, mas apenas pelo fluxo canónico e com método de pagamento ativo/configurável. Métodos marcados com `requer_linha_bancaria` só podem ser usados com linha de extrato selecionada.

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

## Sprint F1.1 — Liquidação manual canónica e métodos de pagamento configuráveis

> Estado: tecnicamente concluída em 2026-05-20. Validações automáticas focadas passaram. Validação manual do utilizador ainda pendente.

> Atualização F1.2: F1.2 corrigiu validação manual do modal, bloqueios de UX, robustez CSRF/419 e manteve a liquidação manual canónica.

### Objetivo

Permitir liquidação manual no Financeiro sem reabrir fluxos paralelos, usando sempre o motor canónico e uma lista configurável de métodos de pagamento.

### Regras fechadas nesta sprint

- `PaymentAllocationService` valida que o método existe e está ativo;
- métodos com `requer_linha_bancaria` obrigam a selecionar linha de extrato;
- métodos manuais continuam a permitir liquidação sem linha bancária;
- o Financeiro consome a lista de métodos ativos do backend;
- Configurações > Financeiro passa a gerir métodos, ativação, ordem e exigência de linha bancária.

### Testes automáticos mínimos

Criar testes para validar:

- transferência sem linha bancária falha;
- transferência com linha bancária continua a funcionar;
- dinheiro sem linha bancária cria `Payment` e `PaymentAllocation`;
- pagamento total continua a criar pedido fiscal;
- método inexistente ou inativo é rejeitado;
- o payload do Financeiro expõe apenas métodos ativos;
- CRUD de métodos em Configurações funciona.

### Testes manuais para o utilizador

1. Entrar em Configurações > Financeiro > Métodos de Pagamento.
2. Confirmar que existem pelo menos Transferência, Dinheiro, Multibanco, TPA e Cheque.
3. Marcar um método manual como inativo e confirmar que desaparece do modal de pagamento no Financeiro.
4. Entrar em Financeiro > Mensalidades e abrir o modal de pagamento.
5. Escolher Transferência sem linha bancária e confirmar que o botão fica bloqueado ou a mensagem indica a obrigação da linha.
6. Selecionar uma linha de extrato e confirmar que o valor/manual fica bloqueado pelo montante da linha.
7. Repetir com Dinheiro sem linha bancária e confirmar que a liquidação avança com sucesso.

### Resultado esperado

A liquidação manual continua disponível, mas apenas com métodos configurados e respeitando a regra bancária definida para cada método.

### Fecho operacional F1.2

- o botão de confirmação do modal deve ficar bloqueado quando falta método, falta alocação válida, o valor disponível é inválido ou um método bancário está sem linha de extrato;
- transferência sem linha de extrato deve falhar visualmente no modal e tecnicamente com `422` claro no backend;
- transferência com linha de extrato deve continuar a criar `Payment`, `PaymentAllocation` e atualizar `BankStatement`;
- métodos manuais, como Dinheiro, devem continuar a liquidar sem `bank_statement_id`;
- o update direto de `estado_pagamento` para `pago/parcial` continua proibido fora do fluxo canónico;
- não avançar para F2 antes de repetir os testes manuais F1.2 em browser e após deploy/cache.

---

## Sprint F2 — Correção de bugs técnicos críticos

> Estado: tecnicamente concluída em 2026-05-20. Validações automáticas obrigatórias passaram. Validação manual do utilizador ainda pendente.

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

### Fecho técnico F2

- pesquisa de extratos e sugestões bancárias já não depende de `ilike` hardcoded;
- closures de pesquisa em sugestões bancárias capturam corretamente o operador SQL;
- importação de recibos valida ausência de ZIP quando a diretoria pendente não é usada;
- delete de faturas com rasto financeiro/fiscal foi bloqueado com mensagem explícita;
- faturas manuais respeitam o tipo selecionado e já não forçam `mensalidade` fora dos fluxos próprios;
- frontend deixou de inventar `LancamentoFinanceiro` e stock local antes de persistência confirmada;
- `openMovements` deixou de usar eager loading perigoso com `limit(1)` e mantém a entry correta por movimento;
- rota GET de sugestões bancárias foi movida para evitar shadowing pelo resource `financeiro`.

### Validação automática executada

- `composer dump-autoload`
- `php artisan migrate --pretend`
- `php artisan test --filter=Financeiro`
- `php artisan test --filter=ReceiptImport`
- `php artisan test --filter=PaymentMethod`
- `npm run build`

### Estado para avanço

F2 fica fechada tecnicamente, mas continua pendente de validação manual orientada. Não avançar para F3 antes de recolher esse feedback.

---

## Sprint F2.1 — Correção da validação manual da F2 no fluxo de faturas manuais

> Estado: tecnicamente concluída em 2026-05-21. Validações automáticas obrigatórias passaram. Validação manual do utilizador ainda pendente.

### Objetivo

Corrigir a regra operacional descoberta na validação manual da F2:

- liquidar fatura manual sem exigir nº de recibo/documento externo;
- criar `Payment` + `PaymentAllocation` e pedido fiscal pendente quando a fatura fica totalmente paga;
- deixar o nº Wintouch apenas para a tab Emissão Fiscal;
- permitir reabertura canónica segura antes de existir documento fiscal externo;
- bloquear reabertura direta quando já existe Wintouch/documento externo.

### Regras fechadas nesta sprint

- `numero_recibo`/`receipt_number` deixa de ser requisito do fluxo de pagamento de faturas;
- `FiscalDocumentRequestService::markIssued` retroalimenta `invoice.numero_recibo` e `recibo_emitido_em`;
- `FinanceiroController` passa a expor endpoint canónico `financeiro.invoices.estado` para reabrir faturas pagas/parciais;
- `PaymentAllocationService` passou a centralizar a reabertura segura, com reversão de `PaymentAllocation`, cancelamento seguro de `Payment` órfão, limpeza do pedido fiscal pendente e sincronização do `BankStatement` para `partial/unreconciled` conforme saldo restante;
- update direto de faturas pagas/parciais para `pendente/vencido` continua bloqueado.

### Testes automáticos mínimos

Criar testes para validar:

- pagamento de fatura manual sem nº de recibo cria `Payment` e `PaymentAllocation`;
- pagamento total cria `FiscalDocumentRequest` pendente sem preencher `invoice.numero_recibo`;
- marcar pedido fiscal como emitido retroalimenta `invoice.numero_recibo`;
- reabrir fatura manual paga por dinheiro remove alocação, cancela pagamento órfão e apaga/soft-delete pedido fiscal pendente;
- reabrir fatura manual paga por banco reverte alocação e devolve extrato a `partial/unreconciled` conforme aplicável;
- reabrir fatura com documento externo é bloqueado com `422`;
- update direto de reabertura continua bloqueado.

### Testes manuais para o utilizador

1. Criar uma fatura manual não mensalidade, por exemplo inscrição ou material.
2. Abrir o modal de pagamento dessa fatura e confirmar que o formulário não pede nº de recibo.
3. Liquidar em Dinheiro e confirmar:
   - `Payment`/`PaymentAllocation` criados;
   - estado `pago`;
   - pedido aparece na tab Emissão Fiscal como por tratar;
   - `numero_recibo` continua vazio na fatura.
4. Reabrir a mesma fatura para `pendente` ou `vencido` e confirmar:
   - fatura reaberta;
   - pagamento/alocação revertidos;
   - pedido fiscal pendente removido.
5. Repetir com Transferência usando linha bancária e confirmar que o extrato volta a `partial` ou `unreconciled` conforme o saldo por conciliar restante.
6. Na tab Emissão Fiscal, tratar manualmente a fatura e preencher nº Wintouch.
7. Tentar reabrir de novo a fatura e confirmar a mensagem:
   - `Esta fatura já tem documento fiscal emitido. Para reabrir é necessário anular/cancelar o documento fiscal.`

### Resultado esperado

O pagamento continua canónico, o pedido fiscal continua pendente até emissão manual e a reabertura deixa de criar incoerências antes da emissão fiscal.

### Validação automática executada

- `composer dump-autoload`
- `php artisan migrate --pretend`
- `php artisan test --filter=Financeiro`
- `php artisan test --filter=FiscalDocument`
- `php artisan test --filter=PaymentAllocation`
- `npm run build`

### Estado para avanço

F2 continua pendente de validação manual orientada e F2.1 fecha a correção da regra de nº de recibo/reversão segura antes de Wintouch. Não avançar para F3 antes de recolher feedback manual de F2 e F2.1.

---

## Sprint F2.2 — Correção de UX/CSRF da tab Movimentos

> Estado: tecnicamente concluída em 2026-05-21. Validações automáticas obrigatórias passaram. Validação manual do utilizador ainda pendente.

### Objetivo

Corrigir a falha operacional detetada na validação manual de F2/F2.1 antes de avançar:

- `POST /financeiro/movimentos` devolvia `419` quando a tab Movimentos usava `fetch` sem envelope CSRF/AJAX consistente;
- dropdowns longos, sobretudo de utilizadores, não tinham scroll utilizável;
- o CTA principal da tab precisava de ser normalizado para `Novo Movimento`.

### Regras fechadas nesta sprint

- a tab Movimentos passou a reutilizar helper partilhado de requests Financeiro com `Accept: application/json`, `X-Requested-With: XMLHttpRequest`, `X-CSRF-TOKEN` vindo do `meta[name="csrf-token"]` e `credentials: 'same-origin'`;
- respostas `419` passam a mostrar a mensagem `Sessão expirada. Atualize a página e tente novamente.`;
- criação de movimento continua a aceitar payload JSON e multipart/FormData;
- edição, liquidação e delete de movimento passaram a usar o mesmo envelope de erro/request;
- dropdowns longos ganharam `max-height` e `overflow-y-auto`;
- labels ambíguos da tab foram normalizados para `Movimento`, mantendo a funcionalidade;
- não foi reintroduzida qualquer exigência de nº de recibo na liquidação de faturas.

### Testes automáticos mínimos

Criar ou ajustar testes para validar:

- `POST /financeiro/movimentos` com utilizador autenticado cria movimento por JSON;
- `POST /financeiro/movimentos` aceita multipart/FormData com `items` serializado;
- o endpoint devolve validação JSON clara quando falta campo obrigatório;
- os testes Financeiro existentes continuam a passar.

### Testes manuais para o utilizador

1. Entrar em Financeiro > tab Movimentos.
2. Confirmar que o botão principal mostra `Novo Movimento`.
3. Abrir criação de movimento e usar o dropdown de utilizadores com lista longa.
4. Confirmar que o dropdown tem scroll e continua fácil de pesquisar/selecionar.
5. Criar um movimento sem ficheiro anexado.
6. Confirmar que o movimento é criado sem erro `419`.
7. Repetir a criação anexando documento original para forçar envio multipart/FormData.
8. Editar um movimento existente e confirmar persistência sem erro `419`.
9. Se a sessão expirar, repetir a ação e confirmar a mensagem:
   `Sessão expirada. Atualize a página e tente novamente.`

### Resultado esperado

A tab Movimentos volta a ser utilizável em browser sem regressão das regras canónicas de pagamento já fechadas nas sprints anteriores.

### Validação automática executada

- `composer dump-autoload`
- `php artisan migrate --pretend`
- `php artisan test --filter=Financeiro`
- `npm run build`

### Estado para avanço

F2, F2.1 e F2.2 ficam tecnicamente fechadas, mas continuam pendentes de validação manual orientada no browser. Não avançar para F3 antes de repetir esses testes e recolher feedback do utilizador.

---

## Sprint F2.3 — Remoção definitiva do nº de recibo do pagamento de faturas

> Estado: tecnicamente concluída em 2026-05-22. Validações automáticas obrigatórias passaram. Validação manual do utilizador ainda pendente.

### Objetivo

Fechar definitivamente a regressão observada na validação manual de F2.1:

- garantir que o pagamento de faturas manuais e mensalidades não depende de `numero_recibo`, `receipt_number`, `external_document_number` nem comprovativo;
- manter o fluxo canónico de pagamento em `financeiro.payments.allocate` para faturas manuais e no endpoint canónico de mensalidades para `mensalidade`;
- reservar o nº externo apenas para a tab Emissão Fiscal ao marcar o pedido como emitido.

### Regras fechadas nesta sprint

- a tab Faturas deixou de transportar `numero_recibo` nos updates administrativos e passou a nomear explicitamente o modal como fluxo de pagamento;
- `StoreInvoiceRequest` e `UpdateInvoiceRequest` deixaram de aceitar `numero_recibo` na superfície administrativa de criação/edição;
- `FinanceiroController::store` e `FinanceiroController::update` deixaram de escrever `invoice.numero_recibo` fora da Emissão Fiscal;
- o pagamento canónico continua a criar `Payment`, `PaymentAllocation` e `FiscalDocumentRequest` pendente sem preencher `invoice.numero_recibo`;
- `FiscalDocumentRequestController::markIssued` continua a exigir `external_document_number` e a ser o único ponto que retroalimenta `invoice.numero_recibo`;
- `Financeiro/Index.tsx` volta a passar `faturasState` completo para `FaturasTab`, impedindo que faturas manuais escapem ao modal canónico;
- `FaturasTab.tsx` filtra apenas `paymentMethods` ativos, repara automaticamente um método inválido/inativo para o default ativo e só mostra seleção de linha bancária quando `requer_linha_bancaria` está ativo;
- o botão de confirmar fica bloqueado quando um método bancário ativo não tem linha disponível ou selecionada, sem cair no fluxo legado `financeiro.movimentos.liquidar`.

### Testes automáticos mínimos

Criar ou ajustar testes para validar:

- pagamento integral de fatura manual `material` sem nº de recibo;
- pagamento integral de fatura manual `inscricao` sem nº de recibo;
- pagamento integral de `mensalidade` sem nº de recibo;
- criação de `Payment` + `PaymentAllocation` + `FiscalDocumentRequest` pendente quando a fatura fica totalmente paga;
- `invoice.numero_recibo` continua `null` até à emissão fiscal;
- `mark-issued` continua a falhar sem `external_document_number`;
- a tab Faturas usa apenas métodos ativos no modal;
- a seleção de linha bancária aparece apenas quando o método o exige e bloqueia a confirmação quando não existe linha válida;
- a tab Faturas continua a usar `financeiro.payments.allocate` e não recai em `financeiro.movimentos.liquidar`.

### Testes manuais para o utilizador

1. Entrar em Financeiro > Faturas.
2. Criar uma fatura manual do tipo `material`.
3. Abrir `Registar Pagamento` dessa fatura e confirmar que o modal não pede nº de recibo.
4. Liquidar em Dinheiro sem preencher qualquer nº externo.
5. Confirmar:
   - a fatura fica `pago`;
   - o pagamento é registado;
   - o pedido aparece na tab Emissão Fiscal como pendente;
   - `numero_recibo` continua vazio na fatura.
6. Repetir o mesmo teste com uma fatura `inscricao`.
7. Repetir o mesmo teste com uma `mensalidade`.
8. Confirmar que só aparecem métodos de pagamento ativos no modal.
9. Escolher um método com exigência de linha bancária, por exemplo Transferência, sem linha disponível/selecionada, e confirmar que o botão fica bloqueado com aviso explícito.
10. Escolher um método manual ativo, por exemplo Dinheiro, e confirmar que a secção de linha bancária desaparece e o pagamento continua possível.
11. Na tab Emissão Fiscal, tentar marcar um pedido como emitido sem nº externo.
12. Confirmar que o backend devolve erro de validação para `external_document_number`.
13. Preencher o nº Wintouch e confirmar então que `invoice.numero_recibo` é atualizado.

### Resultado esperado

O pagamento de faturas volta a depender apenas do fluxo canónico de pagamentos, com o modal a respeitar apenas métodos ativos e a lógica bancária configurada, enquanto a numeração fiscal externa permanece exclusiva da Emissão Fiscal.

### Validação automática executada

- `composer dump-autoload`
- `php artisan migrate --pretend`
- `php artisan test --filter=FaturasTabFlowContractTest`
- `php artisan test --filter=PaymentAllocation`
- `php artisan test --filter=FiscalDocument`
- `php artisan test --filter=Financeiro`
- `php artisan test --filter=PaymentMethod`
- `npm run build`

### Estado para avanço

F2, F2.1, F2.2 e F2.3 ficam tecnicamente fechadas, mas continuam pendentes de validação manual orientada no browser. Não avançar para F3 antes de repetir esses testes e recolher feedback do utilizador.

---

## Sprint F2.4 — Correção limitada do popup Liquidar Movimento

> Estado: tecnicamente concluída em 2026-05-22. Validações automáticas obrigatórias passaram. Validação manual do utilizador confirmada no browser.

### Objetivo

Corrigir exclusivamente o popup `Liquidar Movimento` da tab Movimentos, sem reabrir o fluxo de Faturas/Mensalidades:

- remover a obrigatoriedade de `numero_recibo` no popup e no endpoint local de liquidação;
- usar apenas métodos de pagamento ativos;
- mostrar seleção de linha bancária apenas para métodos com `requer_linha_bancaria=true`;
- bloquear confirmação de transferência sem linha bancária e permitir dinheiro sem linha bancária.

### Regras fechadas nesta sprint

- `MovimentosTab.tsx` passou a ler `paymentMethods` e `extratos` dos `page props` do Inertia, sem alterar `Index.tsx` nem o fluxo de Faturas;
- o popup deixou de mostrar o campo `Numero do Recibo` e removeu o texto que o tornava obrigatório;
- a lista de métodos do popup passou a usar apenas métodos ativos e ordenados;
- a seleção de linha bancária aparece apenas quando o método ativo exige conciliação bancária;
- o botão de confirmar fica bloqueado quando falta linha bancária obrigatória ou quando não existem linhas disponíveis;
- `FinanceiroController::liquidarMovimento` passou a aceitar `numero_recibo` nulo e `bank_statement_id`, delegando a validação do método e da regra bancária para o motor financeiro existente.

### Testes automáticos mínimos

Criar ou ajustar testes para validar:

- liquidação de movimento com `dinheiro` sem `numero_recibo`;
- liquidação de movimento com método bancário sem `bank_statement_id` falha com `422`;
- liquidação de movimento com método inativo falha com `422`;
- liquidação de despesa com `transferencia` e `bank_statement_id` concilia o extrato e não cria pedido fiscal de receita;
- liquidação de receita sem `numero_recibo` mantém `numero_recibo` nulo e pode criar pedido fiscal pendente quando existem dados fiscais mínimos.

### Testes manuais para o utilizador

1. Entrar em Financeiro > Movimentos.
2. Escolher um movimento pendente e clicar em `Liquidar`.
3. Confirmar que o popup já não mostra nem exige `Numero do Recibo`.
4. Confirmar que o seletor de métodos lista apenas métodos ativos.
5. Escolher `Transferencia` e confirmar que surge a seleção de linha bancária.
6. Tentar confirmar sem linha bancária e confirmar que o botão fica bloqueado com aviso explícito.
7. Selecionar uma linha bancária e confirmar que a liquidação avança.
8. Repetir com `Dinheiro` e confirmar que a secção bancária desaparece e a liquidação continua possível.
9. Repetir com um movimento de receita e confirmar que não aparece nº Wintouch e que o pedido fiscal fica pendente quando o movimento tem dados fiscais mínimos.
10. Repetir com um movimento de despesa e confirmar que não é criado pedido fiscal de receita.

### Resultado esperado

O popup `Liquidar Movimento` passa a respeitar a configuração ativa de métodos e a regra bancária definida para cada método, sem depender de `numero_recibo` e sem alterar o fluxo canónico de Faturas/Mensalidades.

### Validação manual confirmada

O utilizador confirmou manualmente no browser que:

- Financeiro > Movimentos > `Liquidar` já não obriga nº de recibo;
- métodos inativos já não aparecem no popup;
- `Dinheiro` não pede linha bancária;
- `Dinheiro` liquida sem nº de recibo;
- `Transferência` mostra linhas bancárias;
- `Transferência` sem linha bancária bloqueia;
- `Transferência` com linha bancária liquida e concilia.

### Validação automática executada

- `php artisan test --filter=ManualExpenseFlowsTest`
- `php artisan test --filter=PaymentAllocation`
- `php artisan test --filter=Financeiro`
- `npm run build`

### Estado para avanço

F2.4 fica validada manualmente no browser para o fluxo de Movimentos testado. Em conjunto com a validação manual confirmada da F2.5 e com a regressão validada em Mensalidades, F2/F2.1/F2.2/F2.3/F2.4/F2.5 ficam fechadas operacionalmente na parte validada desta sequência, mantendo apenas pendências que não pertencem a estas sprints. Não avançar para F3.

---

## Sprint F2.5 — Reabertura controlada de movimentos liquidados

> Estado: tecnicamente concluída em 2026-05-22. Validações automáticas obrigatórias passaram. Validação manual do utilizador confirmada no browser.

### Objetivo

Fechar a lacuna deixada pela F2.4 na tab Movimentos, permitindo reabrir movimentos liquidados sem reintroduzir escrita direta insegura:

- permitir reabertura apenas de movimentos `pago`, `parcial` e `pago_parcial` para `pendente` ou `vencido`;
- usar um endpoint canónico próprio para reverter pagamentos, alocações, conciliação e pedidos fiscais pendentes;
- bloquear a reabertura quando já existe documento fiscal emitido ou nº externo associado ao movimento;
- manter proibida a alteração direta do estado por `update` administrativo.

### Regras fechadas nesta sprint

- `FinancialSettlementService` passou a expor `reopenMovement()` como fluxo canónico de reabertura de movimentos;
- a reabertura só é permitida a partir de estados liquidados e apenas para `pendente` ou `vencido`;
- `PaymentAllocation` confirmadas ligadas ao movimento são canceladas por soft delete e respetivos registos de `MapaConciliacao` são removidos;
- pagamentos órfãos são cancelados apenas quando é seguro fazê-lo, reaproveitando o mesmo critério canónico aplicado noutros fluxos;
- pedidos fiscais pendentes ligados às `FinancialEntry` do movimento são removidos por soft delete;
- extratos bancários afetados são recalculados e voltam a `partial` ou `unreconciled` conforme o remanescente;
- movimentos com `numero_recibo` preenchido ou com documento fiscal externo emitido ficam bloqueados com erro `422` claro;
- `FinanceiroController::updateMovimento` continua a bloquear reabertura direta fora do endpoint canónico;
- a tab Movimentos passou a expor ações explícitas para `Reabrir para pendente` e `Reabrir para vencido` no modal de edição, com aviso específico quando existe risco fiscal/Wintouch.

### Testes automáticos mínimos

Criar ou ajustar testes para validar:

- reabertura de receita liquidada em dinheiro remove alocação, cancela pagamento órfão seguro e apaga pedido fiscal pendente;
- reabertura de receita liquidada por transferência reabre o extrato e remove o pedido fiscal pendente;
- reabertura de despesa liquidada reverte pagamento sem criar pedido fiscal indevido;
- reabertura falha com `422` quando o movimento já tem documento fiscal emitido ou nº externo associado;
- update direto para `pendente` continua bloqueado fora do endpoint canónico.

### Testes manuais para o utilizador

1. Entrar em Financeiro > Movimentos.
2. Escolher um movimento de receita já liquidado em Dinheiro e abrir `Editar`.
3. Confirmar que surgem as ações `Reabrir para pendente` e `Reabrir para vencido`.
4. Reabrir para `pendente` e confirmar:
   - o estado volta a `pendente`;
   - `metodo_pagamento` e `numero_recibo` ficam vazios;
   - o pedido na tab Emissão Fiscal desaparece se ainda estava pendente.
5. Escolher um movimento liquidado por Transferência com linha bancária conciliada.
6. Reabrir para `vencido` e confirmar:
   - o movimento passa para `vencido`;
   - o extrato volta a `partial` ou `unreconciled`;
   - a linha volta a ficar disponível para nova conciliação.
7. Escolher um movimento que já tenha nº Wintouch/documento emitido.
8. Tentar reabrir e confirmar que a operação é bloqueada com mensagem explícita.
9. Tentar alterar o mesmo estado por uma edição administrativa normal e confirmar que continua bloqueado.

### Resultado esperado

A tab Movimentos passa a ter reabertura segura e explícita para movimentos liquidados, revertendo apenas o rasto financeiro que ainda é reversível e bloqueando os casos já fechados fiscalmente.

### Validação manual confirmada

O utilizador confirmou manualmente no browser que:

- Financeiro > Movimentos > `Liquidar` já funciona;
- o movimento liquidado passa para a tab Emissão Fiscal;
- reabrir o movimento para `pendente` e `vencido` já funciona;
- ao reabrir, o pedido fiscal pendente é removido quando ainda não existe nº Wintouch;
- a regra ficou confinada ao fluxo de Movimentos, sem mexer em Mensalidades.

### Regressão de Mensalidades validada manualmente

O utilizador confirmou manualmente no browser que:

- `Dinheiro` sem banco liquida sem nº de recibo;
- `Transferência` sem banco bloqueia;
- `Transferência` com banco liquida e concilia;
- a desconciliação na tab Banco reabre a mensalidade e remove o pedido fiscal pendente.

### Validação automática executada

- `composer dump-autoload`
- `php artisan migrate --pretend`
- `php artisan test --filter=ManualExpenseFlowsTest`
- `php artisan test --filter=PaymentAllocation`
- `php artisan test --filter=Financeiro`
- `npm run build`

### Estado para avanço

F2.4 e F2.5 ficam validadas manualmente no browser e a regressão crítica de Mensalidades desta sequência fica confirmada. F2/F2.1/F2.2/F2.3/F2.4/F2.5 ficam fechadas operacionalmente na parte validada desta sequência, mantendo apenas pendências que não pertencem a estas sprints. Não avançar para F3.

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
