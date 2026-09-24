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


## P2.5 — Catálogo de provas: ProvaTipo vs. shadow KV

### Owner operacional

- **Configuração Desportiva / `ProvaTipo`** é a única fonte canónica do catálogo de tipos de prova.
- Superfícies de Eventos, Desportivo e ficha do membro podem consultar esse catálogo, mas não mantêm cópias persistentes próprias.

### Problema encontrado

`Membros > Desportivo > Convocatórias` consultava corretamente `/api/prova-tipos`, mas gravava depois a resposta em `KeyValueStore` através da chave `club-prova-tipos`. Isso criava uma segunda fonte persistente, potencialmente desatualizada e sem scope de clube.

O próprio endpoint `/api/prova-tipos` também não aplicava `SportsClubContext`, apesar de `ProvaTipo` possuir `forClub()`, `ativo()` e `ordenado()`.

### Decisão deste lote

- a ficha passa a manter apenas estado local da resposta da API canónica;
- `/api/prova-tipos` fica limitado ao clube atual e apenas a registos ativos/não arquivados, com ordenação canónica;
- `club-prova-tipos` é marcado como shadow catalog descontinuado e passa a responder `410 Gone` em GET/PUT/DELETE;
- dados históricos eventualmente existentes no `KeyValueStore` não são apagados automaticamente.

Sem migration, backfill ou eliminação de dados neste lote.


## P2.6 — Importação de recibos: operação financeira fora de Configurações

### Owner operacional

- **Financeiro > Importar recibos** é o owner da importação, matching, revisão e commit de recibos antigos.
- **Configurações > Financeiro** mantém apenas configuração, aprendizagem/auditoria e parâmetros do ciclo financeiro; não executa imports que liquidam obrigações.

### Problema encontrado

A funcionalidade usava exclusivamente rotas, policies e serviços do domínio Financeiro (`financeiro.receipt-imports.*`, `financeiro.importacao_recibos`, `ReceiptCommitService`, `FinancialSettlementService`), mas a UI tinha sido relocalizada em 2026-06-02 para `Configurações > Financeiro > Importar Recibos`.

Essa localização fazia uma operação financeira transacional parecer configuração e obrigava o `ConfiguracoesController` a carregar opções de membros e faturas abertas.

### Decisão deste lote

- `ReceiptImportsTab` passa para a navegação principal de `Financeiro`;
- a permissão existente `financeiro.importacao_recibos` continua a controlar leitura/edição;
- o próprio endpoint `financeiro.receipt-imports.index` fornece, quando `include_options=1`, as opções de membros e faturas abertas necessárias à revisão;
- `ReceiptImportsTab` torna-se autocontida e deixa de receber esses lookups por props;
- Configurações deixa de expor a tab, o componente e os payloads `receiptImportUsers` / `receiptImportInvoices`;
- endpoints, matching, commit, pagamentos, conciliação e regras fiscais não são alterados.

Sem migrations, backfill ou alteração automática de dados.

## P2.7 — Patrocinadores: entidade canónica no módulo de Patrocínios

### Owner operacional

- **Patrocínios > Patrocinadores** é o owner da entidade `Sponsor` e da respetiva identificação, contactos, período, tipologia, estado e logótipo.
- **Patrocínios > Patrocínios** continua a gerir os contratos/apoios `Sponsorship`, que referenciam uma entidade patrocinadora por `sponsor_id`.
- **Configurações > Logística** mantém apenas catálogo de artigos, categorias e fornecedores; não gere entidades patrocinadoras.

### Problema encontrado

A base central de patrocinadores era carregada por `ConfiguracoesController`, apresentada dentro de `Configurações > Logística > Patrocinadores` e gravada por rotas `configuracoes.patrocinadores.*`. No entanto, o único domínio que consome `Sponsor` como entidade funcional é Patrocínios, onde `Sponsorship` já referencia essa tabela diretamente.

