# M3.2 — Fecho das Escritas Legacy em users

## Objetivo

A M3.2 reduziu a escrita direta de dados funcionais na tabela users, mantendo users como tabela de autenticação, estado operacional, compatibilidade e fallback.

## Commits incluídos

- 521b541 Document M3.2 legacy member write contracts
- 1095b85 Limit portal profile legacy user writes
- c37c2c5 Limit member update legacy user writes
- 2ea0d39 Limit member store legacy user writes

## Fluxos tratados

### PortalProfileController@update

- deixou de gravar o payload funcional completo em users;
- mantém apenas payload legacy mínimo;
- os dados pessoais continuam a ser persistidos via MemberDataWriteService.

### MembrosController@update

- deixou de executar update com payload completo;
- passou a usar legacyUserPayloadForMemberWrite();
- dados completos continuam a seguir para MemberDataWriteService.

### MembrosController@store

- User::create($data) foi substituído por User::create($this->legacyUserPayloadForMemberWrite($data));
- dados_pessoais e dados_configuracao continuam a ser criados via MemberDataWriteService;
- criação de membros foi validada por testes.

## Contrato atual da tabela users

users mantém:

- auth/account: name, email, email_utilizador, password;
- permissões/estado: perfil, estado;
- compatibilidade operacional: numero_socio, tipo_membro, escalao, ativo_desportivo, menor, foto_perfil;
- campos operacionais ainda não migrados;
- fallback temporário enquanto outros módulos não forem totalmente refatorados.

## Campos funcionais que deixam de ser fonte primária em users

- nome_completo
- data_nascimento
- sexo
- nif
- cc
- documento_identificacao
- morada
- codigo_postal
- localidade
- nacionalidade
- contacto
- contacto_alternativo
- email_secundario
- rgpd
- consentimento
- declaracao_de_transporte
- afiliacao
- num_federacao

## Validação realizada

- php -l nos controllers alterados;
- MemberDataWriteCutoverTest;
- MemberDataReadFallbackTest;
- MemberDataReadVisualSafetyTest;
- MembrosFamilyContextTabPayloadTest;
- php artisan test --filter=Membros;
- deploys feitos em produção;
- auditoria de produção limpa:
  - missing 0/0
  - conflicts 0/0
  - duplicações 0

Produção validada:

- total_users: 79
- users_with_dados_pessoais: 79
- users_with_dados_configuracao: 79
- missing_dados_pessoais: 0
- missing_dados_configuracao: 0
- conflicts_dados_pessoais: 0
- conflicts_dados_configuracao: 0
- possíveis duplicações: 0

## Riscos remanescentes

- users ainda contém colunas legacy;
- alguns módulos fora de Membros/Portal podem ainda ler users diretamente;
- a remoção física de colunas não faz parte desta fase;
- a próxima etapa deve focar dependências cross-module.

## Próxima sprint

M3.3 — Cross-module legacy dependency reduction.

Objetivo da M3.3:

- mapear leituras e escritas diretas a users fora de Membros/Portal;
- corrigir apenas dependências simples e seguras;
- documentar dependências toleradas;
- não remover colunas;
- manter fallback.
