# Importacao De Membros - Especificacao Tecnica

## Objetivo

Adicionar ao modulo Membros uma importacao assistida de utilizadores a partir de Excel/CSV com:

- upload do ficheiro
- preview das colunas encontradas
- mapeamento manual por grupos `Pessoal`, `Financeiro`, `Desportivo` e `Configuracao`
- validacao antes de gravar
- importacao em lote com resumo final

O fluxo deve alinhar com os campos usados hoje nas tabs de criacao/edicao de membros e evitar, na V1, uploads de ficheiros e relacoes complexas entre membros.

## Superficie Atual Relevante

- Lista de membros: `resources/js/Pages/Membros/ListTab.tsx`
- Pagina principal do modulo: `resources/js/Pages/Membros/Index.tsx`
- Formulario de criacao: `resources/js/Pages/Membros/Create.tsx`
- Tab Pessoal: `resources/js/Components/Members/Tabs/PersonalTab.tsx`
- Tab Financeiro: `resources/js/Components/Members/Tabs/FinancialTab.tsx`
- Tab Desportivo: `resources/js/Components/Members/Tabs/Sports/DadosDesportivosTab.tsx`
- Tab Configuracao: `resources/js/Components/Members/Tabs/ConfigurationTab.tsx`
- Controller atual: `app/Http/Controllers/MembrosController.php`
- Validacao atual: `app/Http/Requests/StoreMembroRequest.php`

## Objetos Da Solucao

### Frontend

- Novo botao `Importar utilizadores` em `resources/js/Pages/Membros/ListTab.tsx`
- Novo dialog `MemberImportDialog`
- Novo componente `MemberImportFieldMappingStep`
- Novo componente `MemberImportPreviewTable`
- Novo componente `MemberImportResultSummary`

### Backend

- Novas rotas:
  - `GET membros.import.template`
  - `POST membros.import.preview`
  - `POST membros.import.store`
- Novo controller dedicado: `app/Http/Controllers/MembrosImportController.php`
- Novo request para preview: `app/Http/Requests/PreviewMembrosImportRequest.php`
- Novo request para importacao final: `app/Http/Requests/StoreMembrosImportRequest.php`
- Novo service: `app/Services/Members/MemberImportService.php`
- Novo DTO ou array contract para linhas normalizadas e erros

## UX Proposta

### Entrada No Modulo

Na lista de membros, junto ao botao `Novo Membro`, expor:

- `Importar utilizadores`
- `Descarregar modelo`

### Fluxo Do Dialog

#### Passo 1 - Ficheiro

Objetivo: receber o ficheiro e validar formato base.

Aceitar:

- `.xlsx`
- `.xls`
- `.csv`

Mostrar:

- nome do ficheiro
- tamanho
- numero estimado de colunas detetadas
- numero estimado de linhas com dados

Bloquear avance se:

- formato invalido
- sem cabecalhos
- sem linhas de dados

#### Passo 2 - Mapeamento

Objetivo: para cada campo de destino, perguntar qual a coluna de origem.

Grupos apresentados:

- `Pessoal`
- `Financeiro`
- `Desportivo`
- `Configuracao`

Cada campo mostra:

- label amigavel
- nome tecnico do campo
- obrigatoriedade
- tipo esperado
- seletor da coluna de origem
- opcao `Nao importar`

Funcionalidades recomendadas:

- automatch por nome semelhante do cabecalho
- detetar colunas duplicadas atribuídas a mais de um campo
- destaque para campos obrigatorios sem mapeamento

#### Passo 3 - Preview E Validacao

Objetivo: validar as primeiras linhas normalizadas antes da gravacao.

Mostrar:

- total de linhas do ficheiro
- linhas validas
- linhas com aviso
- linhas com erro
- tabela preview das primeiras 10 a 20 linhas
- lista de erros por linha

Tipos de erro:

- campo obrigatorio em falta
- formato de data invalido
- valor enum invalido
- email duplicado no ficheiro
- email ja existente no sistema
- numero de socio ja existente
- referencia nao encontrada em catalogos (`escalao`, `tipo_mensalidade`, `centro_custo`)

Tipos de aviso:

- `numero_socio` vazio e sera gerado automaticamente
- `menor` vazio e sera recalculado por `data_nascimento`
- `perfil` vazio e sera assumido por default
- `ativo_desportivo` mapeado para membro nao atleta

