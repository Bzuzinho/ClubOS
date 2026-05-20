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
| Dashboard / entrada por perfil | Dashboard admin e vista atleta/portal; estatísticas, eventos recentes, receitas, atividade | Membros, Eventos, Financeiro, Desportivo, Família | 65% | Bem encaminhado; falta fechar UX e indicadores finais. |
| Portal do atleta / utilizador | Perfil, treinos, eventos, pagamentos, resultados, documentos, comunicados, família, loja | Desportivo, Eventos, Financeiro, Documentos, Comunicação, Loja | 60% | Estrutura existe; precisa de validação de experiência real em mobile. |
| Gestão de Membros / Pessoas | Lista, criação, edição, número de sócio, tipos, escalões, uploads, menor, EE/educando, dados financeiros, centros de custo | Financeiro, Desportivo, Configurações, Comunicação, Família | 75% | Forte, mas exige normalização clara entre `users` e tabelas especializadas. |
| Importação de membros | Template, preview e importação | Membros, user types, escalões | 55% | Confirmar comportamento com ficheiros reais. |
| Documentos de membro | Gestão de documentos no admin e portal | Membros, Portal, RGPD, Configuração | 65% | Precisa de política clara de tipos obrigatórios e permissões. |
| Família / EE / educandos | Portal família, pesquisa e associação de membros familiares | Membros, Portal, Dashboard, Financeiro familiar | 55% | Estrutura existe; falta consolidar permissões e visão financeira/desportiva agregada. |
| Desportivo | Dashboard, planeamento, treinos, presenças, cais, competições, relatórios | Eventos, Membros, Resultados, Financeiro, Configurações | 70% | Módulo bastante avançado, mas ainda precisa de validação funcional completa. |
| Planeamento desportivo | Épocas, macrociclos, mesociclos, microciclos, objetivos | Escalões, treinos, competições | 65% | Boa base; falta fechar UX e relatórios associados. |
| Treinos e presenças | Criar, agendar, editar, duplicar, apagar treino; atletas; presenças; cais; métricas | Eventos, atletas, portal | 70% | Núcleo forte; confirmar sincronização com eventos e ficha de atleta. |
| Competições / resultados | Competições, inscrições, resultados, splits, provas, resultados por equipa | Eventos, Desportivo, Relatórios | 60% | Estrutura existe; maturidade funcional a confirmar. |
| Eventos | CRUD, participantes, estados, estatísticas, portal de eventos | Membros, Comunicação, Desportivo, Financeiro | 65% | Falta validar fluxo completo de convocatórias/resultados/pagamentos. |
| Financeiro geral | Faturas, mensalidades, movimentos, extratos, banco, conciliação, relatórios, pedidos fiscais | Membros, Banco, Loja, Logística, Fiscal, Centros de custo | 79% | Sprint F2 corrigiu bugs técnicos críticos: pesquisa cross-driver, delete seguro de faturas, validação de ZIP, tipo manual não forçado, frontend sem lançamentos/stock falsos e carregamento seguro de movimentos. Falta validação manual dirigida no browser. |
| Faturas / mensalidades | Geração de mensalidades, faturas abertas, estados, itens, tipos de fatura | Membros, Dados financeiros, centros de custo, pagamentos | 78% | Faturas manuais passam a respeitar o tipo escolhido e o delete só é permitido em faturas realmente limpas, sem rasto financeiro/fiscal. Fluxos F1/F1.1/F1.2 mantidos. |
| Pagamentos e alocação canónica | `PaymentAllocationService`, criação de pagamentos, alocação a faturas, recalculo de estados, créditos, pedido fiscal automático | Faturas, banco, mapa de conciliação, pedidos fiscais | 84% | Transferência sem linha bancária bloqueia; transferência com linha bancária liquida/concilia; dinheiro sem linha bancária liquida pelo fluxo canónico. |
| Conciliação bancária assistida | Sugestões, confirmação, rejeição, alocação de extrato, aliases bancários | Banco, faturas, pagamentos, mapa de conciliação | 76% | A pesquisa de extratos e sugestões já não depende de PostgreSQL, o endpoint de sugestões deixou de sofrer shadowing e o carregamento de movimentos já não usa eager loading perigoso com `limit(1)`. Falta validação manual orientada e cenários F4. |
| Importação de recibos antigos | Batches, ZIP/diretoria pendente, matching, edição manual, commit, preview PDF | Faturas, banco, recibos PDF, utilizadores | 60% | A importação passou a validar corretamente ausência de ZIP quando a diretoria pendente não é usada. Continua a faltar validação com PDFs reais e afinação operacional do commit/matching. |
| Emissão fiscal / Wintouch | Pedidos fiscais, estados operacionais, emitido, cancelado, erro de dados | Financeiro, faturas, recibos fiscais | 42% | Validado que pagamento total cria movimento/pedido de emissão fiscal e que desconciliação remove pedido pendente. Falta fechar fluxo Wintouch/manual na F6. |
| Logística / inventário | Stock, produtos, fornecedores, requisições, empréstimos, compras, movimentos de stock, faturação | Financeiro, Loja, Membros, Fornecedores | 65% | Boa estrutura por actions; falta validação operacional e relatórios. |
| Loja | Loja pública, produto, carrinho, histórico, backoffice produtos/encomendas/hero | Membros, Financeiro, Logística, Configurações | 60% | Estrutura funcional; falta fechar ligação financeira e UX final. |
| Comunicação | Comunicados, campanhas, templates, segmentos, envios, alertas, lido/não lido | Membros, Eventos, Financeiro, Portal | 60% | Estrutura relevante; falta maturidade de entregas e automações. |
| Marketing | Campanhas de marketing | Comunicação, segmentos | 45% | Menos maduro e menos crítico para operação interna. |
| Configurações gerais | Clube, tipos, permissões, escalões, eventos, centros de custo, faturas, mensalidades, métodos de pagamento, artigos, categorias, patrocinadores, fornecedores, provas, notificações | Todos os módulos | 76% | A tab Financeiro gere métodos de pagamento ativos, ordem e exigência de linha bancária; validação manual OK. |
| Configurações desportivas | Estados de atleta, tipos de treino, zonas, motivos de ausência, lesão, tipos de piscina | Desportivo, Membros, Presenças | 70% | Boa base; confirmar consumo real em formulários. |
| Patrocínios | CRUD, integrações, retry, fechar, cancelar | Financeiro, entidades, comunicação | 45% | Estrutura existe, mas parece menos consolidada. |
| Relatórios / dashboards | Relatórios financeiros, estatísticas desportivas, dashboard global | Financeiro, Desportivo, Membros, Eventos | 40% | Ainda disperso. Falta reporting consolidado real. |
| PWA / mobile | Manifest, favicon, ícones, rotas específicas para assets | Frontend, mobile, identidade clube | 55% | Base criada; validar instalação real iOS/Android. |
| Testes e qualidade | Testes financeiros identificados, nomeadamente alocação de pagamentos | Financeiro, regressões | 35% | Cobertura ainda insuficiente face ao tamanho da aplicação. |

---

## 5. Principais riscos técnicos vivos

### 5.1. Duplicação da verdade financeira

Há vários modelos e fluxos financeiros coexistentes: `Payment`, `PaymentAllocation`, `Invoice`, `FinancialEntry`, `Movement`, `BankStatement`, `MapaConciliacao` e outros.

A regra recomendada é:

> Qualquer liquidação, conciliação, pagamento manual, pagamento bancário, importação de recibo, loja ou logística deve passar por um único serviço canónico de pagamento.

Neste momento, o candidato natural a serviço canónico é `PaymentAllocationService`.

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
