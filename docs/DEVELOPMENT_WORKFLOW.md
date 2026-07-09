# Fluxo de desenvolvimento do ClubOS

## Princípio

O repositório GitHub é a fonte única de verdade. O desenvolvimento diário deve ser feito numa cópia local do repositório, preferencialmente no Windows com Codex, sem programar directamente na `main`.

## Preparação inicial no Windows

```bash
git clone https://github.com/Bzuzinho/ClubOS.git
cd ClubOS
composer install
npm ci
copy .env.example .env
php artisan key:generate
```

Configurar o `.env` local com uma base de dados de desenvolvimento separada da produção.

## Iniciar uma tarefa

Actualizar a `main` local:

```bash
git checkout main
git pull origin main
```

Criar uma branch:

```bash
git checkout -b feature/nome-da-funcionalidade
```

Usar:

- `feature/*` para novas funcionalidades;
- `fix/*` para correcções;
- `refactor/*` para reorganização interna sem alteração funcional intencional.

## Trabalhar com Codex

Dar ao Codex um âmbito limitado e verificável. Exemplo:

```text
Analisa a implementação actual do registo de presenças.
Altera apenas o módulo Desportivo e os testes directamente relacionados.
Mantém a arquitectura Laravel 11 + Inertia React existente.
Corre os testes Laravel relacionados e o build frontend no final.
Não faças commit nem push até reveres o diff.
```

Antes de publicar alterações:

```bash
git status
git diff
php artisan test
npm run build
```

## Publicar a branch

Adicionar apenas os ficheiros da tarefa:

```bash
git add <ficheiros>
git commit -m "feat: descrição curta"
git push -u origin feature/nome-da-funcionalidade
```

Abrir Pull Request para `main`.

## Pull Request

O GitHub Actions executa automaticamente:

1. instalação das dependências PHP;
2. testes Laravel com SQLite em memória, conforme `phpunit.xml`;
3. instalação das dependências frontend;
4. build Vite.

Uma branch não deve ser integrada na `main` enquanto esta validação estiver a falhar.

## Produção

Depois do merge para `main`, o mesmo workflow volta a validar a aplicação. Apenas depois de a validação passar é executado o deploy para a Oracle VM.

O deploy de produção continua a reutilizar `bin/deploy-vm.sh` durante a primeira fase de automatização.

## Regra de segurança da base de dados

O `.env` local não deve apontar para a mesma base de dados utilizada pela VM de produção. O script de deploy mantém o guardrail existente contra a partilha acidental do mesmo alvo de base de dados.
