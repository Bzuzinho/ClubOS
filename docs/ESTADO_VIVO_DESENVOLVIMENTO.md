# Estado Vivo de Desenvolvimento — ClubOS

> Documento vivo para acompanhar a evolução funcional e técnica do ClubOS.
>
> Última atualização inicial: 2026-05-19.
>
> Fonte da análise inicial: leitura estática do repositório GitHub `Bzuzinho/ClubOS`, ramo `main`.

---

## 1. Objetivo deste documento

Este ficheiro deve funcionar como a fonte de verdade para acompanhar o desenvolvimento do ClubOS ao longo do tempo.

Sempre que existir um novo desenvolvimento, correção, refatoração ou integração, este documento deve ser atualizado com:

- funcionalidade afetada;
- subfuncionalidades concluídas;
- evidência técnica no código;
- percentagem estimada antes e depois;
- riscos ou pendências que continuam abertas.

A intenção é evitar perda de contexto entre conversas, prompts, commits, sprints e decisões técnicas.

---

## 2. Estado global estimado

| Área | Estado estimado |
|---|---:|
| Implementação global da aplicação | 60% a 65% |
| Maturidade técnica global | Média / avançada |
| Maturidade funcional global | Média |
| Maturidade para produção operacional plena | Ainda incompleta |

### Leitura geral

O ClubOS já não está numa fase meramente inicial. O repositório online apresenta uma aplicação Laravel + Inertia React com vários módulos reais: membros, financeiro, desportivo, eventos, portal, loja, logística, comunicação, configurações e patrocínios.

A aplicação já tem bastante estrutura, migrations, models, controllers, services, actions e páginas React/Inertia. Contudo, ainda existem sinais de duplicação de fluxos, sobretudo no financeiro, e áreas que parecem estruturalmente criadas mas ainda não totalmente consolidadas em processos fechados de ponta a ponta.

---

## 3. Stack técnica identificada

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 11 |
| PHP | PHP 8.3 |
| Frontend | React 19 + TypeScript |
| Navegação | Inertia.js |
| Build | Vite |
| UI | Tailwind CSS, Radix UI, Headless UI, Lucide, Phosphor Icons |
| Dados / estado frontend | Axios, React Query |
| Gráficos | Recharts |
| Autenticação | Laravel auth, Sanctum, Breeze |
| Rotas frontend/backend | Laravel routes + Ziggy |
| Cache / performance | Redis/Predis, cache Laravel |
| Exportações | xlsx |

---

## 4. Grelha viva de funcionalidades

