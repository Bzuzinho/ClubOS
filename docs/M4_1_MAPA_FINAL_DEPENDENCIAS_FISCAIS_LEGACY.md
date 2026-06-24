# M4.1 — Mapa Final de Dependências Fiscais Legacy

## Estado da M4.1

A frente M4.1 fechou a base crítica de dados fiscais com o `MemberFiscalDataResolver` já criado e integrado nos pontos de emissão fiscal relevantes.

- `MemberFiscalDataResolver` foi criado para centralizar a leitura fiscal canónica do membro.
- `FiscalEmissionQueueService` passou a usar o resolver para `FinancialEntry` antes de criar pedidos fiscais.
- `FiscalDocumentRequestService` passou a usar o resolver para faturas na criação de pedidos fiscais.
- `dados_pessoais` tem prioridade para `nome`, `nif`, `morada`, `codigo_postal` e `localidade`.
- `users` permanece como fallback legacy.
- A auditoria de produção continuou limpa durante a validação desta frente.

## Contrato fiscal atual

Para emissão e pedidos fiscais novos, a fonte primária deve ser o `MemberFiscalDataResolver`.

O contrato atual é:

1. `MemberFiscalDataResolver` lê primeiro `dados_pessoais`.
2. Se faltar `dados_pessoais`, usa os campos legacy de `users`.
3. Se ainda faltar o nome canónico, usa `users.name` como fallback final.
4. Os campos fiscais tratados são:
   - nome
   - nif
   - morada
   - codigo_postal
   - localidade

Isto mantém compatibilidade com membros antigos sem quebrar o fluxo canónico de emissão fiscal.

## Pontos já tratados

1. `app/Services/Members/MemberFiscalDataResolver.php`
2. `app/Services/Financeiro/FiscalEmissionQueueService.php`
3. `app/Services/Financeiro/FiscalDocumentRequestService.php`
4. `tests/Feature/Membros/MemberFiscalDataResolverTest.php`
5. `tests/Feature/Financeiro/FiscalEmissionQueueServiceCanonicalDataTest.php`
6. `tests/Feature/Financeiro/FiscalDocumentRequestFlowTest.php`

## Dependências legacy restantes a auditar

A auditoria final baseia-se na pesquisa por:

- `nome_completo`
- `name`
- `nif`
- `morada`
- `codigo_postal`
- `localidade`

As áreas abaixo ainda mostram dependências legacy, mas com impacto diferente entre emissão fiscal, matching, auditoria e simples apresentação de dados.

### `app/Http/Controllers/Financeiro/FiscalDocumentRequestController.php`

- Continua a carregar `user:id,name,nome_completo,email,nif,morada,codigo_postal,localidade`.
- A pesquisa interna ainda procura por `name`, `nome_completo` e `nif`.
- Risco: baixo a médio, porque o controller serve listagem e pesquisa operacional, não a construção da emissão fiscal.

### `app/Http/Controllers/FinanceiroController.php`

- Continua a expor `user:id,name,nome_completo,email,nif,morada,codigo_postal,localidade` em payloads e relações.
- Mantém ordenação e filtros por `nome_completo`, `nif` e `morada` em superfícies de consulta.
- Risco: baixo, porque o uso é de payload/listagem, não de criação fiscal direta.

### `app/Services/Financeiro/PaymentAllocationService.php`

- Ainda usa `invoice->user?->nome_completo ?? $invoice->user?->name ?? $invoice->id` em descrições de entrada financeira.
- Verificação feita: o serviço encaminha o fluxo fiscal para `FiscalDocumentRequestService->createFromInvoice(...)` e não constrói pedidos fiscais diretamente.
- Risco: crítico apenas se voltar a construir dados fiscais fora do resolver; no estado atual fica em médio, porque ainda referencia nome legacy em descrições e logs operacionais.

### `app/Services/Financeiro/LegacyConsistencyService.php`

- O reparo legado chama `FiscalDocumentRequestService->createFromInvoice(...)` e não monta payload fiscal por conta própria.
- O ponto a vigiar é a dependência indireta do fluxo de reparação sobre o serviço fiscal canónico.
- Risco: crítico apenas por tocar em reparação de faturas com efeito fiscal; funcionalmente, no estado atual, a criação fiscal já fica delegada ao contrato canónico.

### `app/Services/Financeiro/BankReconciliationSuggestionService.php`

- Continua a comparar `user:id,nome_completo,name`, `numero_socio` e `nif` para sugestão de correspondências.
- Usa `matched_name` e `matched_nif` como sinais de heurística.
- Risco: médio, porque impacta matching bancário, não emissão fiscal direta.

### `app/Services/Financeiro/BankReconciliationAuditService.php`

- Continua a pesquisar e ordenar por `nome_completo` e `name` em contexto de auditoria.
- O consumo é analítico e operacional, não fiscal.
- Risco: baixo.

