# M3.2 — Escritas legacy e contratos de gravação

## Objetivo

Mapear onde a aplicação ainda grava campos funcionais de membro diretamente em `users`, garantindo que os fluxos principais passam por `MemberDataWriteService` ou mantêm dual-write controlado.

## Regra de segurança

Não remover colunas de `users`.

Não alterar migrations.

Não remover validações legacy ainda usadas pelo frontend.

Não eliminar dual-write enquanto existirem módulos dependentes de `users`.

## Ficheiros analisados

- `app/Http/Controllers/MembrosController.php`
- `app/Http/Controllers/PortalProfileController.php`
- `app/Services/Members/MemberDataWriteService.php`
- `app/Http/Requests/StoreMembroRequest.php`
- `app/Http/Requests/UpdateMemberRequest.php`
- `app/Models/User.php`

## Estado atual mapeado

### MembrosController@store

Fluxo observado:
- cria membro via `User::create($data)`;
- de seguida chama `MemberDataWriteService::persistFromMemberRequest(...)`.

Classificação:
- dual-write aceitável temporariamente.

Risco:
- `User::create($data)` continua a permitir escrita direta de campos funcionais em `users` antes da persistência canónica.

Decisão:
- manter nesta fase por compatibilidade legacy;
- em refatoração futura, separar payload auth/operacional de payload funcional antes da criação em `users`.

### MembrosController@update

Fluxo observado:
- executa `member->update($data)`;
- depois chama `MemberDataWriteService::persistFromMemberRequest(...)`.

Classificação:
- dual-write aceitável temporariamente.

Risco:
- há escrita direta em `users` no `update($data)` para campos funcionais; o serviço canónico é chamado depois.

Decisão:
- manter temporariamente nesta fase de transição;
- alvo funcional de hardening em M3.2+ para reduzir escrita funcional direta em `users`.

### PortalProfileController@update

Fluxo observado:
- chama `MemberDataWriteService::persistFromMemberRequest(...)`;
- em seguida executa `targetMember->fill($data)` e `targetMember->save()`.

Classificação:
- escrita legacy a refatorar.

Risco:
- após a escrita canónica, volta a gravar payload funcional em `users` de forma ampla.

Decisão:
- candidato prioritário para primeiro alvo funcional da M3.2;
- reduzir `fill/save` aos campos auth/operacionais necessários, mantendo compatibilidade sem quebrar frontend.

### MemberDataWriteService

Contrato canónico confirmado para escrita em:
- `dados_pessoais` (via `persistPersonalData`);
- `dados_configuracao` (via `persistConfigurationData`).

Comportamento relevante:
- usa mapeamento de input legacy para estrutura canónica;
- mantém sincronização legacy controlada em `users` (`syncLegacyUserPersonalFields` e `syncLegacyUserConfigurationFields`) como dual-write de compatibilidade.

Cobertura dos campos funcionais desta sprint:

Dados pessoais suportados no contrato canónico:
- `nome_completo`, `data_nascimento`, `sexo`, `nif`, `documento_identificacao` (inclui alias `cc`), `tipo_documento`, `validade_documento` (inclui alias `data_validade_cc`), `nacionalidade`, `naturalidade`, `morada`, `codigo_postal`, `localidade`, `distrito`, `concelho`, `contacto` (aceita `contacto`/`telefone`/`telemovel`), `contacto_alternativo` (aceita `contacto_alternativo`/`contacto_telefonico`), `email_secundario`, `tipo_utilizador` (aceita `perfil`), `observacoes` (aceita `notas`).

Dados de configuração suportados no contrato canónico:
- `consentimento_rgpd` (aceita `rgpd`), `consentimento_rgpd_data` (aceita `data_rgpd`), `consentimento_imagem` (aceita `consentimento`), `consentimento_imagem_data` (aceita `data_consentimento`), `declaracao_transporte` (aceita `declaracao_de_transporte`), `afiliacao_federativa` (aceita `afiliacao`), `afiliacao_numero` (aceita `num_federacao`), `certificado_medico_ficheiro` (aceita `arquivo_atestado_medico`), `afiliacao_ficheiro` (aceita `arquivo_afiliacao`), `termos_aceites`, `receber_comunicacoes`, `acesso_portal_ativo`.

Campos legacy de configuração ainda fora do contrato canónico nominal:
- `arquivo_rgpd` e `arquivo_consentimento` continuam a ser persistidos em `users`.

### Requests

`StoreMembroRequest` e `UpdateMemberRequest` continuam a validar chaves legacy usadas no frontend.

Classificação:
- contrato input legacy aceitável.

Nota:
- nesta fase, estas validações não representam erro arquitetural, desde que a persistência funcional continue a convergir para `MemberDataWriteService`.

### User.php

`$fillable` e `casts` mantêm diversos campos funcionais legacy de membro.

Classificação:
- superfície legacy necessária enquanto existir dual-write e fallback.

Nota:
- não remover campos nesta fase M3.2 documental.

## Classificação resumida por categoria

1. Escrita canónica segura:
- `MemberDataWriteService::persistPersonalData` e `persistConfigurationData`.

2. Dual-write aceitável:
- `MembrosController@store`;
- `MembrosController@update`;
- sincronização explícita em `MemberDataWriteService` para compatibilidade (`syncLegacyUser*`).

3. Escrita legacy tolerada temporariamente:
- manutenção de campos funcionais em `users` via dual-write controlado, por dependências legacy ainda ativas.

4. Escrita legacy a refatorar:
- `PortalProfileController@update` (chamada canónica seguida de `fill/save` amplo em `users`).

5. Campo operacional/auth:
- devem continuar em `users` nesta fase: `email`, `password`, `estado`, `perfil`, `numero_socio`, permissões e restantes metadados de autenticação/operação.

## Primeiro alvo funcional recomendado

Candidato prioritário:
- `PortalProfileController@update`.

Objetivo da futura alteração:
- manter escrita canónica em `dados_pessoais`/`dados_configuracao`;
- limitar escrita em `users` ao mínimo necessário para auth/compatibilidade;
- preservar fallback;
- não alterar frontend.

## Testes recomendados

Antes/depois de qualquer alteração funcional:

- `php artisan test tests/Feature/Membros/MemberDataWriteCutoverTest.php`
- `php artisan test tests/Feature/Membros/MemberDataReadFallbackTest.php`
- `php artisan test tests/Feature/Membros/MemberDataReadVisualSafetyTest.php`
- `php artisan test tests/Feature/Membros/MembrosFamilyContextTabPayloadTest.php`

Se mexer no portal:

- procurar e correr testes de portal/profile se existirem.

## Critério de fecho da M3.2 documental

A M3.2 documental fica fechada quando:
- os fluxos principais de escrita estiverem classificados;
- o primeiro alvo funcional estiver identificado;
- não houver alteração funcional nesta tarefa;
- o documento estiver commitado.
