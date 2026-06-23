# M3.3 — Mapa de Dependências Legacy Cross-Module

## Objetivo

A M3.3 identifica dependências legacy fora dos fluxos principais já tratados em Membros/Portal, com foco em leituras e escritas diretas na tabela `users` de campos funcionais que já devem convergir para `dados_pessoais` e `dados_configuracao`.

Esta fase não remove colunas, não altera fallback e não muda comportamento funcional em produção. O objetivo é mapear, classificar por risco e preparar correções seguras e incrementais.

## Estado herdado da M3.2

Com a M3.2:

- `users` deixou de receber payload funcional completo nos fluxos principais de Membros/Portal;
- `dados_pessoais` e `dados_configuracao` passaram a ser as fontes canónicas previstas para dados pessoais/configuração;
- `users` mantém função de auth, permissões, estado operacional, compatibilidade e fallback temporário.

Fluxos já tratados/excluídos desta análise:

- `MembrosController@store`;
- `MembrosController@update`;
- `PortalProfileController@update`;
- `MemberDataReadService`;
- `MemberDataWriteService`.

## Metodologia

Pesquisa técnica executada com varredura textual no backend/frontend, com foco em:

- referências a `User::`, `users.`, `->user`, `with('user')`, `belongsTo(User::class)`;
- referências aos campos legacy-alvo:
  - `nome_completo`, `data_nascimento`, `sexo`, `nif`, `cc`, `documento_identificacao`, `morada`, `codigo_postal`, `localidade`, `nacionalidade`, `contacto`, `contacto_alternativo`, `email_secundario`, `rgpd`, `consentimento`, `declaracao_de_transporte`, `afiliacao`, `num_federacao`;
- revisão manual das ocorrências para separar:
  - uso auth/operacional seguro;
  - dependência legacy tolerada;
  - dependência legacy a corrigir;
  - escrita legacy a corrigir;
  - falso positivo (campos iguais noutros modelos/contextos).

Critério aplicado: apenas ocorrências fora dos fluxos já fechados em Membros/Portal entram no backlog de M3.3.

## Resumo executivo

| Módulo | Tipo de dependência | Risco | Ação recomendada |
|---|---|---|---|
| Financeiro (fiscal) | Leitura direta de `users.nif`, `users.morada`, `users.nome_completo` para emissão/pedidos fiscais | Alto | Introduzir leitura canónica com fallback explícito para fiscal payload |
| Financeiro (conciliação/importação recibos) | Leitura direta de `users.nif` e `users.nome_completo` para matching/display | Médio | Corrigir em fases; começar por leitura de display e manter fallback |
| Desportivo | Leitura direta de `users.num_federacao` e `users.data_nascimento` em payloads/API | Médio | Redirecionar para `dados_configuracao`/`dados_pessoais` com fallback |
| Dashboard/Relatórios | Uso recorrente de `users.nome_completo` para display/ordenação | Baixo | Tolerar nesta sprint; padronizar helper de display no futuro |
| Eventos | Uso de `users.nome_completo` para listas/resultados/convocatórias | Baixo | Tolerar por agora (display), sem mudança estrutural nesta fase |
| Imports (API Users) | Escrita direta de campos legacy em `users` | Alto | Prioridade 1 de correção segura em M3.3 |
| Imports (MemberImportService) | `User::create` com payload legado amplo (fora do fecho M3.2) | Alto | Prioridade 2; alinhar import com contrato reduzido de escrita |
| Comunicação | Leitura de `users.contacto`/`nome_completo` para destinatários e templates | Médio | Manter operacional com fallback; preparar refactor para fonte canónica |

## Dependências por módulo

### Financeiro

1. `app/Services/Financeiro/FiscalEmissionQueueService.php` (métodos `enqueueFromFinancialEntry`, `resolveAddress`, `resolveInitialStatus`, `resolveLastError`)
- Campos: `nome_completo`, `nif`, `morada`, `codigo_postal`, `localidade`.
- Classificação: 3. Dependência legacy a corrigir.
- Motivo: payload fiscal depende diretamente de `users` para identificação fiscal/endereço.
- Recomendação: ler primeiro de estrutura canónica e manter fallback para `users` enquanto houver legado.

2. `app/Services/Financeiro/FiscalDocumentRequestService.php` (métodos `buildInvoicePayload`, `resolveInitialStatus`)
- Campos: `nome_completo`, `nif`, `morada`, `codigo_postal`, `localidade`.
- Classificação: 3. Dependência legacy a corrigir.
- Motivo: dados fiscais do cliente são montados diretamente a partir de `users`.
- Recomendação: abstrair leitura de dados do cliente (fonte canónica + fallback).

