# Deploy Workflow — Codespace → VM Oracle (ClubManager)

## Comando único

```bash
npm run deploy:vm
```

Executa `bin/deploy-vm.sh` que realiza todo o processo de forma automatizada e idempotente.

---

## O que o comando faz (etapas)

| Passo | Descrição |
|-------|-----------|
| **[0/6]** | Valida que estás na branch `main` e que não há alterações por commitar |
| **[1/6]** | Corre `npm ci` + `npm run build` no Codespace (nunca na VM) |
| **[2/6]** | `git push origin main` |
| **[3/6]** | Configura SSH: gera chave ed25519, atualiza `known_hosts`, cria alias `clubmanager-vm` no `~/.ssh/config`, instala chave pública na VM |
| **[4/6]** | Deploy backend na VM: `git pull`, `composer install`, `migrate`, `config/route/view:cache`, reload `php8.3-fpm`; cria scripts remotos se não existirem |
| **[5/6]** | Copia `public/build/` do Codespace para `/var/www/clubmanager/public/` na VM via `scp` |
| **[6/6]** | Reload nginx na VM + healthcheck via `curl`, usando o host configurado em `APP_URL` |

---

## Pré-requisitos

### No Codespace
- Node 20 (definido em `.devcontainer/devcontainer.json`, já presente no repositório)
- Acesso à internet para atingir `129.159.13.211:22`

### Na VM (Oracle Ubuntu 22.04)
- PHP 8.3-fpm + Nginx instalados e a correr
- Laravel 11 em `/var/www/clubmanager`
- Utilizador `ubuntu` com `sudo` sem password (ou com `NOPASSWD` para os comandos necessários)
- Porta 22 acessível a partir do Codespace

### Permissões sudo na VM (mínimas recomendadas)
Adiciona em `/etc/sudoers.d/ubuntu-deploy`:
```
ubuntu ALL=(ALL) NOPASSWD: /usr/bin/tee /usr/local/bin/clubmanager-*.sh, \
  /bin/chmod +x /usr/local/bin/clubmanager-*.sh, \
  /bin/systemctl reload php8.3-fpm, \
  /bin/systemctl reload nginx, \
  /usr/sbin/service php8.3-fpm reload, \
  /usr/sbin/service nginx reload
```

---

## Variáveis de ambiente (opcionais)

Podes sobrepor os defaults antes de correr:

```bash
VM_USER=ubuntu \
VM_HOST=129.159.13.211 \
VM_APP_DIR=/var/www/clubmanager \
SSH_KEY=~/.ssh/id_ed25519 \
npm run deploy:vm
```

---

## Scripts remotos na VM

O deploy cria automaticamente em `/usr/local/bin/` (se não existirem):

| Script | Função |
|--------|--------|
| `clubmanager-deploy-backend.sh` | `git pull`, `composer install`, `migrate`, caches, reload php-fpm |
| `clubmanager-frontend-reload.sh` | `systemctl reload nginx` |
| `clubmanager-healthcheck.sh` | `curl -Is http://127.0.0.1/` com `Host` derivado de `APP_URL`, e verifica 200/30x |

---

## Troubleshooting

### `Permission denied (publickey)` ao fazer SSH

O script tenta instalar a chave automaticamente. Se falhar, instala manualmente na VM:

```bash
# Na VM (ou via outro acesso)
echo '<YOUR_PUBLIC_KEY_CONTENT>' >> /home/ubuntu/.ssh/authorized_keys
chmod 700 /home/ubuntu/.ssh
chmod 600 /home/ubuntu/.ssh/authorized_keys
chown -R ubuntu:ubuntu /home/ubuntu/.ssh
```

Replace `<YOUR_PUBLIC_KEY_CONTENT>` with the output of `cat ~/.ssh/id_ed25519.pub` in the Codespace.

### `Host key verification failed`

O script corre `ssh-keyscan -H 129.159.13.211` automaticamente. Se persistir:
```bash
ssh-keyscan -H 129.159.13.211 >> ~/.ssh/known_hosts
```

### Build falhou

O deploy aborta antes de tocar na VM. Corrige o erro de build e tenta novamente.

### Ver logs de erro na VM

```bash
# Laravel
ssh clubmanager-vm "tail -n 100 /var/www/clubmanager/storage/logs/laravel.log"

# Nginx
ssh clubmanager-vm "tail -n 50 /var/log/nginx/error.log"
```

### Forçar recriação do alias SSH

Apaga a entrada do `~/.ssh/config` e corre novamente:
```bash
# Remove bloco do alias
sed -i '/Host clubmanager-vm/,/^$/d' ~/.ssh/config
npm run deploy:vm
```

---

## Critério de sucesso

```
✅ Deploy completo OK.
🌍 Abre: http://129.159.13.211
```

O healthcheck confirma que `curl -I http://127.0.0.1/` com o `Host` da aplicação retorna `200` ou `30x` na VM.
