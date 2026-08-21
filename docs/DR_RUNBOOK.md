# Disaster Recovery — ClubOS

## Estado e objetivo

Este runbook define o contrato operacional H0.2 do ClubOS para proteger PostgreSQL 17, uploads persistentes e configuração crítica fora do domínio de falha da Oracle VM.

Objetivos operacionais iniciais:

- RPO: **<= 24 horas**;
- RTO: **<= 2 horas**;
- backup local: 1 dump PostgreSQL 17 por dia, 7 dias retidos;
- off-site: arquivo diário cifrado com tiers 7 diários / 4 semanais / 12 mensais;
- restore test off-site: semanal;
- monitor DR: diário via GitHub Actions e local via cron depois da ativação.

O código da aplicação não é incluído no arquivo DR porque a fonte canónica é o GitHub. O arquivo off-site contém:

- último dump PostgreSQL validado;
- checksum SHA256 do dump;
- `.env` produtivo;
- `storage/app/public`;
- manifesto sem credenciais, com timestamp, release SHA e métricas de tamanho.

O arquivo é cifrado **antes do upload** com GPG AES-256.

## Evidência produtiva de 2026-08-21

O diagnóstico H0.2 confirmou:

- cron local ativo às 02:15 UTC;
- 7 dumps locais consecutivos;
- último dump: `clubmanager-prod-20260821-021501.dump`;
- tamanho do último dump: ~1,56 MB;
- checksum do último dump: OK;
- `storage` partilhado do layout atómico: ~30,5 MB;
- disco da VM: ~35 GB livres no momento da auditoria;
- dump criado por PostgreSQL 17;
- `pg_restore` default do sistema era PostgreSQL 14 e não deve ser usado para validar/restaurar dumps PG17;
- binários PostgreSQL 17 existem em `/usr/lib/postgresql/17/bin`;
- restore smoke test real com PostgreSQL 17 concluído em 8 segundos;
- restore smoke: 208 tabelas públicas e 214 migrations;
- pipeline off-site validado E2E na Oracle VM no commit `325b97c4e69b4c6432f26d373e807cd2d32be9c9`, com remote efémero: cifragem, upload, download, decifragem, restore temporário e health estrito concluídos;
- restore E2E: 208 tabelas, 214 migrations, 14 segundos;
- a configuração R2 real continua por ativar.

## Backend off-site recomendado

A implementação usa `rclone` com backend S3-compatible. A configuração fornecida por `configure-r2.sh` usa Cloudflare R2 por omissão, mas o restante pipeline depende apenas do remote `r2:` exposto pelo `rclone`.

Estrutura remota:

```text
<bucket>/clubos-prod/
  daily/YYYY/MM/DD/clubos-prod-YYYYMMDDTHHMMSSZ.tar.gz.gpg
  weekly/YYYY/WNN/clubos-prod-YYYYMMDDTHHMMSSZ.tar.gz.gpg
  monthly/YYYY/MM/clubos-prod-YYYYMMDDTHHMMSSZ.tar.gz.gpg
```

Retenção lógica:

- `daily`: 7 arquivos mais recentes;
- `weekly`: 4 arquivos mais recentes;
- `monthly`: 12 arquivos mais recentes.

Se o provider recusar uma eliminação por causa de Object/Bucket Lock, o backup **não falha**: a retenção fica temporariamente mais longa, nunca mais curta.

### Bucket Lock recomendado no R2

Configurar no bucket regras por prefixo:

- `clubos-prod/daily/`: pelo menos 7 dias;
- `clubos-prod/weekly/`: pelo menos 28 dias;
- `clubos-prod/monthly/`: pelo menos 370 dias.

Isto protege os objetos contra eliminação/overwrite prematuros. Como o lock pode impedir o cleanup exatamente no primeiro instante em que um objeto fica excedentário, é aceitável existir temporariamente um snapshot extra.

## Secrets GitHub necessários para ativar o off-site