3. `app/Services/Financeiro/ReceiptMatchingService.php` (método `findUserCandidates`)
- Campos: `nif`, `nome_completo`.
- Classificação: 2. Dependência legacy tolerada.
- Motivo: matching de recibos antigos exige heurística com dados legacy; impacto operacional elevado se alterado sem testes de dados reais.
- Recomendação: manter nesta sprint; preparar camada de leitura canónica para matching.

4. `app/Http/Controllers/FinanceiroController.php` (múltiplos payloads de listagem/pesquisa)
- Campos: `nome_completo`, `nif`, `morada`.
- Classificação: 2. Dependência legacy tolerada (maioritariamente display/search).
- Motivo: uso dominante de leitura para UX e filtros, sem evidência de escrita direta dos campos legacy-alvo em `users`.
- Recomendação: migrar gradualmente pesquisas/payloads para fonte canónica sem romper filtros atuais.

### Eventos

1. `app/Http/Controllers/EventosController.php` (payloads de resultados, convocações, utilizadores)
- Campo principal: `nome_completo`.
- Classificação: 2. Dependência legacy tolerada.
- Motivo: uso para display/listagem, não para escrita de dados pessoais legacy.
- Recomendação: manter nesta sprint; padronizar fallback (`nome_completo ?? name`) em helper comum.

### Desportivo

1. `app/Services/Desportivo/DesportivoPagePayloadBuilder.php` (método `athleteUsers`)
- Campos: `num_federacao`, `nome_completo`.
- Classificação:
  - `num_federacao`: 3. Dependência legacy a corrigir.
  - `nome_completo`: 2. Dependência legacy tolerada (display).
- Recomendação: migrar `num_federacao` para leitura canónica com fallback.

2. `app/Http/Controllers/Api/AthleteController.php` (método `show`)
- Campo: `data_nascimento`.
- Classificação: 3. Dependência legacy a corrigir.
- Motivo: API desportiva expõe dado pessoal diretamente de `users`.
- Recomendação: ler de `dados_pessoais` com fallback controlado.

### Dashboard / Relatórios

1. `app/Http/Controllers/DashboardController.php`
- Campo principal: `nome_completo` (ordenação e display em listas/resumos).
- Classificação: 2. Dependência legacy tolerada.
- Motivo: uso de identidade visual e fallback com `name`.
- Recomendação: sem urgência nesta sprint; consolidar helper de nome exibido.

2. `app/Services/Financeiro/BankReconciliationAuditService.php` e `app/Http/Controllers/Financeiro/BankReconciliationAuditController.php`
- Campo principal: `nome_completo` (pesquisa/export).
- Classificação: 2. Dependência legacy tolerada.
- Motivo: contexto de auditoria/reporting, sem escrita em `users`.
- Recomendação: evoluir quando houver camada de leitura canónica transversal.

### Imports / Exports

1. `app/Http/Controllers/Api/UsersController.php` (métodos `store`, `update`)
- Campos: `nome_completo`, `data_nascimento`, `sexo`, `morada`, `contacto`, `nif`.
- Classificação: 4. Escrita legacy a corrigir.
- Motivo: criação/edição API grava diretamente campos legacy em `users` fora dos fluxos Membros/Portal tratados na M3.2.
- Recomendação: alinhar com contrato reduzido de escrita (mínimo auth/operacional), delegando dados funcionais para estrutura canónica.

2. `app/Services/Members/MemberImportService.php` (método `createMemberFromPreparedRow`)
- Campos: payload legado amplo em `User::create(...)` (inclui campos pessoais/configuração legacy).
- Classificação: 4. Escrita legacy a corrigir.
- Motivo: importação continua a escrever em `users` como destino primário para dados que M3.2 já limitou nos fluxos principais.
- Recomendação: correção faseada no import para respeitar contrato M3.2/M3.3.

3. `app/Http/Controllers/Financeiro/ReceiptImportController.php` (serialização de item/lote)
- Campos lidos: `nome_completo`, `nif`.
- Classificação: 2. Dependência legacy tolerada.
- Motivo: leitura para revisão/matching de recibos antigos.
- Recomendação: manter nesta sprint, com fallback explícito.

### Outros