| Módulo / Área | Funcionalidades e subfuncionalidades | Integrações / Relações | Estado estimado | Observações |
|---|---|---|---:|---|
| Base técnica / arquitetura | Laravel, Inertia, React, Vite, Tailwind, Radix, Ziggy, Sanctum, Redis | Todos os módulos | 80% | Base sólida, mas convém reforçar organização de rotas e documentação técnica. |
| Autenticação e acessos | Login, middleware `auth`, `verified`, `module.access`, `permission.access`, tipos de utilizador | Configurações, Dashboard, Portal, módulos admin | 70% | Precisa de matriz formal de permissões por perfil. |
| Dashboard / entrada por perfil | Dashboard admin e vista atleta/portal; estatísticas, eventos recentes, receitas, atividade | Membros, Eventos, Financeiro, Desportivo, Família | 66% | Bem encaminhado; F3.2.1 restaurou linguagem operacional nas tabs de membro (`Conta Corrente`, `Mensalidades`, `Movimentos`, `Valor Pago`) mantendo `CurrentAccountService` como leitura canónica. Em F3.3, o payload operacional do Dashboard atleta deixou também de transportar `conta_corrente_manual`. Falta validação manual final de UX e indicadores. |
| Portal do atleta / utilizador | Perfil, treinos, eventos, pagamentos, resultados, documentos, comunicados, família, loja | Desportivo, Eventos, Financeiro, Documentos, Comunicação, Loja | 63% | Estrutura existe; F3.2.1 manteve Pagamentos e Família ligados a `CurrentAccountService`, mas retirou `Saldo manual legado` e a terminologia de dívida como linguagem principal, privilegiando `Conta Corrente`, `Em aberto` e `Crédito disponível`. Em F3.3, o Portal Profile deixou também de usar saldo manual legado como `Conta corrente`. Falta validação manual de experiência real em mobile. |
| Gestão de Membros / Pessoas | Lista, criação, edição, número de sócio, tipos, escalões, uploads, menor, EE/educando, dados financeiros, centros de custo | Financeiro, Desportivo, Configurações, Comunicação, Família | 75% | Forte, mas exige normalização clara entre `users` e tabelas especializadas. |
| Importação de membros | Template, preview e importação | Membros, user types, escalões | 56% | F3.3 passou a ignorar `conta_corrente_manual` como campo de importação operacional, devolvendo apenas aviso de compatibilidade. Confirmar comportamento com ficheiros reais. |
| Documentos de membro | Gestão de documentos no admin e portal | Membros, Portal, RGPD, Configuração | 65% | Precisa de política clara de tipos obrigatórios e permissões. |
| Família / EE / educandos | Portal família, pesquisa e associação de membros familiares | Membros, Portal, Dashboard, Financeiro familiar | 60% | F3.2.1 passou a somar dívida aberta real por educando/família com `CurrentAccountService`, excluindo faturas futuras/ocultas, mantendo crédito disponível em separado e retirando saldo manual legado da superfície operacional. Falta validação manual orientada. |
| Desportivo | Dashboard, planeamento, treinos, presenças, cais, competições, relatórios | Eventos, Membros, Resultados, Financeiro, Configurações | 70% | Módulo bastante avançado, mas ainda precisa de validação funcional completa. |
| Planeamento desportivo | Épocas, macrociclos, mesociclos, microciclos, objetivos | Escalões, treinos, competições | 65% | Boa base; falta fechar UX e relatórios associados. |
| Treinos e presenças | Criar, agendar, editar, duplicar, apagar treino; atletas; presenças; cais; métricas | Eventos, atletas, portal | 70% | Núcleo forte; confirmar sincronização com eventos e ficha de atleta. |
| Competições / resultados | Competições, inscrições, resultados, splits, provas, resultados por equipa | Eventos, Desportivo, Relatórios | 60% | Estrutura existe; maturidade funcional a confirmar. |
| Eventos | CRUD, participantes, estados, estatísticas, portal de eventos | Membros, Comunicação, Desportivo, Financeiro | 65% | Falta validar fluxo completo de convocatórias/resultados/pagamentos. |
| Financeiro geral | Faturas, mensalidades, movimentos, extratos, banco, conciliação, relatórios, pedidos fiscais | Membros, Banco, Loja, Logística, Fiscal, Centros de custo | 88% | F2.5 acrescentou reabertura controlada de movimentos `pago/parcial` pelo endpoint canónico, com reversão segura de `Payment`, `PaymentAllocation`, `MapaConciliacao`, pedidos fiscais pendentes e estado do extrato bancário, mantendo bloqueado o update direto fora do fluxo próprio. O utilizador confirmou manualmente no browser a sequência testada dessa sprint. Em F3.1 passou a existir uma leitura canónica de dívida/conta corrente em `CurrentAccountService`, já usada por Dashboard atleta, Portal Profile e `financeiro.invoices.open`, com exclusão de faturas ocultas/futuras, uso de `valor_em_aberto`, crédito disponível explícito e separação do saldo manual legado. Em F3.2.1, Ficha do membro, DashboardTab/FinancialTab do membro, Portal Pagamentos e Portal Família mantiveram esta leitura canónica mas deixaram de promover `conta_corrente_manual`/`manual_account_balance` como saldo operacional, remetendo ajustes para movimentos manuais auditáveis no Financeiro e restaurando linguagem funcional nas superfícies. Em F3.3, criação/edição de membro e importação passaram a bloquear/ignorar `conta_corrente_manual`, o Portal Profile deixou de o mostrar como `Conta corrente` e o Dashboard atleta deixou de o transportar no payload operacional. Em F3.4, a auditoria foi validada operacionalmente no servidor real com `0` membros afetados, `0.00` total positivo, `0.00` total negativo e estado semântico `no_legacy_manual_balance_found`, concluindo que não existe legado real para migrar. Ajustes de conta corrente devem ser feitos por Movimentos manuais e não é necessário avançar para F3.5. |
| Faturas / mensalidades | Geração de mensalidades, faturas abertas, estados, itens, tipos de fatura | Membros, Dados financeiros, centros de custo, pagamentos | 83% | Faturas manuais respeitam o tipo escolhido, entram no modal canónico de pagamento via `faturasState`, já podem ser reabertas pelo endpoint canónico antes de existir documento fiscal externo e o update direto de reabertura continua bloqueado. O utilizador validou manualmente a regressão crítica de Mensalidades: `Dinheiro` sem banco liquida sem nº de recibo, `Transferência` sem banco bloqueia, `Transferência` com banco liquida/concilia e a desconciliação na tab Banco reabre a mensalidade e remove o pedido fiscal pendente. Em F3.1, o endpoint de faturas abertas passou também a excluir faturas ocultas/futuras e a devolver `valor_em_aberto` canónico mesmo com snapshots antigos. |
| Pagamentos e alocação canónica | `PaymentAllocationService`, criação de pagamentos, alocação a faturas, recalculo de estados, créditos, pedido fiscal automático | Faturas, banco, mapa de conciliação, pedidos fiscais | 87% | Transferência sem linha bancária bloqueia; transferência com linha bancária liquida/concilia; dinheiro sem linha bancária liquida pelo fluxo canónico; o modal frontend de Faturas só expõe métodos ativos e o popup de Movimentos passou a respeitar a mesma lista ativa e a mesma regra bancária, sem reintroduzir nº de recibo. A regressão manual de Mensalidades foi confirmada no browser, incluindo reabertura por desconciliação bancária com remoção do pedido fiscal pendente. |
| Conciliação bancária assistida | Sugestões, confirmação, rejeição, alocação de extrato, aliases bancários | Banco, faturas, pagamentos, mapa de conciliação | 76% | A pesquisa de extratos e sugestões já não depende de PostgreSQL, o endpoint de sugestões deixou de sofrer shadowing e o carregamento de movimentos já não usa eager loading perigoso com `limit(1)`. Falta validação manual orientada e cenários F4. |
| Importação de recibos antigos | Batches, ZIP/diretoria pendente, matching, edição manual, commit, preview PDF | Faturas, banco, recibos PDF, utilizadores | 60% | A importação passou a validar corretamente ausência de ZIP quando a diretoria pendente não é usada. Continua a faltar validação com PDFs reais e afinação operacional do commit/matching. |
| Emissão fiscal / Wintouch | Pedidos fiscais, estados operacionais, emitido, cancelado, erro de dados | Financeiro, faturas, recibos fiscais | 46% | O pedido fiscal continua pendente até tratamento manual e, quando marcado como emitido, retroalimenta `invoice.numero_recibo`. Falta fechar o fluxo completo Wintouch/manual e anulação fiscal na F6. |
| Logística / inventário | Stock, produtos, fornecedores, requisições, empréstimos, compras, movimentos de stock, faturação | Financeiro, Loja, Membros, Fornecedores | 65% | Boa estrutura por actions; falta validação operacional e relatórios. |
| Loja | Loja pública, produto, carrinho, histórico, backoffice produtos/encomendas/hero | Membros, Financeiro, Logística, Configurações | 60% | Estrutura funcional; falta fechar ligação financeira e UX final. |
| Comunicação | Comunicados, campanhas, templates, segmentos, envios, alertas, lido/não lido | Membros, Eventos, Financeiro, Portal | 60% | Estrutura relevante; falta maturidade de entregas e automações. |
| Marketing | Campanhas de marketing | Comunicação, segmentos | 45% | Menos maduro e menos crítico para operação interna. |
| Configurações gerais | Clube, tipos, permissões, escalões, eventos, centros de custo, faturas, mensalidades, métodos de pagamento, artigos, categorias, patrocinadores, fornecedores, provas, notificações | Todos os módulos | 76% | A tab Financeiro gere métodos de pagamento ativos, ordem e exigência de linha bancária; validação manual OK. |
| Configurações desportivas | Estados de atleta, tipos de treino, zonas, motivos de ausência, lesão, tipos de piscina | Desportivo, Membros, Presenças | 70% | Boa base; confirmar consumo real em formulários. |
| Patrocínios | CRUD, integrações, retry, fechar, cancelar | Financeiro, entidades, comunicação | 45% | Estrutura existe, mas parece menos consolidada. |
| Relatórios / dashboards | Relatórios financeiros, estatísticas desportivas, dashboard global | Financeiro, Desportivo, Membros, Eventos | 40% | Ainda disperso. Falta reporting consolidado real. |
| PWA / mobile | Manifest, favicon, ícones, rotas específicas para assets | Frontend, mobile, identidade clube | 55% | Base criada; validar instalação real iOS/Android. |
| Testes e qualidade | Testes financeiros identificados, nomeadamente alocação de pagamentos | Financeiro, regressões | 40% | A cobertura Financeiro inclui regressões explícitas para pagamento canónico de faturas `material`, `inscricao` e `mensalidade` sem nº de recibo, garante que a Emissão Fiscal continua a exigir `external_document_number` no `mark-issued`, tem contract test da tab Faturas, cobre `liquidarMovimento` sem nº de recibo e passou a cobrir a reabertura controlada de movimentos em dinheiro e por transferência, o bloqueio quando já existe documento fiscal emitido e a proibição de reabrir por `update` direto. Continua insuficiente face ao tamanho da aplicação. |

