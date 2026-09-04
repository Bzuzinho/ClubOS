# Deploy Workflow — ClubOS → Oracle VM

## 1. Fonte de verdade

O deploy produtivo canónico é executado pelo GitHub Actions quando existe `push` em `main` e os jobs de validação terminam com sucesso.

Entrada canónica:

```bash
npm run deploy:vm
```

Fluxo:

```txt
GitHub Actions
  → bin/deploy-vm.mjs
  → build frontend no runner
  → upload temporário do build + script remoto
  → bin/remote-deploy-backend.sh na VM
  → release isolada
  → preflight + migrations
  → healthcheck pre-cutover
  → current troca atomicamente
  → reload PHP-FPM
  → healthcheck real via Nginx/PHP /up
  → auditorias pós-deploy
```

Não usar `git pull`, `git reset --hard`, `composer install` ou substituição manual de `public/build` diretamente no path produtivo como mecanismo normal de deployment.

---

## 2. Layout produtivo atómico

Para preservar os paths já usados por Nginx, cron e scripts de backup, `/var/www/clubmanager` mantém-se como path compatível, mas passa a ser um symlink.

```txt
/var/www/clubmanager
  -> /var/www/clubmanager.deploy/current

/var/www/clubmanager.deploy/
├── repository.git/          # mirror Git usado apenas para construir releases
├── releases/
│   ├── 20260821123000-<sha>/
│   └── ...
├── shared/
│   ├── .env
│   └── storage/
├── current -> releases/<release-atual>
├── previous -> releases/<release-anterior> ou legacy inicial
├── legacy/                  # working tree anterior ao primeiro cutover
└── legacy-persistence/      # cópia de segurança do .env/storage no primeiro cutover
```

Cada release contém código e `vendor` próprios e recebe o `public/build` produzido pelo runner. A release não contém working tree Git.

Estado persistente:

- `.env` → `shared/.env`;
- `storage` → `shared/storage`;
- `public/storage` → `shared/storage/app/public`.

`bootstrap/cache` e `public/build` são específicos de cada release.

---

## 3. Primeiro cutover

A primeira execução da H0.1b converte o layout antigo automaticamente.

Antes da troca:

1. valida que `/var/www/clubmanager` ainda é uma working tree Git limpa;
2. cria o mirror Git e o estado `shared`;
3. constrói a nova release fora do path servido pelo Nginx;
4. instala dependências Composer;
5. valida Nginx e configuração PHP;
6. executa preflight de migrations;
7. aplica migrations;
8. executa `/up` na release isolada através de um servidor PHP local.

Só depois existe uma breve maintenance window para sincronizar a última escrita de `storage`. O path `/var/www/clubmanager` é trocado de diretório para symlink com `renameat2(RENAME_EXCHANGE)`, evitando uma janela em que o path fique inexistente.

Nginx não precisa de mudar de root: continua a usar:

```txt
/var/www/clubmanager/public
```

Os crons também continuam a usar `/var/www/clubmanager`.

---

## 4. Deploys seguintes

Nos deploys normais não existe working tree produtiva a alterar.

O processo é:

1. `repository.git` faz fetch de `origin/main`;
2. é exigido que o SHA remoto seja exatamente o SHA que a CI está a fazer deploy;
3. é criada uma release nova em `releases/`;
4. `.env` e `storage` são ligados ao estado partilhado;
5. o build frontend do runner é colocado na release;
6. `composer install --no-dev` corre na release isolada;
7. migrations e sincronizações necessárias correm antes do cutover;
8. a release passa o healthcheck interno `/up`;
9. `previous` guarda a release anterior;
10. `current` é substituído por rename atómico de symlink;
11. PHP-FPM é recarregado para invalidar OPcache;
12. `/up` é testado através do Nginx e PHP-FPM reais;
13. apenas após sucesso são limpas releases antigas.

Retenção padrão: 5 releases, preservando sempre os targets de `current` e `previous`.

---

## 5. Healthchecks

Existem dois níveis.

### Pre-cutover

A release ainda não está exposta pelo Nginx. O deploy inicia temporariamente o servidor PHP apenas em `127.0.0.1` e exige:

```txt
GET /up → HTTP 200
```

### Pós-cutover