### `app/Services/Financeiro/ReceiptMatchingService.php`

- Faz matching por `nif`, `nome_completo` e fallback para `name`.
- Este serviço é sensível a compatibilidade histórica de recibos e importações.
- Risco: médio.

### `app/Services/Financeiro/FinanceReportService.php`

- Usa `nome_completo` para relatórios e agregações por utilizador.
- O objetivo é reporte, não emissão fiscal.
- Risco: baixo.

### `app/Services/Financeiro/MonthlyFeeGenerationService.php`

- Ordena e processa utilizadores por `nome_completo` na geração de mensalidades.
- A dependência é operacional e de apresentação do lote.
- Risco: baixo.

### `tests/Feature/Financeiro/*`

- Os testes ainda usam fixtures com `nome_completo`, `nif`, `morada`, `codigo_postal` e `localidade` legacy.
- Isto é aceitável enquanto a cobertura documenta compatibilidade e regressão.
- Risco: baixo, desde que os testes continuem a validar o contrato canónico novo.

## Classificação de risco

### Crítico

Dependências que podem afetar emissão fiscal, criação de pedidos fiscais, recibos ou documentos legais.

- `FiscalDocumentRequestService` — tratado.
- `FiscalEmissionQueueService` — tratado.
- `PaymentAllocationService` — verificar apenas porque ainda escreve descrições com nome legacy; no estado atual não constrói pedidos fiscais diretamente.
- `LegacyConsistencyService` — verificar apenas porque reexecuta reparações com efeito fiscal; no estado atual apenas chama `createFromInvoice`.

### Médio

Dependências que podem afetar matching bancário, conciliação, identificação de pagamentos, sugestões automáticas ou associação por NIF/nome.

- `BankReconciliationSuggestionService`
- `ReceiptMatchingService`
- `PaymentAllocationService` quando usa nome/NIF para logs, descrições ou matching indireto

Estas áreas podem continuar a usar `users` legacy temporariamente porque são superfícies de matching e pesquisa, não emissão fiscal direta. Ainda assim, devem ser candidatas a resolvers próprios ou a queries canonical-aware na próxima frente.

### Baixo

Dependências de display, listagens, auditoria, relatórios ou ordenação.

- `BankReconciliationAuditService`
- `FinanceReportService`
- `MonthlyFeeGenerationService`
- `FinanceiroController` quando usado apenas como payload/listagem
- testes de fixtures com `nome_completo` e `nif` legacy

Estas dependências não bloqueiam a M4.1 porque não são a fonte final da emissão fiscal.

## Decisão técnica

A frente crítica de emissão e pedidos fiscais fica fechada quando:

- `FiscalEmissionQueueService` usa o resolver;
- `FiscalDocumentRequestService` usa o resolver;
- os testes fiscais relevantes passam;
- a produção mantém auditoria limpa.

As restantes dependências legacy no módulo financeiro não devem ser alteradas em bloco. A redução deve continuar por superfície funcional, com validação e testes dedicados.

## Próximas fases recomendadas

### M4.2 — Matching e conciliação canonical-aware

Objetivo:

- avaliar `BankReconciliationSuggestionService` e `ReceiptMatchingService`;
- criar resolver ou helper de pesquisa para nome/NIF;
- evitar alterar heurísticas sem testes;
- priorizar NIF e nome em `dados_pessoais`;
- manter fallback `users`.

### M4.3 — Importação de membros

Objetivo:

- separar payload canonical/legacy em `MemberImportService`;
- adicionar testes específicos.

### M4.4 — API Users

Objetivo:

- reduzir escritas pessoais legacy nas APIs;
- cobrir `store` e `update` com testes.

### M4.5 — Observabilidade de fallback

Objetivo:

- identificar quando o sistema ainda cai para `users` por falta de `dados_pessoais` ou `dados_configuracao`.

## O que não fazer

- não remover colunas `users`;
- não remover fallback;
- não alterar matching bancário sem testes;
- não alterar recibos/importação em massa sem testes;
- não fazer `DROP COLUMN`;
- não usar `migrate:fresh`, `migrate:refresh`, `migrate:reset` ou `db:wipe`;
- não usar `git add .`.

## Critério de fecho M4.1

Fechar M4.1 se:

- M4.1-F1, M4.1-F2, M4.1-F3 e M4.1-F4 existirem;
- os testes relevantes de Membros e Financeiro passarem;
- a produção tiver sido validada;
- as dependências restantes estiverem classificadas;
- a próxima frente estiver definida.

## Conclusão

A M4.1 fecha a frente crítica de dados fiscais no caminho canónico sem remover compatibilidade legacy. O sistema passa a privilegiar `dados_pessoais` para emissão fiscal nova, mantém `users` como fallback controlado e deixa para fases seguintes as superfícies de matching, auditoria, relatórios e importação.