---

## 5. Principais riscos técnicos vivos

### 5.1. Duplicação da verdade financeira

Há vários modelos e fluxos financeiros coexistentes: `Payment`, `PaymentAllocation`, `Invoice`, `FinancialEntry`, `Movement`, `BankStatement`, `MapaConciliacao` e outros.

A regra recomendada é:

> Qualquer liquidação, conciliação, pagamento manual, pagamento bancário, importação de recibo, loja ou logística deve passar por um único serviço canónico de pagamento.

Neste momento, o candidato natural a serviço canónico é `PaymentAllocationService`.

Na leitura de dívida/conta corrente, `CurrentAccountService` passou a ser a fonte canónica para Dashboard atleta, Portal Profile, Ficha do membro, DashboardTab/FinancialTab do membro, Portal Pagamentos, Portal Família e `financeiro.invoices.open`. Mantém-se como risco vivo qualquer superfície administrativa fora deste conjunto que ainda apresente dívida fora desta leitura partilhada.

Mantém-se como risco apenas residual a existência de leituras de compatibilidade para saldo manual legado em `dados_financeiros.conta_corrente_manual` e no fallback legado `users.conta_corrente`, apesar de a validação operacional da F3.4 no servidor real não ter encontrado qualquer valor para migrar. O comando de migração continua bloqueado para `--commit` por desenho conservador e a regra operacional mantém-se: saldo manual legado não altera a conta corrente atual e ajustes de conta corrente devem ser feitos por Movimentos manuais.