1. Comunicação
- `app/Services/Communication/CommunicationDeliveryService.php` e `app/Services/Communication/SegmentResolverService.php`.
- Campos: `contacto`, `nome_completo`.
- Classificação: 3. Dependência legacy a corrigir.
- Motivo: contacto/nome para entrega de campanhas e segmentação ainda dependem de `users`.
- Recomendação: criar camada de leitura canónica para contacto e nome de comunicação.

2. Loja/Admin Loja
- Controladores usam sobretudo `nome_completo` para display.
- Classificação: 2. Dependência legacy tolerada.
- Recomendação: manter por agora.

3. Jobs/Commands
- Uso encontrado maioritariamente em display/reporting (`nome_completo`) e utilitários de auditoria.
- Classificação: 2 (tolerada) ou 5 (falso positivo), conforme caso.
- Recomendação: sem intervenção imediata na M3.3-F1.

## Dependências a corrigir na M3.3

Prioridade curta (alterações seguras primeiro):

1. API Users (escrita direta legacy)
- Ficheiro: `app/Http/Controllers/Api/UsersController.php`
- Método: `store`, `update`
- Campo: `nome_completo`, `data_nascimento`, `sexo`, `morada`, `contacto`, `nif`
- Alteração recomendada: aplicar contrato de escrita reduzido em `users` e encaminhar dados pessoais/configuração para fonte canónica.
- Risco: Alto
- Testes recomendados: Feature tests API para create/update/read, regressão de autenticação/perfil e validação de compatibilidade.

2. Importação de membros (escrita direta legacy)
- Ficheiro: `app/Services/Members/MemberImportService.php`
- Método: `createMemberFromPreparedRow`
- Campo: payload legacy amplo em `User::create`
- Alteração recomendada: separar payload auth/operacional de payload pessoal/configuração; manter fallback.
- Risco: Alto
- Testes recomendados: testes de import preview/import, criação efetiva, deduplicação, e regressão de dados gerados.

3. Desportivo API (leitura pessoal direta)
- Ficheiro: `app/Http/Controllers/Api/AthleteController.php`
- Método: `show`
- Campo: `data_nascimento`
- Alteração recomendada: leitura canónica com fallback.
- Risco: Médio
- Testes recomendados: API tests de payload atleta com/sem dados em estrutura canónica.

4. Fiscal financeiro (leitura fiscal direta)
- Ficheiro: `app/Services/Financeiro/FiscalDocumentRequestService.php`, `app/Services/Financeiro/FiscalEmissionQueueService.php`
- Método: builders de payload fiscal
- Campo: `nif`, `morada`, `codigo_postal`, `localidade`, `nome_completo`
- Alteração recomendada: adapter único para dados fiscais do utilizador (canónico + fallback).
- Risco: Alto
- Testes recomendados: testes de criação de pedido fiscal e validação de campos obrigatórios em cenários com dados parciais.

## Dependências toleradas

Mantêm-se toleradas nesta sprint por baixo risco ou impacto operacional alto:

- leituras de `nome_completo` para display/search em Dashboard, Eventos, Auditoria, Loja e listagens administrativas;
- leituras de `nif`/`nome_completo` no matching de recibos antigos (importação histórica), onde a heurística atual já está estabilizada;
- usos operacionais de `name`, `email`, `perfil`, `estado`, `numero_socio` (classificados como seguro/auth-operacional).

## Falsos positivos

Padrões encontrados que não devem entrar como dívida M3.3:

- `nif`, `morada`, `contacto` em entidades não-`users` (ex.: `suppliers`, `club_settings`, patrocinadores);
- campos genéricos (`estado`, `tipo`, `observacoes`, `notas`, `numero`, `data`, `valor`, `email`, `name`) fora do contexto de dados pessoais legacy de `users`;
- labels/document metadata em Portal Documentos que não representam nova escrita funcional em `users` nesta análise cross-module.

## Critério para alterações futuras

Para execução segura das próximas fases:

1. corrigir primeiro leituras simples de display/consulta e escritas isoladas com baixo acoplamento;
2. evitar alterações profundas em Financeiro/Desportivo sem cobertura de testes e validação operacional;
3. não remover fallback nesta fase;
4. não remover colunas de `users` nesta sprint;
5. preferir relações `dadosPessoais`/`dadosConfiguracao` ou camada de leitura unificada quando aplicável.

## Próximo passo

Próxima tarefa recomendada:

M3.3-F1 — corrigir a primeira dependência simples e segura identificada no mapa.

Sugestão objetiva para M3.3-F1:

- começar por `app/Http/Controllers/Api/AthleteController.php` (`show`) para trocar leitura de `data_nascimento` para fonte canónica com fallback, por ter menor blast radius que fiscal/import.
