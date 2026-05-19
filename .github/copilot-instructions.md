# Instruções do GitHub Copilot — ClubOS

Este repositório usa um documento vivo para manter o contexto funcional e técnico do projeto.

Antes de sugerir, gerar ou alterar código para o ClubOS, consultar:

```txt
docs/ESTADO_VIVO_DESENVOLVIMENTO.md
```

Consultar também, quando aplicável:

```txt
AGENTS.md
```

---

## Regras obrigatórias

1. Não assumir o estado do projeto sem consultar o documento vivo.
2. Não criar funcionalidades desalinhadas com as prioridades e riscos registados.
3. Sempre que uma alteração tiver impacto funcional, atualizar `docs/ESTADO_VIVO_DESENVOLVIMENTO.md`.
4. Se a alteração não exigir atualização do documento vivo, justificar isso no resumo.
5. Não duplicar fluxos de negócio existentes sem motivo técnico forte.
6. No módulo financeiro, evitar múltiplas fontes de verdade para pagamentos, faturas, conciliações e pedidos fiscais.
7. No módulo de membros, evitar concentrar em `users` dados que pertencem a tabelas especializadas.
8. No portal do atleta/família, preservar lógica mobile-first e acesso simples.

---

## Financeiro

O fluxo financeiro deve tender para um caminho canónico.

Antes de alterar pagamentos, faturas, banco, recibos, movimentos, extratos ou emissão fiscal, verificar impacto em:

- `App\Services\Financeiro\PaymentAllocationService`
- `Invoice`
- `Payment`
- `PaymentAllocation`
- `BankStatement`
- `MapaConciliacao`
- `FinancialEntry`
- `Movement`
- `FiscalDocumentRequest`
- `ReceiptImport*`

Não criar novo fluxo paralelo de liquidação sem atualizar o documento vivo e justificar a decisão.

---

## Atualização do documento vivo

Quando atualizar o documento vivo, preferir este formato no histórico:

```md
| AAAA-MM-DD | Módulo | Desenvolvimento / análise | Evidência | Percentagem antes | Percentagem depois | Pendências |
```

A evidência deve apontar para ficheiros alterados, serviços, controllers, migrations, testes ou PRs.

---

## Validações recomendadas

Depois de alterações relevantes, sugerir ou executar quando possível:

```bash
composer dump-autoload
php artisan migrate --pretend
php artisan test
npm run build
```

Se não forem executadas, mencionar no resumo final.

---

## Resumo esperado

No fim de cada tarefa relevante, indicar:

- o que foi alterado;
- ficheiros principais;
- impacto funcional;
- se o documento vivo foi atualizado;
- validações executadas;
- pendências.