### 5.2. Rotas demasiado concentradas em `routes/web.php`

O ficheiro `routes/web.php` concentra portal, membros, eventos, desportivo, financeiro, loja, logística, patrocínios, comunicação e configurações.

Recomendação futura:

- `routes/modules/financeiro.php`
- `routes/modules/desportivo.php`
- `routes/modules/membros.php`
- `routes/modules/portal.php`
- `routes/modules/logistica.php`
- `routes/modules/loja.php`
- `routes/modules/comunicacao.php`
- `routes/modules/configuracoes.php`

### 5.3. Normalização de dados de utilizador

O modelo `users` continua a concentrar muitos campos. Algumas áreas já estão separadas em tabelas especializadas, como dados financeiros, documentos, tipos, relações e centros de custo.

É importante manter a regra:

- identidade e autenticação em `users`;
- dados financeiros em tabelas financeiras;
- dados desportivos em tabelas desportivas;
- permissões/tipos em tabelas de configuração;
- documentos em tabela própria.

### 5.4. Testes insuficientes

A aplicação já tem testes em áreas críticas, mas o nível de cobertura deve aumentar.

Prioridade de testes:

1. liquidação de faturas;
2. conciliação bancária parcial;
3. importação de recibos antigos;
4. anulação/cancelamento de documentos fiscais;
5. permissões por perfil;
6. relações familiares;
7. geração de mensalidades;
8. sincronização treino/evento/presença.

---

## 6. Prioridades recomendadas

### Prioridade 1 — Fechar o financeiro canónico

Objetivo: garantir que todos os fluxos de pagamento usam o mesmo motor.

Tarefas:

- declarar `PaymentAllocationService` como caminho oficial;
- rever `FinanceiroController` e fluxos antigos;
- impedir escrita direta concorrente em estados de fatura;
- garantir que reaberturas de faturas pagas/parciais passam por endpoint canónico e nunca por `update` direto;
- garantir que importação de recibos, banco, mensalidades, loja e logística usam o mesmo caminho.

### Prioridade 2 — Fechar importação de recibos antigos

Objetivo: permitir arranque real do sistema com histórico já emitido.

Tarefas:

- testar PDFs reais;
- validar matching por atleta, NIF, número de sócio, mês e valor;
- permitir correção manual;
- suportar conciliação parcial de movimentos bancários;
- guardar alias para sugestões futuras.

### Prioridade 3 — Matriz de permissões

Objetivo: impedir acessos indevidos e clarificar a experiência por perfil.

Perfis mínimos:

- Administrador;
- Direção;
- Tesouraria;
- Treinador;
- Logística;
- Atleta;
- Encarregado de Educação.

### Prioridade 4 — Portal mobile / atleta / família

Objetivo: garantir que utilizadores normais entram sempre numa visualização simples, mobile-first e sem ruído administrativo.

Tarefas:

- validar dashboard atleta;
- validar família/educandos;
- validar pagamentos;
- validar treinos/eventos;
- validar documentos;
- validar botão de entrada em administração apenas para perfis autorizados.

### Prioridade 5 — Relatórios consolidados

Objetivo: transformar dados operacionais em indicadores úteis para gestão.

Relatórios prioritários:

- dívida por atleta/família;
- receitas por escalão;
- despesas por centro de custo;
- assiduidade por atleta/escalão;
- evolução de resultados;
- peso financeiro vs peso desportivo.

---

## 7. Histórico vivo de atualizações

