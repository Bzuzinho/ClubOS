# Prompt Base para GitHub Copilot — ClubOS

Este ficheiro existe para apoiar o fluxo real de desenvolvimento do projeto, em que as tarefas são normalmente executadas colando uma prompt no chat do Copilot.

Antes de qualquer prompt técnica específica, usar este bloco como contexto inicial ou garantir que a prompt específica contém estas regras.

---

## Prompt base obrigatória

```txt
Estás a trabalhar no repositório ClubOS.

Antes de propor ou alterar código, lê obrigatoriamente:

1. AGENTS.md
2. docs/ESTADO_VIVO_DESENVOLVIMENTO.md
3. .github/copilot-instructions.md

Usa estes ficheiros como fonte de verdade do estado funcional e técnico do projeto.

Não assumas que uma funcionalidade está concluída apenas porque existe uma rota, página React, controller ou migration. Confirma sempre o fluxo completo: rota, controller/service/action, model/migration, frontend, validações e testes quando existirem.

Sempre que a tarefa tiver impacto funcional, técnico ou de arquitetura, atualiza também docs/ESTADO_VIVO_DESENVOLVIMENTO.md com:

- módulo afetado;
- o que foi desenvolvido ou corrigido;
- ficheiros relevantes;
- percentagem antes e depois, se aplicável;
- riscos ou pendências que continuam abertas;
- validações executadas ou não executadas.

No final da tarefa, responde sempre com:

1. o que foi alterado;
2. ficheiros principais;
3. impacto funcional;
4. se o documento vivo foi atualizado;
5. validações executadas;
6. pendências.

No módulo financeiro, não cries fluxos paralelos de pagamento, liquidação, conciliação ou emissão fiscal sem verificar primeiro o fluxo canónico existente, em especial App\Services\Financeiro\PaymentAllocationService.

No módulo de membros, evita colocar novamente em users dados que pertencem a tabelas especializadas.

No portal atleta/família, mantém uma lógica mobile-first, simples e sem ruído administrativo.
```

---

## Como usar

Quando fores pedir uma tarefa ao Copilot, começa por colar o bloco acima e, de seguida, acrescenta a tarefa concreta.

Exemplo:

```txt
[COLAR PROMPT BASE]

Tarefa concreta:
Quero corrigir a importação de recibos antigos para permitir conciliação parcial do movimento bancário quando o valor do extrato cobre mais do que uma mensalidade.

Antes de implementar, analisa o estado atual do módulo Financeiro, ReceiptImportController, ReceiptImportService, ReceiptMatchingService, ReceiptCommitService, PaymentAllocationService, BankStatement e MapaConciliacao.

Depois implementa a solução, valida e atualiza docs/ESTADO_VIVO_DESENVOLVIMENTO.md.
```

---

## Versão curta para prompts rápidas

Quando a tarefa for pequena, pode ser usada esta versão resumida:

```txt
Antes de mexer no código, lê AGENTS.md e docs/ESTADO_VIVO_DESENVOLVIMENTO.md. Usa-os como fonte de verdade. Se a alteração tiver impacto funcional, atualiza o documento vivo no fim. Não cries fluxos paralelos, especialmente no Financeiro. No resumo final indica ficheiros alterados, impacto, validações e pendências.
```

---

## Nota importante

O Copilot pode não cumprir sempre automaticamente todas as instruções se a prompt concreta for ambígua ou demasiado curta.

Por isso, para tarefas relevantes, usar sempre a prompt base completa.
