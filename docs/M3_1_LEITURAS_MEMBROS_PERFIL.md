# M3.1 — Leituras legacy em Membros e Portal Profile

## Objetivo

Mapear, sem alterar comportamento, onde `MembrosController` e `PortalProfileController` ainda dependem de campos funcionais de membro lidos diretamente de `users`, tendo como referência a camada canónica já existente em `MemberDataReadService` e `MemberDataWriteService`.

## Ficheiros analisados

- `app/Http/Controllers/MembrosController.php`
- `app/Http/Controllers/PortalProfileController.php`
- `app/Services/Members/MemberDataReadService.php`
- `app/Services/Members/MemberDataWriteService.php`

## Resumo das dependências encontradas

### 1. `MembrosController`

Estado geral: leitura mista, mas a superfície principal de ficha/edição já passa por `MemberDataReadService`.

#### Já usa `MemberDataReadService`

- `show()` aplica `mergedMemberPayload()` sobre `$member->toArray()` antes de renderizar a ficha.
- `edit()` devolve `member` já consolidado via `mergedMemberPayload()`.

#### Leitura direta legacy a refatorar

- `index()` seleciona e ordena diretamente por `users.nome_completo` para a listagem de membros.
- `buildFamilyContext()` usa diretamente:
  - `nome_completo` com fallback para `name` em guardiões, educandos e membros de família;
  - `contacto`, `telemovel` e `contacto_telefonico` para contacto de guardião.
- `sendAccessEmail()` volta a ler `nome_completo` de `users` para sincronizar `name` ao enviar acessos.

#### Compatibilidade / fallback aceitável temporariamente

- Fallbacks de relações legacy em `show()` para `encarregados` e `educandos` usam `select('nome_completo', 'name', ...)` apenas para reconstrução visual de relações antigas quando o payload já consolidado não traz dados.
- Leituras de `name` como identidade de autenticação/display continuam aceitáveis nesta fase, desde que não substituam a migração dos campos funcionais de membro.

#### Escrita já coberta por `MemberDataWriteService`

- `store()` chama `persistFromMemberRequest()` após `User::create()`.
- `update()` chama `persistFromMemberRequest()` após `refresh()` do modelo.

### 2. `PortalProfileController`

Estado geral: a controller já injeta a leitura canónica no `show()`, mas o payload final ainda consome vários campos diretamente de `User` em `buildProfilePayload()`.

#### Já usa `MemberDataReadService`

- `show()` carrega `dadosPessoais` e `dadosConfiguracao` e faz `forceFill()` com o resultado de `mergedMemberPayload()`.

#### Leitura direta legacy a refatorar

Em `buildProfilePayload()`, continuam a existir leituras diretas dos seguintes campos funcionais:

- `data_nascimento`
- `nif`
- `morada`
- `codigo_postal`
- `localidade`
- `nacionalidade`
- `sexo`
- `contacto`
- `email_secundario`
- `rgpd`
- `num_federacao`

Leituras relacionadas com alias legacy do mesmo domínio funcional:

- `cc` como alias legacy de `documento_identificacao`
- `data_rgpd` como data legacy de consentimento RGPD
- `consentimento` como alias legacy de consentimento de imagem
- `declaracao_de_transporte` como alias legacy de declaração de transporte

Observação importante: apesar de `forceFill()` reduzir risco visual imediato, o payload continua acoplado às chaves antigas de `users` e aos aliases legacy gerados por `mergedMemberPayload()`. O acoplamento não desapareceu; apenas ficou mascarado por preenchimento prévio do modelo.

#### Compatibilidade / fallback aceitável temporariamente

- `displayName()` usa `nome_completo ?: name`, o que continua aceitável como fallback de apresentação enquanto `name` permanecer o identificador de autenticação.
- Leituras de `numero_socio`, `estado`, `foto_perfil`, `tipo_membro`, `menor` e dados desportivos/financeiros não entram neste mapeamento M3.1 porque não são os campos funcionais alvo desta sprint.

#### Escrita já coberta por `MemberDataWriteService`

- `update()` chama `persistFromMemberRequest()` antes de `fill()` e `save()` em `users`.

## Classificação das ocorrências por campo

