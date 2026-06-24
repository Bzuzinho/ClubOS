# M4.0 — Plano de Aposentação de Colunas Legacy users

## Objetivo
Explicar que a M4 não pretende remover colunas imediatamente, mas preparar de forma segura uma futura redução de fallback e eventual remoção física de colunas legacy em users.

## Estado de partida
Explicar:
- a migração funcional está fechada;
- dados_pessoais é fonte primária para dados pessoais;
- dados_configuracao é fonte primária para configuração legal/RGPD/afiliação;
- users mantém auth, permissões, estado operacional, compatibilidade e fallback;
- colunas legacy ainda existem fisicamente.

## Princípios da M4
Listar:
1. Não remover colunas sem plano explícito.
2. Não remover fallback sem observabilidade e testes.
3. Não mexer em Financeiro/Fiscal sem adapter e testes próprios.
4. Não mexer em importação de membros sem testes dedicados.
5. Não alterar API Users sem cobrir store/update/read.
6. Cada corte deve ser pequeno, testado e reversível.
7. Produção deve ser auditada após cada alteração funcional.
8. Qualquer migration destrutiva fica proibida até sprint específica aprovada.

## Áreas críticas

### Financeiro/Fiscal
Explicar que ainda pode depender de:
- users.nif
- users.morada
- users.codigo_postal
- users.localidade
- users.nome_completo

Risco:
- emissão fiscal;
- documentos fiscais;
- pedidos fiscais;
- filas de emissão;
- reembolsos/recibos.

Recomendação:
- criar adapter fiscal único, por exemplo MemberFiscalDataResolver ou UserFiscalDataResolver;
- adapter deve ler dados_pessoais primeiro e fallback users;
- só depois refatorar serviços fiscais.

### Importação de membros
Explicar que MemberImportService pode ainda criar payload amplo em users.
Risco:
- criação massiva;
- deduplicação;
- dados parciais;
- preview/import;
- rollback difícil.

Recomendação:
- criar testes antes de alterar;
- separar payload auth/operacional de payload canónico;
- manter fallback.

### API Users
Explicar que store/update podem ainda aceitar dados pessoais legacy.
Risco:
- integrações externas;
- contratos API;
- payloads antigos;
- permissões/autenticação.

Recomendação:
- mapear rotas;
- criar testes API;
- depois aplicar contrato semelhante ao MembrosController.

### Dashboard / Configurações / Relatórios
Explicar que podem existir leituras de display legacy.
Risco:
- baixo a médio;
- principalmente nomes/listagens/pesquisa.

Recomendação:
- corrigir apenas leituras simples;
- não bloquear fecho se houver fallback.

## Colunas candidatas a aposentação futura
Agrupar por risco.

### Risco baixo
- nome_completo, apenas depois de todos os displays usarem dados_pessoais;
- data_nascimento, depois de APIs/desportivo estarem cobertos.

### Risco médio
- contacto
- contacto_alternativo
- email_secundario
- morada
- codigo_postal
- localidade
- nacionalidade
- sexo
- cc
- documento_identificacao

### Risco alto
- nif
- rgpd
- consentimento
- declaracao_de_transporte
- afiliacao
- num_federacao
- campos usados em fiscal/importação/API externa

## Estratégia proposta para a M4

### M4.1 — Fiscal data resolver
Objetivo:
Criar adapter fiscal canónico com fallback, sem alterar ainda emissão fiscal de forma ampla.

### M4.2 — Refatoração fiscal controlada
Objetivo:
Usar o adapter fiscal em FiscalDocumentRequestService e FiscalEmissionQueueService, com testes.

### M4.3 — Importação de membros
Objetivo:
Criar testes e separar escrita canonical/legacy na importação.

### M4.4 — API Users
Objetivo:
Criar testes API e reduzir escrita/leitura legacy.

### M4.5 — Legacy fallback observability
Objetivo:
Criar logs/auditoria para saber quando fallback users ainda é usado.

### M4.6 — Decisão sobre remoção física
Objetivo:
Decidir se vale a pena remover colunas ou mantê-las como compatibilidade permanente.

## O que está proibido nesta fase
Listar explicitamente:
- migrate:fresh
- migrate:refresh
- migrate:reset
- db:wipe
- DROP COLUMN em users sem sprint própria;
- remoção de fallback sem testes;
- alteração fiscal sem testes;
- alteração de importação sem testes;
- git add .

## Critério para iniciar alteração funcional M4
Antes de qualquer alteração funcional:
- ficheiro alvo identificado;
- risco classificado;
- testes existentes ou novos definidos;
- rollback possível;
- produção auditável;
- alteração pequena.

## Primeira tarefa funcional recomendada
M4.1-F1 — criar testes e adapter fiscal canónico com fallback.

Objetivo:
- criar resolver de dados fiscais do utilizador;
- ler dados_pessoais primeiro;
- fallback para users;
- sem alterar ainda o comportamento fiscal final, ou alterar apenas por adapter mantendo payload igual.

## Decisão
Declarar:
A M4 começa como fase de preparação e redução controlada.
A remoção física de colunas legacy não é objetivo imediato.
O objetivo imediato é retirar risco de Financeiro/Fiscal, Importação e API Users antes de qualquer decisão destrutiva.