#### Passo 4 - Confirmacao

Objetivo: gravar apenas linhas validas.

Opcoes:

- `Importar apenas linhas validas`
- `Cancelar`

Resumo antes de gravar:

- total a importar
- total ignorado
- catalogos usados na resolucao

#### Passo 5 - Resultado

Mostrar:

- membros criados
- linhas rejeitadas
- numero de erros
- download opcional de relatorio CSV de erros

## Estados De UI

```ts
type MemberImportStep = 'file' | 'mapping' | 'preview' | 'confirm' | 'result';

interface MemberImportDialogState {
  open: boolean;
  step: MemberImportStep;
  uploadFile: File | null;
  uploadToken: string | null;
  headers: string[];
  sampleRows: Array<Record<string, unknown>>;
  mapping: Record<string, string | null>;
  preview: ImportPreviewResponse | null;
  result: ImportStoreResponse | null;
  isSubmitting: boolean;
  error: string | null;
}
```

## Campos V1 Recomendados

### Pessoal

- `nome_completo` - obrigatorio
- `numero_socio` - opcional
- `data_nascimento`
- `sexo` - `masculino|feminino`
- `estado` - `ativo|inativo|suspenso`
- `tipo_membro` - string multipla separada por `|`
- `nif`
- `cc`
- `morada`
- `codigo_postal`
- `localidade`
- `nacionalidade`
- `estado_civil`
- `contacto_telefonico`
- `email_secundario`
- `numero_irmaos`
- `ocupacao`
- `empresa`
- `escola`

### Financeiro

- `tipo_mensalidade` - por designacao
- `centro_custo` - por nome
- `conta_corrente_manual`

### Desportivo

- `ativo_desportivo` - `1/0`, `sim/nao`, `true/false`
- `num_federacao`
- `numero_pmb`
- `escalao` - por nome
- `data_inscricao`
- `data_atestado_medico`
- `informacoes_medicas`

### Configuracao

- `email_utilizador`
- `perfil` - `admin|encarregado|atleta|staff`
- `rgpd`
- `data_rgpd`
- `consentimento`
- `data_consentimento`
- `afiliacao`
- `data_afiliacao`
- `declaracao_de_transporte`

## Fora De Escopo Na V1

Nao importar na primeira versao:

- `foto_perfil`
- `cartao_federacao`
- `arquivo_rgpd`
- `arquivo_consentimento`
- `arquivo_afiliacao`
- `arquivo_atestado_medico`
- `encarregado_educacao`
- `educandos`
- `inscricao` como ficheiro
- campos legacy `name`, `email`, `senha`

Razao:

- sao ficheiros, arrays, relacoes reciprocas ou campos legacy que exigem regras adicionais

## Contrato De Mapeamento

Payload enviado pelo frontend para preview/import:

```json
{
  "upload_token": "tmp_01HS...",
  "mapping": {
    "nome_completo": "Nome Completo",
    "numero_socio": "Numero",
    "data_nascimento": "Nascimento",
    "sexo": "Sexo",
    "estado": "Estado",
    "tipo_membro": "Tipo",
    "tipo_mensalidade": "Mensalidade",
    "centro_custo": "Centro Custo",
    "escalao": "Escalao",
    "email_utilizador": "Email Login",
    "perfil": "Perfil"
  },
  "options": {
    "delimiter": ";",
    "date_format": "Y-m-d",
    "skip_empty_rows": true,
    "trim_strings": true
  }
}
```

Semantica:

- chave = campo de destino no sistema
- valor = nome da coluna existente no ficheiro
- `null` = nao importar esse campo

## Endpoints

### `GET /membros/import/template`

Objetivo:

- descarregar o modelo recomendado

Resposta:

- ficheiro `storage/app/templates/membros_import_template_v2.csv`
- futuramente pode devolver `.xlsx` gerado a partir da mesma estrutura

### `POST /membros/import/preview`

Objetivo:

- carregar ficheiro
- ler cabecalhos
- devolver preview e catalogos para o passo de mapeamento

Request `multipart/form-data`:

- `file` - obrigatorio

Response sugerida:

