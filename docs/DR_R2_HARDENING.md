# Hardening Cloudflare R2 — ClubOS

Este runbook fecha a dívida H1 de least privilege e retenção imutável do Disaster Recovery já ativado em produção.

## Objetivo

O fluxo normal de backup deve usar apenas uma credencial S3 de data plane com permissão `Object Read & Write`, aplicada a `specific buckets only` e limitada exclusivamente ao bucket privado de backup ClubOS.

O token histórico `Admin Read & Write / All buckets` foi necessário durante a primeira ativação, mas não é adequado para operação normal e deve ser revogado depois de a nova credencial restrita ser validada.

Bucket Lock é configuração de control plane. Não deve obrigar a guardar uma credencial Cloudflare administrativa na Oracle VM nem no processo normal de backup.

## 1. Credencial S3 de data plane

Na Cloudflare R2 criar uma nova credencial com:

- permission: `Object Read & Write`;
- scope: `specific buckets only`;
- bucket: apenas o bucket usado por `CLUBOS_DR_R2_BUCKET`;
- nunca `Admin Read & Write`;
- nunca `All buckets`.

Guardar o novo Access Key ID e Secret Access Key nos repository secrets:

```text
CLUBOS_DR_R2_ACCESS_KEY_ID
CLUBOS_DR_R2_SECRET_ACCESS_KEY
```

Não gravar estes valores em commits, logs, issues, documentação ou artefactos.

## 2. Validação obrigatória da rotação

Depois de atualizar os secrets, executar manualmente `.github/workflows/dr-r2-activate.yml`.

Antes de qualquer backup, o workflow executa `scripts/ops/dr/probe-r2-access.sh` na Oracle VM. O probe usa um objeto efémero sob `${DR_REMOTE_BASE}/.access-probe/` e só passa se confirmar:

```text
list_access=ok
write_access=ok
read_access=ok
delete_access=ok
delete_verification=ok
r2_access_probe=ok
```

Depois do probe, o mesmo workflow tem de concluir:

1. backup off-site cifrado;
2. releitura/verificação SHA256 do objeto remoto;
3. restore real para PostgreSQL 17 temporário;
4. instalação/confirmação dos crons;
5. health check DR estrito.

Só depois deste ciclo totalmente verde deve ser revogado o token antigo `Admin Read & Write / All buckets`.

## 3. Bucket Lock

Os objetos de produção vivem sob o prefixo lógico `clubos-prod`. As regras têm portanto de usar os prefixos completos abaixo; regras apenas em `daily/`, `weekly/` ou `monthly/` não cobrem os objetos reais.

Regras mínimas:

| Regra | Prefixo | Condição | Retenção mínima |
|---|---|---|---:|
| `clubos-daily-7d` | `clubos-prod/daily/` | Age | 604800 segundos (7 dias) |
| `clubos-weekly-28d` | `clubos-prod/weekly/` | Age | 2419200 segundos (28 dias) |
| `clubos-monthly-370d` | `clubos-prod/monthly/` | Age | 31968000 segundos (370 dias) |

Se `DR_R2_PREFIX` for alterado em produção, substituir `clubos-prod` nas três regras pelo valor produtivo real antes de ativar Bucket Lock.

Não usar lock `Indefinite`: a política ClubOS prevê retenção finita 7 diários / 4 semanais / 12 mensais e limpeza automática. Um lock ligeiramente mais longo do que o instante em que a retenção tenta apagar um objeto é seguro: `backup-offsite.sh` trata a impossibilidade temporária de apagar como warning e volta a tentar em execuções posteriores.

Bucket Lock aplica-se também a objetos já existentes e prevalece sobre regras de lifecycle. Enquanto existirem regras de Bucket Lock, o bucket não pode ser esvaziado sem primeiro remover as regras.

## 4. Separação data plane / control plane

A credencial S3 `Object Read & Write` é apenas para operações de objetos via API S3-compatible. Não deve ser promovida a Admin para permitir configurar Bucket Lock.

Configurar/verificar Bucket Lock pelo dashboard Cloudflare ou com uma credencial de control plane separada e mínima para configuração R2. Essa credencial não deve ser copiada para `/etc/clubos-dr`, não deve ser usada por `rclone` e não deve permanecer como requisito do workflow diário de backup.

## 5. Sequência de fecho H1

A H1 de R2 só pode ser marcada concluída quando existir evidência dos seguintes pontos:

- [ ] nova credencial `Object Read & Write` limitada exclusivamente ao bucket;
- [ ] repository secrets atualizados;
- [ ] probe `list/write/read/delete/delete_verification` verde com a nova credencial;
- [ ] backup cifrado real verde;
- [ ] restore PostgreSQL 17 real verde;
- [ ] health DR estrito verde;
- [ ] token antigo `Admin Read & Write / All buckets` revogado;
- [ ] Bucket Lock `clubos-prod/daily/` >= 7 dias confirmado;
- [ ] Bucket Lock `clubos-prod/weekly/` >= 28 dias confirmado;
- [ ] Bucket Lock `clubos-prod/monthly/` >= 370 dias confirmado;
- [ ] `docs/ESTADO_VIVO_DESENVOLVIMENTO.md` atualizado com a evidência final.

Até essa evidência externa existir, o hardening de código pode estar merged mas a H1 R2 permanece operacionalmente pendente.
