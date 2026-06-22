# M3 — Redução progressiva da dependência legacy da tabela `users`

## Objetivo

Reduzir gradualmente a dependência de campos funcionais de membro guardados diretamente na tabela `users`, migrando leituras e escritas para `dados_pessoais` e `dados_configuracao`.

A tabela `users` mantém-se como conta de autenticação, permissões, compatibilidade legacy e fallback enquanto a transição não estiver totalmente concluída.

## Estado de entrada

A fase M2 terminou com produção consolidada:

- `users_with_dados_pessoais`: 79
- `users_with_dados_configuracao`: 79
- `missing_dados_pessoais`: 0
- `missing_dados_configuracao`: 0
- `conflicts_dados_pessoais`: 0
- `conflicts_dados_configuracao`: 0
- duplicações relevantes: 0

## Regra de segurança

Nesta fase não devem ser removidas colunas da tabela `users`.

A remoção física de colunas só poderá ser avaliada depois de:

- leituras principais usarem `dados_pessoais` / `dados_configuracao`;
- escritas principais usarem serviços dedicados;
- fallback legacy estar testado;
- produção estar auditada sem conflitos;
- existir plano de rollback;
- existir validação em produção após deploy.

## Estratégia M3

### M3.0 — Mapeamento

Identificar leituras e escritas que ainda usam campos funcionais diretamente em `users`.

Categorias:

- serviços canónicos já seguros;
- controllers de membros/perfil;
- módulos cruzados que consomem dados de membro;
- contratos frontend;
- superfície legacy do modelo `User`;
- dependências a rever manualmente.

### M3.1 — Leituras

Substituir leituras diretas em controllers e páginas por payload consolidado vindo de `MemberDataReadService`, quando for seguro.

### M3.2 — Escritas

Garantir que todos os formulários relevantes escrevem através de `MemberDataWriteService` ou fluxo equivalente com dual-write controlado.

### M3.3 — Contratos e testes

Reforçar testes para impedir regressão para escrita exclusiva em `users`.

### M3.4 — Depreciação controlada

Marcar campos legacy como deprecated a nível documental e preparar a eventual remoção futura, sem alterar produção de forma destrutiva.

## Critérios de segurança para remoção futura

Antes de remover qualquer coluna legacy em `users`, é obrigatório confirmar:

- 100% das leituras principais em `dados_pessoais` / `dados_configuracao`;
- 100% das escritas principais via serviços canónicos;
- fallback legacy coberto por testes de não-regressão;
- auditoria de produção sem conflitos nem lacunas;
- plano de rollback documentado e validado;
- validação pós-deploy em produção concluída.

## Campos abrangidos

### Dados pessoais

- `nome_completo`
- `data_nascimento`
- `sexo`
- `nif`
- `documento_identificacao`
- `tipo_documento`
- `validade_documento`
- `nacionalidade`
- `naturalidade`
- `morada`
- `codigo_postal`
- `localidade`
- `distrito`
- `concelho`
- `contacto`
- `contacto_alternativo`
- `email_secundario`
- `tipo_utilizador`
- `observacoes`

### Dados de configuração

- `consentimento_rgpd`
- `rgpd`
- `consentimento_imagem`
- `declaracao_transporte`
- `afiliacao_federativa`
- `afiliacao_numero`
- `num_federacao`
- `certificado_medico_ficheiro`
- `termos_aceites`
- `receber_comunicacoes`
- `acesso_portal_ativo`

## Regra operacional

A M3 não deve introduzir alterações destrutivas.

Cada alteração deve ser pequena, testável e reversível.

O primeiro alvo técnico da M3.1 deve ser escolhido apenas depois de confirmar onde a aplicação ainda lê diretamente campos funcionais em `users`.