```json
{
  "upload_token": "tmp_01HS...",
  "headers": ["Nome Completo", "Numero", "Nascimento"],
  "sample_rows": [
    {
      "Nome Completo": "Ana Costa",
      "Numero": "2026-0001",
      "Nascimento": "2012-03-15"
    }
  ],
  "catalogs": {
    "ageGroups": [{ "id": "uuid", "nome": "Cadetes" }],
    "monthlyFees": [{ "id": "uuid", "designacao": "Mensal Atleta" }],
    "costCenters": [{ "id": "uuid", "nome": "Natacao" }]
  },
  "field_groups": {
    "pessoal": ["nome_completo", "numero_socio", "data_nascimento"],
    "financeiro": ["tipo_mensalidade", "centro_custo"],
    "desportivo": ["escalao", "ativo_desportivo"],
    "configuracao": ["email_utilizador", "perfil"]
  }
}
```

### `POST /membros/import/store`

Objetivo:

- validar linhas normalizadas
- criar membros validos
- devolver resumo final

Request JSON:

```json
{
  "upload_token": "tmp_01HS...",
  "mapping": {
    "nome_completo": "Nome Completo",
    "numero_socio": "Numero",
    "data_nascimento": "Nascimento",
    "sexo": "Sexo",
    "estado": "Estado",
    "tipo_membro": "Tipo",
    "tipo_mensalidade": "Mensalidade",
    "centro_custo": "Centro Custo",
    "escalao": "Escalao",
    "email_utilizador": "Email Login",
    "perfil": "Perfil"
  },
  "options": {
    "import_only_valid_rows": true,
    "skip_duplicates_by_email": false,
    "skip_duplicates_by_member_number": false
  }
}
```

Response sugerida:

```json
{
  "created_count": 12,
  "skipped_count": 3,
  "error_count": 2,
  "created_ids": ["uuid-1", "uuid-2"],
  "errors": [
    {
      "row": 7,
      "field": "email_utilizador",
      "message": "Email ja existe no sistema"
    }
  ],
  "warnings": [
    {
      "row": 5,
      "field": "numero_socio",
      "message": "Numero de socio gerado automaticamente"
    }
  ]
}
```

## Normalizacao Requerida No Backend

### Strings

- trim
- converter string vazia em `null`

### Booleanos

Aceitar:

- `1`, `0`
- `true`, `false`
- `sim`, `nao`
- `yes`, `no`

### Datas

Aceitar prioritariamente:

- `YYYY-MM-DD`
- fallback opcional para `DD/MM/YYYY`

### `tipo_membro`

Aceitar valores separados por `|`.

Exemplo:

- `atleta|socio`
- `encarregado_educacao|socio`

Normalizar para array de slugs internos.

### `escalao`

Resolver por nome visivel em `age_groups.nome`.

Armazenar em:

- `escalao` como array com um elemento
- `escalao_id` apenas como valor auxiliar durante a normalizacao

### `tipo_mensalidade`

Resolver por `monthly_fees.designacao`.

### `centro_custo`

Resolver por `cost_centers.nome`.

Armazenar em:

- `centro_custo` como array com o id
- pivot `centro_custo_user` com peso `1`

### `menor`

Se vier vazio e `data_nascimento` existir:

- calcular automaticamente pela idade

## Regras De Criacao

Para cada linha valida:

1. validar dados normalizados
2. se `numero_socio` vier vazio, usar geracao automatica
3. sincronizar identidade de autenticacao como hoje ja acontece no controller
4. criar `User`
5. se existir `tipo_mensalidade` ou `conta_corrente_manual`, persistir `DadosFinanceiros`
6. se existir `centro_custo`, sincronizar pivot
7. limpar cache relevante de membros

## Validacao Reutilizavel

Recomendacao:

- extrair a normalizacao comum do `store()` atual de `MembrosController` para um service reutilizavel
- evitar duplicar regras entre criacao individual e importacao

## Riscos Conhecidos

- valores historicos do template antigo usam `M/F` e o formulario atual usa `masculino/feminino`
- template antigo usa `Ativo/Inativo` em `tipo_membro`, mas o sistema atual usa slugs como `atleta`, `socio`, `treinador`
- imports de ficheiros/documentos nao sao compatíveis com o fluxo de upload base64 atual
- relacoes `encarregado_educacao` e `educandos` exigem lookup por membro existente e sincronizacao reciproca

## Ordem Recomendada De Implementacao

1. descarregar template V2
2. upload + leitura de cabecalhos
3. UI de mapeamento
4. preview com validacao
5. importacao final
6. relatorio de erros
7. so depois considerar ficheiros e relacoes familiares