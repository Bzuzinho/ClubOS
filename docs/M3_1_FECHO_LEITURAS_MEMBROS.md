# M3.1 — Fecho da refatoração incremental de leituras de membros

## Objetivo

Reduzir a dependência direta da tabela `users` nas leituras principais do módulo de membros, usando `dados_pessoais` como origem preferencial para dados pessoais simples, mantendo fallback legacy para `users`.

## Alterações concluídas

### 1. Listagem de membros

Commit:

- `494db1d Use canonical member name in members index`

O método `MembrosController@index` passou a carregar `dadosPessoais` e a usar `dados_pessoais.nome_completo` como origem preferencial para o nome apresentado na listagem, com fallback para `users.nome_completo` / `users.name`.

### 2. Payload `allUsers` na ficha de membro

Commit:

- `de698c5 Use canonical member data in member selector payload`

O bloco `allUsers` dentro de `MembrosController@show` passou a usar `dados_pessoais.nome_completo` e `dados_pessoais.data_nascimento` como origem preferencial, mantendo fallback legacy.

### 3. Contexto familiar

Commit:

- `d42362f Use canonical member data in family context`

O método `buildFamilyContext()` passou a resolver nomes e contactos de membros relacionados usando `dados_pessoais` como origem preferencial, mantendo fallback para os campos legacy em `users`.

## Estado validado em produção

Após deploy da última alteração funcional, foi executada a auditoria:

```bash
sudo -u www-data -H php artisan members:audit-data-structure
```

Resultado validado:

- total_users: 79
- users_with_personal_payload: 79
- users_with_configuration_payload: 79
- users_with_dados_pessoais: 79
- users_with_dados_configuracao: 79
- missing_dados_pessoais: 0
- missing_dados_configuracao: 0
- conflicts_dados_pessoais: 0
- conflicts_dados_configuracao: 0
- absent_source_fields: 11
- suspicious_values: 2
- possíveis duplicações: 0

## Conclusão

A sprint M3.1 fica encerrada com os objetivos cumpridos: leituras principais de membros/perfil migradas para origem canónica (`dados_pessoais`) com fallback legacy preservado, sem remoção de colunas em `users` e sem regressões funcionais validadas em produção.
