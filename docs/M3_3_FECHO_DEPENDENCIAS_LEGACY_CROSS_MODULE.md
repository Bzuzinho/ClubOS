# M3.3 — Fecho das Dependências Legacy Cross-Module

## Objetivo
A M3.3 reduziu dependências legacy fora de Membros/Portal, focando leituras simples e seguras, sem remover colunas de `users` e sem remover fallback.

## Commits incluídos
- 4c1317d Document M3.3 cross-module legacy dependency map
- 29ee446 Use canonical athlete birth date in API
- aa4d705 Use canonical trainer names in teams
- 458f7b8 Use canonical user names in events payload

## Correções realizadas

### API Desportivo / AthleteController
- Campo tratado: `data_nascimento`.
- Antes: leitura direta de `users.data_nascimento`.
- Depois: `dados_pessoais.data_nascimento` com fallback para `users.data_nascimento`.
- Impacto: API de atleta passa a usar fonte canónica para data de nascimento.

### EquipasController
- Campo tratado: `nome_completo` de treinadores.
- Antes: lista de treinadores usava `users.nome_completo`.
- Depois: `dados_pessoais.nome_completo` com fallback para `users.nome_completo`/`users.name`.
- Impacto: criação/edição de equipas passa a apresentar nomes canónicos.

### EventosController
- Campo tratado: `nome_completo` na lista de utilizadores/atletas do módulo Eventos.
- Antes: `buildUsersPayload` usava `users.nome_completo` como fonte primária.
- Depois: `dados_pessoais.nome_completo` com fallback para `users.nome_completo`/`users.name`.
- Impacto: payload de users em Eventos passa a usar nome canónico.

## O que ficou deliberadamente fora da M3.3
Não foram tratados nesta sprint:
- Financeiro fiscal;
- emissão fiscal;
- importação de membros;
- API Users store/update;
- matching de recibos antigos;
- resultados/convocatórias/presenças em Eventos;
- leituras de display de baixo risco ainda espalhadas em Dashboard/Configurações.

Motivo:
- risco operacional superior;
- necessidade de testes específicos;
- dependências de faturação/importação;
- não se pretendia fazer refatoração ampla nesta sprint.

## Validação realizada
- `php -l` nos controllers alterados;
- testes locais executados nas fatias funcionais;
- `php artisan test --filter=Membros` usado como regressão transversal;
- deploys feitos em produção;
- auditoria de produção limpa:
  - missing 0/0
  - conflicts 0/0
  - duplicações 0

## Estado atual após M3.3
- `users` continua a existir com colunas legacy;
- `users` já não é fonte primária nos fluxos principais de Membros/Portal;
- três dependências cross-module simples foram corrigidas;
- fallback continua ativo;
- dependências de maior risco ficam documentadas para M3.4.

## Riscos remanescentes
- Financeiro ainda pode ler `users.nif`/`users.morada`/`users.codigo_postal`/`users.localidade`/`users.nome_completo`;
- `FiscalDocumentRequestService` e `FiscalEmissionQueueService` exigem adapter próprio antes de refatoração;
- `MemberImportService` ainda exige cuidado porque cria membros em massa;
- API Users store/update pode continuar a escrever legacy diretamente e precisa de testes antes de mexer;
- Dashboard/Configurações/Eventos ainda podem ter leituras de display toleradas.

## Próxima sprint
M3.4 — Final hardening and migration closure.

Objetivos:
- criar testes de proteção final;
- documentar dependências toleradas;
- decidir explicitamente o que fica como fallback;
- criar plano futuro para eventual remoção física de colunas legacy;
- evitar alterações de alto risco sem testes dedicados.

## Decisão de fecho
A M3.3 fica fechada com sucesso:
- mapa criado;
- três correções funcionais simples executadas;
- deploys validados;
- auditoria limpa;
- sem remoção de colunas;
- sem quebra de fallback.