A localização anterior associava indevidamente o CRUD à permissão/middleware de Configurações e ao cache de Logística, apesar de não existir responsabilidade logística sobre a identidade do patrocinador.

### Decisão deste lote

- a gestão da entidade `Sponsor` passa para uma área própria `Patrocínios > Patrocinadores`;
- as rotas CRUD passam a `patrocinios.patrocinadores.*` sob `module.access:patrocinios`;
- `PatrocinosController` passa a ser o boundary HTTP do diretório de patrocinadores;
- `ConfiguracoesController` deixa de importar, carregar, cachear ou mutar `Sponsor`;
- a tab `Configurações > Logística > Patrocinadores` e as rotas `configuracoes.patrocinadores.*` são retiradas;
- a eliminação de uma entidade com patrocínios associados falha fechada, preservando a integridade histórica;
- `Sponsorship`, integrações financeiras/logísticas e dados existentes não são migrados nem reescritos.

Sem migrations, backfill ou alteração automática de dados neste lote.

## P2.8 — Projeções KV de Eventos: leitura transversal vs. mutação

### Owners operacionais

- **Eventos > Calendário** é o owner do lifecycle de eventos.
- **Eventos > Convocatórias** é o owner das projeções de grupos/atletas de convocatória.
- **Eventos > Resultados** é o owner das presenças e resultados associados a Eventos.
- Desportivo e a ficha do membro podem consultar estas projeções quando necessário, mas não são portas alternativas de escrita.

### Problema encontrado

O `KeyValueController` autorizava `edit/delete` de `club-events`, `club-convocatorias*`, `movimentos-convocatoria` e `club-presencas` com as mesmas permissões usadas para leitura. Isso permitia que permissões de Desportivo ou da ficha do membro chegassem aos writers canónicos de Eventos, apesar de as superfícies runtime correspondentes serem apenas leitoras.

O risco era especialmente elevado porque o endpoint KV genérico suporta sincronização e eliminação sobre tabelas canónicas de Eventos.

### Decisão deste lote

- leitura mantém compatibilidade transversal com Eventos, Desportivo e ficha do membro;
- mutação de `club-events` fica reservada a `eventos.calendario`;
- mutação de `club-convocatorias`, `club-convocatorias-grupo`, `club-convocatorias-atleta` e `movimentos-convocatoria` fica reservada a `eventos.convocatorias`;
- mutação de `club-presencas` fica reservada a `eventos.resultados`;
- o boundary já aplicado em P2.2 a `club-resultados*` mantém-se inalterado;
- contract tests provam que permissões de Desportivo/ficha continuam a ler, mas recebem `403` em `PUT/DELETE`.

Sem migrations, backfill, alteração de dados ou mudança dos formatos das projeções.

## P2.9 — Tipos de evento: retirar adapter KV órfão

### Owner operacional

- **Configurações > Tipos de Evento** é o owner do catálogo `EventType`.
- Eventos, Desportivo e restantes superfícies consomem diretamente a tabela/modelo canónico ou payloads construídos a partir dela.
- O endpoint KV genérico não é uma superfície de configuração deste catálogo.

### Problema encontrado

`club-eventos-tipos` permanecia suportado por `EventosKeyValueService`, apesar de não existir qualquer consumidor runtime no frontend. O adapter permitia sincronizar toda a tabela `event_types` e, em `DELETE`, executar `EventType::query()->delete()`.

Isto constituía uma segunda porta de escrita e uma operação destrutiva global fora do CRUD canónico de Configurações.

### Decisão deste lote

- `club-eventos-tipos` passa a catálogo KV descontinuado e responde `410 Gone` em GET/PUT/DELETE;
- `EventosKeyValueService` deixa de suportar a chave e remove os métodos de leitura/sincronização associados;
- o CRUD `configuracoes.tipos-evento.*` permanece inalterado como única porta de gestão;
- o runtime frontend mantém zero consumidores desta chave;
- dados `event_types` existentes não são migrados, apagados ou reescritos.

Sem migrations, backfill ou alteração automática de dados neste lote.