`/usr/local/bin/clubmanager-healthcheck.sh` usa `APP_URL` e, quando a produção é HTTPS, faz resolução local do hostname para `127.0.0.1:443`. Para um hostname canónico sem `www`, valida também automaticamente o alias `www`; aliases adicionais ou alternativos podem ser definidos em `HEALTHCHECK_ALIAS_HOSTS`, separados por vírgulas ou espaços.

Isto testa efetivamente:

```txt
Nginx → TLS → public/index.php → PHP-FPM → Laravel → /up
```

O hostname canónico tem de devolver `HTTP 200` em `/` (website público), `/login` (entrada ClubOS) e `/up` (saúde Laravel). Cada alias tem de devolver exatamente `HTTP 301` para o mesmo caminho no hostname canónico, sem seguir o redirect. Desta forma, um website público substituído por um redirect para login, um certificado sem o alias, um virtual host incorreto ou um redirect de `www` para si próprio fazem falhar o deploy.

Para o BSCN, o contrato Nginx de referência está versionado em `docs/deploy/nginx-bscn-canonical.conf`: `bscn.pt` é canónico e `www.bscn.pt` redireciona sempre para `https://bscn.pt$request_uri`.

---

## 6. Rollback

### Automático

Se ocorrer uma falha depois de `current` mudar — caches, reload PHP-FPM ou healthcheck — o deploy tenta automaticamente repor a release anterior, recarrega PHP-FPM e volta a validar `/up`.

O job continua vermelho para tornar a falha visível, mas a aplicação deve regressar à release anterior quando o rollback for tecnicamente possível.

### Manual

Na VM:

```bash
sudo /usr/local/bin/clubmanager-rollback-release.sh \
  /var/www/clubmanager \
  www-data \
  www-data
```

O rollback manual troca `current` e `previous`, reconstrói caches, reinicia o sinal das queues, recarrega PHP-FPM e exige healthcheck verde. Se o rollback falhar, o script tenta restaurar a release que estava ativa antes da operação.

### Limite importante: migrations

Rollback de release **não executa rollback automático de migrations**.

Regra de produção:

```txt
migrations usadas num deploy atómico têm de ser backward-compatible
```

Usar padrão expand/contract para alterações destrutivas:

1. adicionar estrutura compatível;
2. migrar/backfill;
3. cortar leituras/escritas antigas;
4. remover estrutura antiga apenas numa release posterior.

---

## 7. Segurança SSH

O deployment exige explicitamente:

- `ORACLE_VM_USER`;
- `ORACLE_VM_HOST`;
- `ORACLE_VM_APP_DIR`;
- `ORACLE_VM_SSH_KEY`;
- `ORACLE_VM_KNOWN_HOSTS`.

Todas as chamadas `ssh`/`scp` do orquestrador usam:

```txt
StrictHostKeyChecking=yes
UserKnownHostsFile=~/.ssh/known_hosts
```

Não existe `ssh-keyscan` durante o deploy.

---

## 8. Verificação na VM

Estado atual:

```bash
readlink -f /var/www/clubmanager
readlink -f /var/www/clubmanager.deploy/current
readlink -f /var/www/clubmanager.deploy/previous
cat /var/www/clubmanager/.clubos-release
```

Releases disponíveis:

```bash
ls -lah /var/www/clubmanager.deploy/releases
```

Estado persistente:

```bash
ls -ld /var/www/clubmanager/.env /var/www/clubmanager/storage
readlink -f /var/www/clubmanager/.env
readlink -f /var/www/clubmanager/storage
```

Healthcheck:

```bash
sudo /usr/local/bin/clubmanager-healthcheck.sh /var/www/clubmanager
```

---

## 9. Scripts antigos

`bin/deploy-vm.sh` mantém-se apenas como wrapper de compatibilidade e encaminha para `npm run deploy:vm`.

O antigo `/usr/local/bin/clubmanager-deploy-backend.sh`, que alterava diretamente a working tree produtiva, é desativado durante a primeira instalação do layout atómico.

---

## 10. Critério de sucesso

Um deployment só é considerado concluído quando:

- CI e PostgreSQL concurrency estão verdes;
- release isolada foi construída para o SHA esperado;
- migrations concluíram;
- healthcheck pre-cutover ficou verde;
- `current` aponta para a release esperada;
- PHP-FPM foi recarregado;
- healthcheck Nginx/PHP `/up` ficou verde;
- auditorias pós-deploy não têm findings críticos.