| Data | Módulo | Desenvolvimento / análise | Evidência | Percentagem antes | Percentagem depois | Pendências |
|---|---|---|---|---:|---:|---|
| 2026-05-19 | Global | Criação do ficheiro vivo de estado de desenvolvimento | Análise estática do repositório GitHub online | — | 60%–65% global | Executar validações runtime e atualizar por commit/PR. |
| 2026-05-20 | Financeiro | Sprint F1 — verdade financeira canónica tecnicamente concluída. Foram bloqueadas escritas diretas perigosas em faturas/mensalidades e movimentos; fluxos de liquidação/conciliação passam pelos serviços canónicos. | `FinanceiroController.php`, `ManualExpenseService.php`, `PaymentAllocationFlowTest.php` | 70% | 72% | Validação manual no browser ainda pendente. F2 deve corrigir bugs técnicos críticos e reforçar guardas fora da superfície HTTP principal. |
| 2026-05-20 | Financeiro | Sprint F1.1 — liquidação manual canónica com métodos de pagamento configuráveis. O backend passou a validar métodos ativos e a exigir linha bancária quando configurado; o frontend Financeiro e Configurações passaram a consumir a lista configurável. | `PaymentAllocationService.php`, `FinanceiroController.php`, `ConfiguracoesController.php`, `resources/js/Pages/Financeiro/FaturasTab.tsx`, `resources/js/Pages/Configuracoes/Index.tsx`, `tests/Feature/Financeiro/PaymentAllocationFlowTest.php`, `tests/Feature/Financeiro/FinanceDashboardFlowTest.php`, `tests/Feature/Configuracoes/PaymentMethodCrudTest.php` | 72% | 74% | Falta validação manual de UX no modal de pagamento e na gestão de métodos em Configurações. |
| 2026-05-20 | Financeiro | Sprint F1.2 — validação manual pós-correção do modal de pagamento. Confirmado no browser: transferência sem linha bancária bloqueia, transferência com linha bancária liquida/concilia, dinheiro sem linha bancária liquida, pedido fiscal pendente é criado e desconciliação reabre mensalidade/remover pedido pendente. | Feedback manual do utilizador em produção; modal Financeiro, tab Banco, tab Emissão Fiscal e Configurações > Financeiro | 74% | 76% | Avançar para F2: bugs técnicos críticos, incluindo delete seguro de faturas, `ilike`, validação de ZIP e limpeza de resquícios frontend. |
| 2026-05-20 | Financeiro | Sprint F2 — bugs técnicos críticos corrigidos no backend e frontend. Foram corrigidos os operadores de pesquisa dependentes do driver, a closure de sugestões bancárias com `$operator`, a validação condicional de ZIP, o delete inseguro de faturas, o tipo manual forçado a `mensalidade`, os lançamentos/stock otimistas falsos no frontend, o shadowing da rota de sugestões e o eager loading perigoso de movimentos. | `app/Http/Controllers/Controller.php`, `app/Http/Controllers/FinanceiroController.php`, `app/Http/Controllers/Financeiro/BankReconciliationSuggestionController.php`, `app/Http/Controllers/Financeiro/ReceiptImportController.php`, `app/Models/Movement.php`, `app/Services/Financeiro/BankReconciliationSuggestionService.php`, `resources/js/Pages/Financeiro/FaturasTab.tsx`, `routes/web.php`, `tests/Feature/Financeiro/FinanceiroCriticalBugFixesTest.php` | 76% | 79% | Tecnica e automaticamente concluida; falta validacao manual no browser para pesquisa bancária, delete bloqueado, faturas manuais por tipo e importacao sem ZIP. Nao avancar para F3 sem esse feedback. |
| 2026-05-21 | Financeiro | Sprint F2.1 — correção da validação manual da F2 no fluxo de faturas manuais. A liquidação deixou de depender de nº de recibo externo, o pagamento total cria pedido fiscal pendente, a emissão fiscal passa a retroalimentar `invoice.numero_recibo` e a reabertura de faturas pagas/parciais passou a usar endpoint canónico com reversão segura de pagamentos/alocações e do estado bancário antes de existir Wintouch. | `app/Http/Controllers/FinanceiroController.php`, `app/Services/Financeiro/PaymentAllocationService.php`, `app/Services/Financeiro/MonthlyInvoiceStatusService.php`, `app/Services/Financeiro/FiscalDocumentRequestService.php`, `resources/js/Pages/Financeiro/FaturasTab.tsx`, `routes/web.php`, `tests/Feature/Financeiro/PaymentAllocationFlowTest.php`, `tests/Feature/Financeiro/FiscalDocumentRequestFlowTest.php` | 79% | 80% | Validacao tecnica obrigatoria executada; F2 e F2.1 continuam pendentes de validacao manual orientada no browser. Nao avancar para F3 sem esse feedback. |
| 2026-05-21 | Financeiro | Sprint F2.2 — correção de UX/CSRF da tab Movimentos. Os requests de criação/edição/liquidação/apagar movimentos passaram a usar helper partilhado com `Accept: application/json`, `X-Requested-With`, `X-CSRF-TOKEN` e `credentials: same-origin`, com mensagem explícita para `419`; os dropdowns longos passaram a ter scroll e o CTA principal foi normalizado para `Novo Movimento`. Foram ainda adicionados testes HTTP para `POST /financeiro/movimentos` em JSON, multipart/FormData e validação obrigatória clara. | `resources/js/Pages/Financeiro/request.ts`, `resources/js/Pages/Financeiro/MovimentosTab.tsx`, `resources/js/Pages/Financeiro/FaturasTab.tsx`, `tests/Feature/Financeiro/ManualExpenseFlowsTest.php`, `tests/Feature/Financeiro/BankReconciliationSuggestionFlowTest.php` | 80% | 81% | `composer dump-autoload`, `php artisan migrate --pretend`, `php artisan test --filter=Financeiro` e `npm run build` executados. F2/F2.1/F2.2 continuam pendentes de validação manual orientada no browser; não avançar para F3 antes de repetir esses testes. |
| 2026-05-21 | Financeiro | Sprint F2.3 — remoção definitiva da dependência residual de nº de recibo no pagamento de faturas. A superfície administrativa de faturas deixou de transportar `numero_recibo`, o modal canónico foi renomeado para pagamento e os testes cobrem pagamento de faturas `material`, `inscricao` e `mensalidade` sem recibo, preservando `external_document_number` obrigatório apenas no `mark-issued` da Emissão Fiscal. | `resources/js/Pages/Financeiro/FaturasTab.tsx`, `app/Http/Requests/StoreInvoiceRequest.php`, `app/Http/Requests/UpdateInvoiceRequest.php`, `app/Http/Controllers/FinanceiroController.php`, `tests/Feature/Financeiro/PaymentAllocationFlowTest.php`, `tests/Feature/Financeiro/FiscalDocumentRequestFlowTest.php` | 81% | 82% | `composer dump-autoload`, `php artisan migrate --pretend`, `php artisan test --filter=PaymentAllocation`, `php artisan test --filter=FiscalDocumentRequestFlowTest`, `php artisan test --filter=Financeiro` e `npm run build` executados. Repetir validação manual orientada em browser para F2/F2.1/F2.2/F2.3; não avançar para F3 antes desse feedback. |
| 2026-05-22 | Financeiro | Sprint F2.3 — correção final do modal de pagamento em Faturas. O `Index` voltou a encaminhar o estado completo de faturas para a tab canónica, o modal passou a aceitar apenas métodos ativos, a mostrar seleção de linha bancária apenas quando o método o exige e a bloquear confirmação quando falta conciliação bancária obrigatória, sem regressão para o fluxo legado de movimentos nem reintrodução de nº de recibo. | `resources/js/Pages/Financeiro/Index.tsx`, `resources/js/Pages/Financeiro/FaturasTab.tsx`, `tests/Feature/Financeiro/FaturasTabFlowContractTest.php`, `tests/Feature/Financeiro/FinanceDashboardFlowTest.php`, `tests/Feature/Financeiro/PaymentAllocationFlowTest.php`, `tests/Feature/Financeiro/FiscalDocumentRequestFlowTest.php` | 82% | 83% | `composer dump-autoload`, `php artisan migrate --pretend`, `php artisan test --filter=FaturasTabFlowContractTest`, `php artisan test --filter=Financeiro`, `php artisan test --filter=PaymentAllocation`, `php artisan test --filter=FiscalDocument`, `php artisan test --filter=PaymentMethod` e `npm run build` executados. F2/F2.1/F2.2/F2.3 continuam pendentes de validação manual orientada no browser; não avançar para F3 antes desse feedback. |
| 2026-05-22 | Financeiro | Sprint F2.4 — correção limitada do popup `Liquidar Movimento` na tab Movimentos. O popup deixou de obrigar `numero_recibo`, passou a consumir apenas métodos ativos a partir dos `page props`, mostra seleção de linha bancária apenas para métodos com `requer_linha_bancaria`, bloqueia confirmação quando falta conciliação bancária obrigatória e envia `bank_statement_id` apenas quando aplicável. O endpoint `liquidarMovimento` passou a aceitar `numero_recibo` nulo e `bank_statement_id`, mantendo o motor financeiro canónico inalterado. | `resources/js/Pages/Financeiro/MovimentosTab.tsx`, `app/Http/Controllers/FinanceiroController.php`, `tests/Feature/Financeiro/ManualExpenseFlowsTest.php` | 83% | 84% | `php artisan test --filter=ManualExpenseFlowsTest`, `php artisan test --filter=PaymentAllocation`, `php artisan test --filter=Financeiro` e `npm run build` executados. F2/F2.1/F2.2/F2.3/F2.4 continuam pendentes de validação manual orientada no browser; não avançar para F3 antes desse feedback. |
| 2026-05-22 | Financeiro | Sprint F2.5 — reabertura controlada de movimentos liquidados. Movimentos `pago/parcial/pago_parcial` passaram a poder ser reabertos apenas pelo endpoint canónico, com reversão segura de alocações, pagamentos órfãos, reconciliação bancária e pedidos fiscais pendentes, bloqueando a operação quando já existe documento fiscal emitido ou nº externo associado. A tab Movimentos passou a expor ações explícitas de reabertura para `pendente` e `vencido`, sem reabrir o update direto. | `app/Services/Financeiro/FinancialSettlementService.php`, `app/Http/Controllers/FinanceiroController.php`, `routes/web.php`, `resources/js/Pages/Financeiro/MovimentosTab.tsx`, `tests/Feature/Financeiro/ManualExpenseFlowsTest.php` | 84% | 85% | `composer dump-autoload`, `php artisan migrate --pretend`, `php artisan test --filter=ManualExpenseFlowsTest`, `php artisan test --filter=PaymentAllocation`, `php artisan test --filter=Financeiro` e `npm run build` executados. F2/F2.1/F2.2/F2.3/F2.4/F2.5 continuam pendentes de validação manual orientada no browser; não avançar para F3 antes desse feedback. |
| 2026-05-22 | Financeiro | Validação manual em browser da sequência F2.4/F2.5 em Movimentos e da regressão crítica de Mensalidades. O utilizador confirmou que `Liquidar Movimento` já não obriga nº de recibo, filtra métodos inativos, permite `Dinheiro` sem linha bancária, mostra/bloqueia/permite `Transferência` conforme a linha bancária, que o movimento liquidado entra em Emissão Fiscal, que a reabertura para `pendente` e `vencido` funciona e que o pedido fiscal pendente é removido ao reabrir enquanto ainda não existe nº Wintouch. Confirmou também que, em Mensalidades, `Dinheiro` sem banco liquida sem nº de recibo, `Transferência` sem banco bloqueia, `Transferência` com banco liquida/concilia e a desconciliação no Banco reabre a mensalidade e remove o pedido fiscal pendente. | Feedback manual do utilizador no browser; Financeiro > Movimentos; Financeiro > Mensalidades; tab Banco; tab Emissão Fiscal | 85% | 85% | F2/F2.1/F2.2/F2.3/F2.4/F2.5 ficam fechadas operacionalmente na parte validada desta sequência; mantêm-se apenas pendências fora destas sprints. Não avançar para F3. |
| 2026-05-22 | Financeiro | Sprint F3.1 — leitura canónica de dívida e conta corrente. Foi criado `CurrentAccountService` para centralizar a leitura de faturas abertas, movimentos a receber, crédito disponível e saldo manual legado; o Dashboard atleta e o Portal Profile passaram a consumir `net_debt`/`manual_account_balance`, e `financeiro.invoices.open` passou a excluir faturas ocultas/futuras mantendo `valor_em_aberto` canónico em cenários com snapshots antigos. | `app/Services/Financeiro/CurrentAccountService.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/PortalProfileController.php`, `app/Http/Controllers/FinanceiroController.php`, `tests/Feature/Dashboard/DashboardEntryRoutingTest.php`, `tests/Feature/Portal/PortalProfileFamilyAccessTest.php`, `tests/Feature/Financeiro/PaymentAllocationFlowTest.php` | 85% | 86% | Validação técnica focada executada. Falta validação manual orientada em Dashboard atleta, Portal > Perfil e Financeiro > Faturas abertas para confirmar os novos valores no browser. |
| 2026-05-25 | Financeiro | Sprint F3.2 — alinhamento das superfícies de utilizador/família com `CurrentAccountService`. Ficha do membro e tabs locais deixaram de duplicar `conta_corrente_manual`, Portal Pagamentos passou a usar dívida líquida/valor em aberto real e Portal Família passou a somar apenas dívida aberta efetiva dos membros visíveis, separando crédito disponível e saldo manual legado. | `app/Http/Controllers/MembrosController.php`, `app/Http/Controllers/PortalPageController.php`, `app/Http/Controllers/FamilyPortalController.php`, `resources/js/Pages/Portal/Payments.tsx`, `resources/js/Pages/Portal/Family.tsx`, `resources/js/Components/Members/Tabs/FinancialTab.tsx`, `resources/js/Components/Members/Tabs/DashboardTab.tsx`, `tests/Feature/Membros/MembrosCurrentAccountSurfaceTest.php`, `tests/Feature/Portal/PortalPaymentsTest.php`, `tests/Feature/Portal/PortalFamilyCurrentAccountTest.php`, `tests/Feature/Dashboard/MemberDashboardSurfaceTest.php` | 86% | 87% | Tecnica e automaticamente concluida. Validacao manual orientada ainda pendente em Ficha do membro, Portal > Pagamentos e Portal > Familia. Nao avancar para F3.3 antes desse feedback. |
| 2026-05-25 | Financeiro | Sprint F3.2.1 — correção limitada das superfícies operacionais de conta corrente. Mantido `CurrentAccountService` como leitura canónica, mas removida a promoção operacional de `conta_corrente_manual`/`manual_account_balance`; tabs de membro e Portais Pagamentos/Família voltaram a usar linguagem funcional (`Conta Corrente`, `Mensalidades`, `Movimentos`, `Valor Pago`, `Em aberto`) e a indicar que novos ajustes devem ser feitos por movimento manual auditável no Financeiro. | `app/Http/Controllers/MembrosController.php`, `app/Http/Controllers/PortalPageController.php`, `app/Http/Controllers/FamilyPortalController.php`, `resources/js/Pages/Membros/Show.tsx`, `resources/js/Components/Members/Tabs/DashboardTab.tsx`, `resources/js/Components/Members/Tabs/FinancialTab.tsx`, `resources/js/Pages/Portal/Payments.tsx`, `resources/js/Pages/Portal/Family.tsx`, `tests/Feature/Membros/MembrosCurrentAccountSurfaceTest.php`, `tests/Feature/Financeiro/CurrentAccountServiceOperationalBalanceTest.php`, `tests/Feature/Dashboard/MemberCurrentAccountLanguageContractTest.php`, `tests/Feature/Portal/PortalCurrentAccountLanguageContractTest.php`, `tests/Feature/Portal/PortalPaymentsTest.php`, `tests/Feature/Portal/PortalFamilyCurrentAccountTest.php` | 87% | 87% | Validação técnica automática em curso; continua pendente validação manual orientada em Ficha do membro, Portal > Pagamentos e Portal > Família. Futura sprint pode migrar saldo manual legado para movimentos auditáveis. |
| 2026-05-26 | Financeiro | Sprint F3.3 — encerramento operacional de `conta_corrente_manual`. O campo deixou de ser editável em criação/edição de membro, passou a ser bloqueado por request com mensagem explícita, a importação de membros passou a ignorá-lo com aviso de compatibilidade, o Portal Profile deixou de o promover como `Conta corrente` e o Dashboard atleta deixou de o transportar no payload operacional. Ajustes de conta corrente passam exclusivamente por Movimentos manuais. | `app/Http/Requests/StoreMembroRequest.php`, `app/Http/Requests/UpdateMemberRequest.php`, `app/Http/Controllers/MembrosController.php`, `app/Http/Controllers/PortalProfileController.php`, `app/Http/Controllers/DashboardController.php`, `app/Services/Members/MemberImportService.php`, `resources/js/lib/member-import.ts`, `tests/Feature/Membros/MembrosCurrentAccountSurfaceTest.php`, `tests/Feature/Portal/PortalProfileFamilyAccessTest.php`, `tests/Feature/Financeiro/CurrentAccountServiceOperationalBalanceTest.php`, `tests/Feature/Dashboard/DashboardEntryRoutingTest.php` | 87% | 88% | Tecnica concluida; validacao manual orientada ainda pendente em Dashboard atleta, Portal > Perfil, Membros > Ficha e importacao real de membros. F3.4 fica apenas proposta para migracao controlada do legado para movimentos manuais. |
| 2026-05-26 | Financeiro | Sprint F3.4 — auditoria e preparação de migração controlada de `conta_corrente_manual` para Movimentos manuais. Foram criados um comando de auditoria e um comando de plano de migração em dry-run para listar membros afetados, totais positivos/negativos, movimentos manuais já existentes, pendências abertas e preview do movimento auditável futuro com origem `legacy_manual_current_account`. A validação operacional executada no servidor real com `php artisan finance:audit-manual-current-account` devolveu `0` membros afetados, `0.00` total positivo, `0.00` total negativo, `0.00` total líquido legado e estado semântico `no_legacy_manual_balance_found`, fechando a sprint sem necessidade de F3.5. | `app/Console/Commands/AuditManualCurrentAccount.php`, `app/Console/Commands/MigrateManualCurrentAccount.php`, `app/Console/Commands/Support/ManualCurrentAccountAuditReportBuilder.php`, `bootstrap/app.php`, `tests/Feature/Financeiro/ManualCurrentAccountCommandsTest.php`, `tests/Feature/Financeiro/CurrentAccountServiceOperationalBalanceTest.php`, `docs/PLANO_FECHO_MODULO_FINANCEIRO.md` | 88% | 88% | Validada operacionalmente em servidor real; nenhuma migração real executada; não avançar para F3.5. |
| 2026-05-26 | Financeiro | Sprint F4.1 — implementação canónica de reconciliação bancária com guarda de duplicados. Novo modelo `BankStatement::duplicateSignatureFrom()` com normalização de dados (trim, round) para detecção robusta; `storeExtrato()` bloqueia criação individual duplicada; `storeExtratosBulk()` deteta duplicados intra-ficheiro e em BD, rejeitando com mensagens claras e retornando resumo (recebidas/criadas/rejeitadas); `conciliarExtrato()` bloqueado permanentemente com `abort(410)` direcionando para fluxo canónico; `unreconcile()` agora limpa `BankTransactionAllocation` confirmadas e restaura recibos ao estado `PENDING`; frontend `BancoTab.tsx` desabilitou `handleCatalogar` com aviso de descontinuação. | `app/Models/BankStatement.php` (assinatura/duplicados), `app/Models/BankTransactionAllocation.php` (status constants), `app/Http/Controllers/FinanceiroController.php` (guardas bulk), `app/Services/Financeiro/BankReconciliationService.php` (limpeza alocações), `resources/js/Pages/Financeiro/BancoTab.tsx` (desabilitação fluxo legado) | 88% | 89% | Validações técnicas executadas: `composer dump-autoload`, `php artisan test --filter=BankReconciliationSuggestionFlowTest` (33 passed), `npm run build` OK. Validação manual orientada ainda pendente: criar extrato duplicado (deve bloquear), bulk import com duplicados (deve rejeitar com resumo), desconciliação (deve limpar alocações). |

---

## 8. Como atualizar este documento no futuro

Sempre que for feita uma nova sprint, commit ou alteração relevante, atualizar:

1. a grelha da secção 4;
2. os riscos da secção 5;
3. as prioridades da secção 6;
4. o histórico da secção 7.

Formato recomendado para cada atualização:

```md
| AAAA-MM-DD | Módulo | O que mudou | Ficheiros/PR/commit | % antes | % depois | Pendências |
```

---

## 9. Nota metodológica

As percentagens deste documento são estimativas técnicas baseadas em leitura estática do código.

Para transformar estas percentagens em estado validado, é necessário executar:

```bash
composer dump-autoload
php artisan migrate --pretend
php artisan test
npm run build
```

Sempre que estes comandos forem executados com sucesso, deve ser acrescentada uma nota no histórico vivo.
