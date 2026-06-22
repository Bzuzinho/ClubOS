# M3.1 — Leituras legacy em Membros e Perfil

## Objetivo

Mapear os primeiros pontos onde a aplicação ainda lê campos funcionais de membro a partir de users, antes de qualquer refatoração funcional.

## Ficheiros analisados

- app/Http/Controllers/MembrosController.php
- app/Http/Controllers/PortalProfileController.php
- app/Services/Members/MemberDataReadService.php
- app/Services/Members/MemberDataWriteService.php

## Estado atual

### MembrosController

O fluxo show já usa MemberDataReadService e mergedMemberPayload, pelo que a ficha de membro já recebe dados consolidados com fallback.

O método index ainda usa User::select diretamente com campos funcionais como nome_completo, mantendo dependência de users para nome/listagem/ordenação.

Ainda existem leituras auxiliares diretas em users, sobretudo:
- listagem index;
- payload allUsers;
- contexto familiar;
- nomes/contactos de relações.

Nesta fase, estado, numero_socio, perfil, email de autenticação e campos operacionais continuam aceitáveis em users.

### PortalProfileController

O fluxo show carrega dadosPessoais e dadosConfiguracao, aplica MemberDataReadService e faz forceFill no modelo.

O buildProfilePayload ainda lê propriedades diretamente do modelo User, incluindo nif, morada, codigo_postal, localidade, nacionalidade, sexo, contacto, email_secundario e num_federacao.

Esta leitura é aceitável temporariamente porque ocorre depois do forceFill, mas deve ser refatorada numa fase posterior para receber um payload explícito, em vez de depender do modelo mutado em memória.

## Primeiro alvo recomendado

O primeiro alvo funcional da M3.1 deve ser MembrosController@index.

Motivo:
- é uma leitura simples;
- é visível ao utilizador;
- ainda depende de users para nome_completo;
- pode ficar desatualizada se no futuro os dados pessoais deixarem de ser sincronizados para users.

## Riscos

- Se a listagem continuar a ler users, alterações futuras apenas em dados_pessoais podem não refletir na lista.
- forceFill em PortalProfileController é compatível, mas esconde a origem dos dados.
- Relações familiares ainda usam nomes/contactos via User e devem continuar com fallback até existir payload dedicado.

## Regra de segurança

Não remover colunas de users nesta fase.

Qualquer alteração deve preservar fallback e compatibilidade com produção.

## Testes recomendados

- php artisan test tests/Feature/Membros/MemberDataReadFallbackTest.php
- php artisan test tests/Feature/Membros/MemberDataReadVisualSafetyTest.php
- php artisan test tests/Feature/Membros/MemberDataWriteCutoverTest.php
- php artisan test tests/Feature/Membros/MembrosFamilyContextTabPayloadTest.php

## Decisão

M3.1 documental fecha com este mapeamento.

A próxima tarefa funcional deverá refatorar apenas MembrosController@index, mantendo comportamento e cache.