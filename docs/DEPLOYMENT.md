# Deploy de produção

O ClubOS mantém a infraestrutura actual de produção:

- GitHub como fonte de verdade do código;
- Oracle VM para a aplicação Laravel/Inertia;
- Neon PostgreSQL para a base de dados;
- `main` como branch de produção.

## Fluxo recomendado

1. Criar uma branch a partir de `main` (`feature/*`, `fix/*` ou `refactor/*`).
2. Desenvolver e testar localmente.
3. Fazer push da branch para GitHub.
4. Abrir um Pull Request para `main`.
5. Rever e validar as alterações.
6. Fazer merge do Pull Request.
7. O workflow `Deploy production` executa automaticamente o deploy da `main` para a Oracle VM.

Também é possível executar o workflow manualmente através de `workflow_dispatch` no GitHub Actions.

## GitHub Environment

Deve existir um environment GitHub com o nome:

`production`

Recomenda-se activar regras de protecção/revisão nesse environment quando houver mais pessoas a operar produção.

## Secrets necessários

Configurar em GitHub > Settings > Secrets and variables > Actions ou no environment `production`.

### Obrigatório

- `ORACLE_VM_SSH_KEY`: chave privada SSH já autorizada na Oracle VM.

### Recomendados

- `ORACLE_VM_HOST`: hostname ou IP da VM. Se não existir, o workflow mantém temporariamente o valor legado definido no pipeline actual.
- `ORACLE_VM_USER`: utilizador SSH. Default: `ubuntu`.
- `ORACLE_VM_APP_DIR`: directoria da aplicação. Default: `/var/www/clubmanager`.
- `PRODUCTION_APP_URL`: URL pública da aplicação.

## Comportamento do workflow

O workflow reutiliza `bin/deploy-vm.sh` em vez de introduzir um segundo mecanismo de deploy.

O script actual mantém as protecções já existentes, incluindo:

- deploy apenas da `main`;
- working tree limpo;
- validação entre o commit local e `origin/main`;
- build do frontend;
- guardrail contra utilização acidental da mesma base de dados entre desenvolvimento e produção;
- execução dos scripts remotos existentes;
- health check e apresentação de logs em caso de falha.

## Regra operacional

Não executar deploy de branches `feature/*`, `fix/*` ou `refactor/*` para produção.

O deploy automático ocorre apenas após a alteração chegar à `main`.

## Migração do desenvolvimento para Windows

Clone inicial:

```bash
git clone https://github.com/Bzuzinho/ClubOS.git
cd ClubOS
```

Antes de iniciar trabalho:

```bash
git checkout main
git pull origin main
git checkout -b feature/nome-da-funcionalidade
```

Depois das alterações e testes:

```bash
git add <ficheiros-do-ambito>
git commit -m "feat: descrição curta"
git push -u origin feature/nome-da-funcionalidade
```

A branch deve então seguir para Pull Request e merge em `main`.

## Fase seguinte

Após estabilizar o deploy automático, o passo seguinte recomendado é substituir gradualmente o script legado de deploy por um deploy remoto mais curto e idempotente. Essa alteração deve ser feita separadamente para reduzir risco operacional.
