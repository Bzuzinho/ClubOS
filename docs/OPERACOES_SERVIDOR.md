# Operações de Servidor — ClubOS

Este documento define os comandos padrão para aplicar alterações do ClubOS no servidor.

Deve ser consultado sempre que um desenvolvimento inclua:

- migrations;
- alterações em modelos/tabelas;
- alterações de configuração;
- alterações no frontend React/Vite;
- novas dependências Composer ou NPM;
- alterações em permissões, caches, rotas ou policies;
- alterações que precisem de refletir no servidor depois do push para `main`.

---

## 1. Regra obrigatória

Sempre que uma sprint ou correção exigir aplicação no servidor, o resumo final deve incluir uma secção:

```txt
Aplicação no servidor
```

Essa secção deve indicar explicitamente:

- se é necessário `git pull`;
- se é necessário `composer install`;
- se é necessário `npm ci` / `npm run build`;
- se é necessário `php artisan migrate --force`;
- se é necessário limpar ou reconstruir cache;
- se é necessário reiniciar filas/workers;
- se há risco de impacto na base de dados.

---

## 2. Comandos padrão para aplicar alterações no servidor

Entrar no servidor por SSH:

```bash
ssh utilizador@IP_OU_DOMINIO_DO_SERVIDOR
```

Ir para a pasta da aplicação:

```bash
cd /var/www/clubmanager
```

Confirmar estado antes de mexer:

```bash
git status
git log --oneline -5
```

Atualizar código:

```bash
git pull origin main
```

Instalar/atualizar dependências PHP, quando necessário:

```bash
composer install --no-dev --optimize-autoloader
```

Instalar/compilar frontend, quando necessário:

```bash
npm ci
npm run build
```

Se o build falhar por falta de memória no Node/Vite, usar a alternativa da secção 3.1.

Aplicar migrations em produção:

```bash
php artisan migrate --force
```

Limpar caches antigas:

```bash
php artisan optimize:clear
```

Recriar caches, quando adequado:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Se existirem filas/workers:

```bash
php artisan queue:restart
```

---

## 3. Comando rápido para alterações com migration

Usar quando o código já está em `main` e a alteração inclui migration, como criação de tabela.

```bash
ssh utilizador@IP_OU_DOMINIO_DO_SERVIDOR
cd /var/www/clubmanager
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Se o servidor não compila frontend e recebe `public/build` já gerado por outro processo, não executar `npm ci` / `npm run build` sem confirmar a estratégia de deploy.

---

## 3.1. Build frontend em servidor com pouca memória

Em servidores pequenos, o `npm run build` pode falhar com:

```txt
FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory
```

Primeira tentativa:

```bash
NODE_OPTIONS=--max-old-space-size=2048 npm run build
```

Se voltar a falhar, criar swap temporária antes do build:

```bash
sudo fallocate -l 2G /swapfile-build
sudo chmod 600 /swapfile-build
sudo mkswap /swapfile-build
sudo swapon /swapfile-build
NODE_OPTIONS=--max-old-space-size=2048 npm run build
sudo swapoff /swapfile-build
sudo rm /swapfile-build
```

Não correr `npm audit fix` diretamente no servidor de produção sem rever alterações em `package-lock.json` e sem validar o build/testes.

---

## 4. Caso específico: Sprint F1.1

A Sprint F1.1 criou a tabela:

```txt
payment_methods
```

Migration:

```txt
database/migrations/2026_05_20_140000_create_payment_methods_table.php
```

Para aplicar no servidor:

```bash
ssh utilizador@IP_OU_DOMINIO_DO_SERVIDOR
cd /var/www/clubmanager
git pull origin main
php artisan migrate --force
php artisan optimize:clear
```

Se o servidor também compila assets frontend:

```bash
npm ci
NODE_OPTIONS=--max-old-space-size=2048 npm run build
```

Depois validar:

```bash
php artisan tinker
```

Dentro do tinker:

```php
\App\Models\PaymentMethod::all(['codigo', 'nome', 'requer_linha_bancaria', 'ativo'])->toArray();
```

Devem existir pelo menos:

- `transferencia`, com `requer_linha_bancaria = true`;
- `dinheiro`, com `requer_linha_bancaria = false`;
- `multibanco`, com `requer_linha_bancaria = false`;
- `tpa`, com `requer_linha_bancaria = false`;
- `cheque`, com `requer_linha_bancaria = false`.

---

## 5. Cuidados importantes

Nunca correr migrations em produção sem saber que branch está aplicado.

Antes de `php artisan migrate --force`, confirmar:

```bash
git branch --show-current
git log --oneline -3
```

A branch deve ser `main` ou a branch de produção acordada.

Se houver alterações locais no servidor, não fazer `git pull` à força. Primeiro analisar:

```bash
git status
git diff --stat
```

Nunca usar `git reset --hard` em produção sem confirmação expressa.

---

## 6. Regra para futuras sprints

Todas as prompts de desenvolvimento devem pedir ao Copilot/IA:

```txt
Se esta alteração exigir aplicação no servidor, inclui no resumo final a secção Aplicação no servidor com os comandos exatos. Se criar migrations, indicar explicitamente php artisan migrate --force e php artisan optimize:clear.
```