Criar os seguintes repository secrets:

```text
CLUBOS_DR_R2_ENDPOINT
CLUBOS_DR_R2_BUCKET
CLUBOS_DR_R2_ACCESS_KEY_ID
CLUBOS_DR_R2_SECRET_ACCESS_KEY
CLUBOS_DR_BACKUP_PASSPHRASE
```

Exemplo de endpoint R2:

```text
https://<ACCOUNT_ID>.r2.cloudflarestorage.com
```

A passphrase deve ser longa, aleatória e guardada também fora da VM num gestor de passwords seguro. Sem esta passphrase, os arquivos `.gpg` não são recuperáveis.

Nunca gravar estes valores no repositório, issues, PRs ou logs.

## Ativação controlada via GitHub Actions

O caminho canónico de ativação é o workflow manual `.github/workflows/dr-activate-r2.yml` (`H0.2 Activate R2 Offsite`). Não executar enquanto o bucket, locks e cinco secrets anteriores não estiverem configurados.

O workflow exige duas confirmações explícitas:

1. escrever `ACTIVATE-R2`;
2. confirmar que os Bucket Locks 7d/28d/370d estão ativos nos prefixos `clubos-prod/daily/`, `clubos-prod/weekly/` e `clubos-prod/monthly/`.

Garantias do workflow:

- usa SSH com `known_hosts` pinned e `StrictHostKeyChecking=yes`;
- exige que o SHA ativo na Oracle VM coincida com o SHA de `main` que está a executar o workflow;
- não imprime os secrets; transfere-os por SSH num payload temporário `0600`, codificado apenas para transporte e removido depois da leitura;
- executa `configure-r2.sh` na VM;
- executa o **primeiro backup off-site real antes de ativar o cron**;
- executa o **primeiro restore test real antes de ativar o cron**;
- só depois de backup + restore verdes executa `install-dr-cron.sh`, que cria o marker de ativação;
- termina com `check-dr-health.sh` em modo estrito e exige os markers de sucesso de backup e restore.

A ordem é intencional: uma falha no primeiro backup ou restore não deve deixar `/var/lib/clubos-dr/enabled` ativo nem instalar um agendamento que já nasceu degradado.

## Configuração manual na VM — fallback operacional

O workflow anterior é o caminho recomendado. Em recuperação operacional excecional, o bootstrap equivalente recebe os secrets por canal seguro e executa:

```bash
sudo env \
  DR_R2_ENDPOINT='...' \
  DR_R2_BUCKET='...' \
  DR_R2_ACCESS_KEY_ID='...' \
  DR_R2_SECRET_ACCESS_KEY='...' \
  DR_BACKUP_PASSPHRASE='...' \
  bash /var/www/clubmanager/scripts/ops/dr/configure-r2.sh
```

Depois, para preservar a mesma ordem segura do workflow:

```bash
sudo bash /var/www/clubmanager/scripts/ops/dr/backup-offsite.sh
sudo bash /var/www/clubmanager/scripts/ops/dr/restore-test-offsite.sh
sudo bash /var/www/clubmanager/scripts/ops/dr/install-dr-cron.sh
sudo bash /var/www/clubmanager/scripts/ops/dr/check-dr-health.sh
```

`install-dr-cron.sh` instala `rclone` via APT se necessário e ativa:

```text
02:15 UTC  backup PostgreSQL local já existente
02:35 UTC  backup DR off-site
04:15 UTC  domingo: restore test off-site
06:30 UTC  health check local
07:00 UTC  GitHub Actions: DR Health Monitor
```

## Verificação do backup local

O backup local passa a usar explicitamente:

```text
/usr/lib/postgresql/17/bin/pg_dump
/usr/lib/postgresql/17/bin/pg_restore
```

Um dump só é considerado válido depois de:

1. existir e não estar vazio;
2. ter checksum SHA256 válido;
3. `pg_restore --list` com PostgreSQL 17 terminar com sucesso.