| Campo / grupo | Local | Classificação | Nota |
|---|---|---|---|
| `nome_completo` | `MembrosController::show()` e `edit()` | já usa `MemberDataReadService` | via `mergedMemberPayload()` |
| `nome_completo` | `MembrosController::index()` | leitura direta legacy a refatorar | listagem e ordenação ainda em `users` |
| `nome_completo` | `MembrosController::buildFamilyContext()` | leitura direta legacy a refatorar | guardiões/educandos/famílias |
| `nome_completo` | `PortalProfileController::displayName()` | compatibilidade/fallback aceitável temporariamente | fallback `name` ainda é aceitável |
| `data_nascimento` | `MembrosController::show()` e `edit()` | já usa `MemberDataReadService` | normalizado no payload consolidado |
| `data_nascimento` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `sexo` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `nif` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `documento_identificacao` | `PortalProfileController::buildProfilePayload()` via `cc` | leitura direta legacy a refatorar | alias legacy do mesmo campo |
| `nacionalidade` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `morada` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `codigo_postal` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `localidade` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `contacto` | `MembrosController::buildFamilyContext()` | leitura direta legacy a refatorar | usa cadeia `contacto_telefonico/contacto/telemovel` |
| `contacto` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `email_secundario` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | payload editável e secção pessoal |
| `rgpd` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | booleano e data legacy ainda lidos no payload |
| `consentimento_imagem` | `PortalProfileController::buildProfilePayload()` via `consentimento` | leitura direta legacy a refatorar | alias legacy |
| `declaracao_transporte` | `PortalProfileController::buildProfilePayload()` via `declaracao_de_transporte` | leitura direta legacy a refatorar | alias legacy |
| `num_federacao` / `afiliacao_numero` | `PortalProfileController::buildProfilePayload()` | leitura direta legacy a refatorar | secções editável, documentos e desporto |

## Campos da lista alvo sem ocorrência nestes ficheiros

Nestes quatro ficheiros não foram encontradas ocorrências relevantes de leitura direta para:

- `tipo_documento`
- `validade_documento`
- `naturalidade`
- `distrito`
- `concelho`
- `contacto_alternativo`
- `tipo_utilizador`
- `observacoes`
- `consentimento_rgpd`
- `afiliacao_federativa`
- `certificado_medico_ficheiro`
- `termos_aceites`
- `receber_comunicacoes`
- `acesso_portal_ativo`

Classificação: falso positivo para o recorte desta análise, porque fazem parte do universo M3 mas não apareceram nestas duas controllers como leitura direta atual.

## Primeiro alvo recomendado de refatoração

Primeiro alvo M3.1 recomendado: `PortalProfileController::buildProfilePayload()`.

Razões:

1. Já existe pré-condição técnica favorável: a controller já carrega `dadosPessoais` e `dadosConfiguracao` e já chama `MemberDataReadService` no `show()`.
2. O impacto é local e bem delimitado ao payload do portal, sem tocar ainda em `MembrosController` nem em fluxos administrativos maiores.
3. Concentra várias leituras legacy do mesmo domínio num único ponto de montagem de resposta.
4. Permite reduzir rapidamente o acoplamento a aliases legacy como `cc`, `rgpd`, `consentimento` e `declaracao_de_transporte`.

Sequência sugerida após esta análise:

1. introduzir em `buildProfilePayload()` consumo explícito do payload composto em vez de leituras diretas de `User`;
2. só depois atacar `MembrosController::index()` e `buildFamilyContext()`;
3. manter `displayName()` e `name` como fallback temporário de identidade.

## Riscos

- O `forceFill()` atual em `PortalProfileController` pode dar falsa sensação de cutover completo, porque o código continua dependente de nomes/aliases legacy.
- Refatorar o portal sem preservar os aliases esperados pelo frontend pode introduzir regressões visuais em formulários e cartões do perfil.
- `MembrosController::index()` usa `nome_completo` para ordenação/listagem; mudar esta leitura exigirá confirmar impacto de performance e ordenação quando o nome vier do payload composto.
- Relações familiares ainda combinam dados relacionais novos com reconstrução legacy local; mexer cedo nessa zona amplia o risco funcional.

## Testes a correr antes e depois

- `php artisan test tests/Feature/Membros/MemberDataReadFallbackTest.php`
- `php artisan test tests/Feature/Membros/MemberDataWriteCutoverTest.php`
- `php artisan test tests/Feature/Membros/MemberDataReadVisualSafetyTest.php`

## Regra de segurança

Não remover colunas de `users` nesta fase.

`users` continua a ser tabela de autenticação, compatibilidade legacy e fallback até nova validação da M3.