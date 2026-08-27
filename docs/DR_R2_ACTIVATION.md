# Ativação produtiva R2 — ClubOS

Este documento cobre a ativação final do backend off-site real da H0.2. O contrato completo de Disaster Recovery está em `docs/DR_RUNBOOK.md` e o hardening H1 de least privilege/Bucket Lock está em `docs/DR_R2_HARDENING.md`.

## Estado atual

A ativação produtiva foi concluída e validada em 2026-08-22.

Resultado final:

```text
list_access=ok
write_access=ok
delete_access=ok
dr_enabled=yes
local_integrity=ok
offsite_status=ok
restore_test_status=ok
r2_real_validation=ok
```

A aplicação manteve `/up=200` durante a validação.

## Configuração externa

O backend usa Cloudflare R2 com um bucket privado dedicado e acesso S3-compatible.

Recomendações de retenção/lock:

- `clubos-prod/daily/` — pelo menos 7 dias / 604800 segundos;
- `clubos-prod/weekly/` — pelo menos 28 dias / 2419200 segundos;
- `clubos-prod/monthly/` — pelo menos 370 dias / 31968000 segundos.

Os repository secrets usados pelo workflow são:

```text
CLUBOS_DR_R2_ENDPOINT
CLUBOS_DR_R2_BUCKET
CLUBOS_DR_R2_ACCESS_KEY_ID
CLUBOS_DR_R2_SECRET_ACCESS_KEY
CLUBOS_DR_BACKUP_PASSPHRASE
```

Continuam também obrigatórios os secrets Oracle VM já usados pelo deploy:

```text
ORACLE_VM_USER
ORACLE_VM_HOST
ORACLE_VM_APP_DIR
ORACLE_VM_KNOWN_HOSTS
ORACLE_VM_SSH_KEY
```

Nenhum destes valores deve ser colocado em commits, PRs, issues ou logs.

## Workflow de ativação

`.github/workflows/dr-r2-activate.yml` está separado do deploy normal.

Pode ser acionado por:

- `workflow_dispatch`; ou
- push controlado para `ops/h0-2-r2-activate`.

Não corre em PRs nem em pushes normais para `main`.

Sequência atual:

1. valida todos os secrets e configuração de produção;
2. configura SSH com host key pinned;
3. transfere bootstrap transitório privado;
4. executa `configure-r2.sh`;
5. remove o bootstrap transitório;
6. garante `rclone` na VM;
7. executa `probe-r2-access.sh` e exige `list/write/read/delete/delete_verification` verdes;
8. executa `backup-offsite.sh`;
9. relê/verifica o objeto remoto por SHA256;
10. executa `restore-test-offsite.sh` numa BD PostgreSQL 17 temporária;
11. só depois de probe+backup+restore passarem instala os crons e o marker através de `install-dr-cron.sh`;
12. executa `check-dr-health.sh` em modo estrito;
13. confirma markers e cron DR;
14. só termina verde depois de `dr_r2_activation=ok`.

Esta ordem impede que uma configuração R2 inválida marque o DR como ativo antes de existir prova real de acesso, backup e restore.

## Evidência real de 2026-08-22

A primeira tentativa atingiu o endpoint e listou o bucket, mas falhou no upload com:

```text
list_access=ok
write_access=failed
HTTP 403 AccessDenied
```

Depois de corrigida a permissão do token, o probe S3 confirmou leitura/listagem, escrita e eliminação:

```text
list_access=ok
write_access=ok
delete_access=ok
```

Primeiro objeto real criado e validado:

```text
clubos-prod-20260822T001929Z.tar.gz.gpg
```

Estado gravado:

```text
archive=clubos-prod-20260822T001929Z.tar.gz.gpg
objects=daily/2026/08/22/clubos-prod-20260822T001929Z.tar.gz.gpg
encrypted_bytes=2011560
```

O restore test descarregou e decifrou esse objeto R2 real e restaurou para uma BD PostgreSQL 17 temporária:

```text
restore_seconds=5
public_table_count=208
migration_count=214
storage_file_count=5
```

Health final:

```text
dr_enabled=yes
local_integrity=ok
offsite_status=ok
restore_test_status=ok
r2_real_validation=ok
```

H0.2 está concluída operacionalmente em produção.

## Hardening residual H1

O token usado na validação final de H0.2 continua documentado como `Admin Read & Write / All buckets`. Funciona, mas é mais amplo do que o necessário.

A preparação H1 introduz um probe permanente e um runbook específico em `docs/DR_R2_HARDENING.md`. O fecho operacional exige ainda, na conta Cloudflare:

1. criar uma credencial `Object Read & Write` limitada exclusivamente ao bucket de backup;
2. atualizar os repository secrets e repetir o workflow de ativação;
3. obter `list_access=ok`, `write_access=ok`, `read_access=ok`, `delete_access=ok` e `delete_verification=ok` com a nova credencial;
4. repetir backup real + restore PostgreSQL 17 + health estrito;
5. revogar o token antigo `Admin Read & Write / All buckets`;
6. configurar e confirmar Bucket Lock nos prefixos completos `clubos-prod/daily/`, `clubos-prod/weekly/` e `clubos-prod/monthly/`.

Até essa evidência externa existir, H1 R2 permanece pendente e não deve ser marcada concluída apenas por o código de preparação estar integrado.