O cron mantém 7 dumps locais.

## Conteúdo e cifragem do arquivo off-site

O staging do arquivo contém:

```text
manifest.txt
database/database.dump
database/database.dump.sha256
application/.env
application/storage-public/
```

O staging é compactado com `tar.gz` e depois cifrado:

```text
GPG symmetric AES-256
```

Só o `.tar.gz.gpg` é enviado para o provider.

Após o upload, o script volta a ler o objeto remoto com `rclone cat` e compara SHA256 local/remoto. Um upload sem esta verificação não é marcado como sucesso.

## Restore test semanal

`restore-test-offsite.sh`:

1. escolhe o arquivo diário mais recente no off-site;
2. descarrega-o para `/tmp`;
3. decifra-o;
4. extrai-o;
5. valida `database.dump.sha256`;
6. valida `pg_restore --list` com PostgreSQL 17;
7. cria uma BD temporária `clubos_dr_restore_*`;
8. restaura integralmente o dump;
9. confirma existência de tabelas públicas e migrations;
10. elimina a BD temporária e todos os ficheiros temporários;
11. grava `/var/lib/clubos-dr/last-restore-test-success`.

Nunca restaura por cima da BD produtiva durante o teste.

O hotfix H0.2.3 garante que o utilizador `postgres` consegue atravessar o workspace temporário sem o tornar público: diretório pai `root:postgres 0710` e dump temporário `postgres:postgres 0600`.

## Health check e alertas

`check-dr-health.sh` valida:

- dump local <= 26 horas;
- checksum e catálogo PostgreSQL 17 válidos;
- se DR estiver ativado: último off-site <= 30 horas;
- se DR estiver ativado: último restore test <= 8 dias;
- existência de pelo menos um arquivo diário remoto.

Antes da ativação do off-site, o script termina com sucesso mas reporta:

```text
offsite_status=not_enabled
```

Depois de `install-dr-cron.sh`, o marker `/var/lib/clubos-dr/enabled` torna o health check estrito.

`.github/workflows/dr-health-monitor.yml` executa o mesmo health check diariamente via SSH pinned. Uma falha fica visível como GitHub Actions vermelho e usa o mecanismo normal de notificações de Actions do repositório.

## Recuperação real após perda total da VM

Sequência resumida:

1. provisionar nova VM Ubuntu compatível;
2. instalar PostgreSQL 17, Nginx, PHP 8.3, Redis, Node/Composer conforme documentação produtiva;
3. clonar `Bzuzinho/ClubOS` e aplicar `main`;
4. configurar acesso ao bucket off-site e passphrase GPG;
5. descarregar o último arquivo apropriado (normalmente `daily`, ou `weekly/monthly` se necessário);
6. decifrar/extrair;
7. restaurar `.env` e `storage/app/public`;
8. criar a BD alvo vazia;
9. restaurar `database.dump` com PostgreSQL 17;
10. executar healthcheck Laravel/Nginx;
11. validar Access Control e auditorias operacionais;
12. trocar DNS/IP apenas depois da validação.

O objetivo RTO de 2 horas inclui provisionamento e validação. O restore puro da BD medido em 2026-08-21 demorou 8 segundos; no E2E off-site completo o restore mediu 14 segundos. O principal componente do RTO é reconstrução do runtime e validação, não o volume atual de dados.

## Regras de segurança

- não usar `pg_restore` 14 nos dumps PG17;
- não guardar a passphrase GPG no Git;
- não imprimir credenciais R2 em logs;
- usar token R2 `Object Read & Write` limitado ao bucket de backup;
- manter o bucket privado;
- ativar Bucket Lock por prefixo;
- não reutilizar a passphrase da aplicação, SSH ou base de dados;
- um restore test nunca pode apontar para o nome da BD de produção;
- alterações destrutivas à política de retenção exigem revisão explícita.
