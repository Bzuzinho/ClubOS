# Ativação produtiva R2 — ClubOS

Este documento cobre apenas a ativação final do backend off-site real da H0.2. O contrato completo de Disaster Recovery está em `docs/DR_RUNBOOK.md`.

## Pré-requisitos externos

Antes de executar a ativação, criar no Cloudflare R2:

1. um bucket privado dedicado ao ClubOS;
2. Bucket Lock por prefixo, recomendado:
   - `clubos-prod/daily/` — pelo menos 7 dias;
   - `clubos-prod/weekly/` — pelo menos 28 dias;
   - `clubos-prod/monthly/` — pelo menos 370 dias;
3. um token S3 limitado ao bucket, com leitura/escrita/eliminação de objetos necessária à retenção;
4. uma passphrase longa e aleatória, guardada fora da VM num gestor de passwords.

O workflow não cria o bucket nem as regras de Bucket Lock. Isto é deliberado para não exigir um token Cloudflare de administração mais abrangente do que o necessário para backups.

## Repository secrets

Configurar em GitHub Actions:

```text
CLUBOS_DR_R2_ENDPOINT
CLUBOS_DR_R2_BUCKET
CLUBOS_DR_R2_ACCESS_KEY_ID
CLUBOS_DR_R2_SECRET_ACCESS_KEY
CLUBOS_DR_BACKUP_PASSPHRASE
```

Os secrets Oracle VM já usados pelo deploy continuam obrigatórios:

```text
ORACLE_VM_USER
ORACLE_VM_HOST
ORACLE_VM_APP_DIR
ORACLE_VM_KNOWN_HOSTS
ORACLE_VM_SSH_KEY
```

Nenhum destes valores deve ser colocado em commits, PRs, issues ou logs.

## Workflow de ativação

`.github/workflows/dr-r2-activate.yml` é um workflow operacional deliberadamente separado do deploy normal.

Pode ser acionado por:

- `workflow_dispatch`; ou
- um push para a branch dedicada `ops/h0-2-r2-activate`.

Não corre em PRs e não corre em pushes normais para `main`.

A branch de trigger não deve existir permanentemente sem necessidade. Depois de os secrets estarem configurados, pode ser criada a partir de `main` e receber um commit mínimo para iniciar a ativação. Após validação, pode ser removida.

## Sequência automática

O workflow executa, por esta ordem:

1. valida presença de todos os secrets e configuração de produção;
2. configura SSH com host key pinned;
3. transfere um ficheiro transitório privado com as credenciais de bootstrap;
4. executa `configure-r2.sh`;
5. remove o ficheiro transitório;
6. garante que `rclone` existe na VM;
7. executa o primeiro `backup-offsite.sh`;
8. relê/verifica o objeto remoto por SHA256;
9. executa `restore-test-offsite.sh` numa BD PostgreSQL 17 temporária;
10. só depois da prova backup+restore instala os crons e o marker de ativação através de `install-dr-cron.sh`;
11. executa `check-dr-health.sh` em modo estrito;
12. confirma os markers `enabled`, `last-offsite-success` e `last-restore-test-success`;
13. confirma os três blocos de cron DR;
14. só termina verde depois de `dr_r2_activation=ok`.

Esta ordem é intencional: uma configuração R2 inválida não deve marcar o DR como ativo nem instalar o agendamento permanente antes de um backup e restore reais terem passado.

A aplicação e o deploy normal são independentes deste workflow. Uma falha de ativação R2 não altera a release da aplicação nem faz rollback de código.

## Critério para fechar H0.2

H0.2 só pode ser marcado como concluído depois de uma execução real no R2 confirmar:

```text
local_integrity=ok
offsite_status=ok
restore_test_status=ok
dr_r2_activation=ok
```

Também deve existir pelo menos um objeto diário cifrado no bucket e o restore test deve ter restaurado com sucesso a BD temporária a partir desse objeto real.
