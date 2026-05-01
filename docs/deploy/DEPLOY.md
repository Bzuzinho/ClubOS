# Guia de Deploy para Produção

Este documento descreve o processo completo de deployment da aplicação BSCN Club Management em ambiente de produção.

## Pré-requisitos

### Servidor
- Ubuntu 22.04 LTS ou superior (recomendado)
- Mínimo 2GB RAM
- 20GB de espaço em disco
- Acesso root/sudo

### Software Necessário
- PHP 8.3 ou superior
- PostgreSQL 15 ou superior
- Nginx ou Apache
- Node.js 18 ou superior
- Composer
- Git
- Supervisor (para queue workers)
- Redis (opcional, para cache e sessions)

## 1. Preparação do Servidor

### 1.1 Atualizar Sistema
```bash
sudo apt update && sudo apt upgrade -y
```

### 1.2 Instalar PHP 8.3
```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-common \
    php8.3-mysql php8.3-pgsql php8.3-xml php8.3-curl php8.3-mbstring \
    php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl php8.3-redis
```

### 1.3 Instalar PostgreSQL
```bash
sudo apt install -y postgresql postgresql-contrib
sudo systemctl start postgresql
sudo systemctl enable postgresql
```

### 1.4 Instalar Nginx
```bash
sudo apt install -y nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

### 1.5 Instalar Node.js
```bash
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs
```

### 1.6 Instalar Composer
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

### 1.7 Instalar Redis (opcional)
```bash
sudo apt install -y redis-server
sudo systemctl start redis
sudo systemctl enable redis
```

## 2. Configuração da Base de Dados

### 2.1 Criar Base de Dados
```bash
sudo -u postgres psql
```

```sql
CREATE DATABASE bscn_club;
CREATE USER bscn_user WITH ENCRYPTED PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE bscn_club TO bscn_user;
\q
```

### 2.2 Configurar PostgreSQL para Aceitar Conexões
Editar `/etc/postgresql/15/main/pg_hba.conf`:
```
# IPv4 local connections:
host    bscn_club    bscn_user    127.0.0.1/32    md5
```

Reiniciar PostgreSQL:
```bash
sudo systemctl restart postgresql
```

## 3. Deploy da Aplicação

### 3.1 Criar Diretório da Aplicação
```bash
sudo mkdir -p /var/www/bscn-club
sudo chown -R $USER:www-data /var/www/bscn-club
```

### 3.2 Clonar Repositório
```bash
cd /var/www/bscn-club
git clone https://github.com/Bzuzinho/spark-original-ui.git .
```

### 3.3 Instalar Dependências
```bash
# PHP dependencies
composer install --no-dev --optimize-autoloader

# Node dependencies
npm ci --production
```

### 3.4 Configurar Variáveis de Ambiente
```bash
cp .env.example .env
nano .env
```

Configurar variáveis críticas:
```env
APP_NAME="BSCN Club Management"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bscn_club
DB_USERNAME=bscn_user
DB_PASSWORD=your_secure_password

# Cache & Session
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

Para sincronizar estas variáveis para a VM usando o deploy automatizado:

```bash
SYNC_MAIL_ENV=1 \
MAIL_MAILER=smtp \
MAIL_HOST=smtp.seuprovider.com \
MAIL_PORT=587 \
MAIL_USERNAME=utilizador_smtp \
MAIL_PASSWORD='password_smtp' \
MAIL_ENCRYPTION=tls \
MAIL_FROM_ADDRESS=noreply@seudominio.com \
MAIL_FROM_NAME='ClubOS' \
APP_URL='https://www.bscn.pt' \
npm run deploy:vm
```

### 3.5 Gerar Application Key
```bash
php artisan key:generate
```

### 3.6 Executar Migrações
```bash
php artisan migrate --force
```

### 3.7 Seed Inicial (apenas primeira vez)
```bash
php artisan db:seed --force
```

### 3.8 Otimizações
```bash
# Otimizar autoloader
composer dump-autoload --optimize

# Cache de configurações
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Build frontend
npm run build

# Link storage
php artisan storage:link
```

### 3.9 Permissões
```bash
sudo chown -R www-data:www-data /var/www/bscn-club/storage
sudo chown -R www-data:www-data /var/www/bscn-club/bootstrap/cache
sudo chmod -R 775 /var/www/bscn-club/storage
sudo chmod -R 775 /var/www/bscn-club/bootstrap/cache
```

## 4. Configuração do Nginx

### 4.1 Criar Configuração do Site
```bash
sudo nano /etc/nginx/sites-available/bscn-club
```

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/bscn-club/public;

    # SSL Certificates
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    
    # SSL Configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript 
               application/x-javascript application/xml+rss 
               application/javascript application/json;

    # Client body size (upload limit)
    client_max_body_size 20M;
}
```

### 4.2 Ativar Site
```bash
sudo ln -s /etc/nginx/sites-available/bscn-club /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

## 5. SSL/HTTPS com Let's Encrypt

### 5.1 Instalar Certbot
```bash
sudo apt install -y certbot python3-certbot-nginx
```

### 5.2 Obter Certificado
```bash
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### 5.3 Auto-renovação
```bash
sudo certbot renew --dry-run
```

O Certbot configura automaticamente um cron job para renovação.

## 6. Queue Workers com Supervisor

### 6.1 Instalar Supervisor
```bash
sudo apt install -y supervisor
```

### 6.2 Configurar Worker
```bash
sudo nano /etc/supervisor/conf.d/bscn-worker.conf
```

```ini
[program:bscn-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/bscn-club/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/bscn-club/storage/logs/worker.log
stopwaitsecs=3600
```

### 6.3 Iniciar Workers
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start bscn-worker:*
```

## 7. Configurar Cron Jobs

### 7.1 Editar Crontab
```bash
sudo crontab -e -u www-data
```

Adicionar:
```cron
* * * * * cd /var/www/bscn-club && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Monitorização e Logs

### 8.1 Logs da Aplicação
```bash
tail -f /var/www/bscn-club/storage/logs/laravel.log
```

### 8.2 Logs do Nginx
```bash
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log
```

### 8.3 Logs dos Workers
```bash
tail -f /var/www/bscn-club/storage/logs/worker.log
```

## 9. Backup Strategy

### 9.1 Script de Backup da Base de Dados
```bash
sudo nano /usr/local/bin/backup-bscn-db.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/bscn-club"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Backup database
pg_dump -U bscn_user -h localhost bscn_club | gzip > $BACKUP_DIR/db_backup_$DATE.sql.gz

# Keep only last 30 days
find $BACKUP_DIR -name "db_backup_*.sql.gz" -mtime +30 -delete

echo "Backup completed: db_backup_$DATE.sql.gz"
```

```bash
sudo chmod +x /usr/local/bin/backup-bscn-db.sh
```

### 9.2 Agendar Backup Diário
```bash
sudo crontab -e
```

```cron
0 2 * * * /usr/local/bin/backup-bscn-db.sh
```

### 9.3 Backup de Ficheiros
```bash
sudo nano /usr/local/bin/backup-bscn-files.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/bscn-club"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Backup storage files
tar -czf $BACKUP_DIR/storage_backup_$DATE.tar.gz /var/www/bscn-club/storage

# Keep only last 30 days
find $BACKUP_DIR -name "storage_backup_*.tar.gz" -mtime +30 -delete

echo "Storage backup completed: storage_backup_$DATE.tar.gz"
```

```bash
sudo chmod +x /usr/local/bin/backup-bscn-files.sh
```

## 10. Atualizações

### 10.1 Script de Deploy
```bash
nano /var/www/bscn-club/deploy.sh
```

```bash
#!/bin/bash
set -e

echo "🚀 Starting deployment..."

# Put application in maintenance mode
php artisan down

# Pull latest changes
git pull origin main

# Install/update dependencies
composer install --no-dev --optimize-autoloader
npm ci --production

# Run migrations
php artisan migrate --force

# Clear and rebuild cache
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Build assets
npm run build

# Restart queue workers
sudo supervisorctl restart bscn-worker:*

# Bring application back online
php artisan up

echo "✅ Deployment completed successfully!"
```

```bash
chmod +x /var/www/bscn-club/deploy.sh
```

### 10.2 Executar Deploy
```bash
cd /var/www/bscn-club
./deploy.sh
```

## 11. Segurança

### 11.1 Firewall
```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 11.2 Fail2Ban
```bash
sudo apt install -y fail2ban
sudo systemctl start fail2ban
sudo systemctl enable fail2ban
```

### 11.3 Permissões Adequadas
```bash
# Código deve ser readonly para www-data
sudo find /var/www/bscn-club -type f -exec chmod 644 {} \;
sudo find /var/www/bscn-club -type d -exec chmod 755 {} \;

# Exceções para storage e cache
sudo chmod -R 775 /var/www/bscn-club/storage
sudo chmod -R 775 /var/www/bscn-club/bootstrap/cache
```

## 12. Verificação do Deploy

### 12.1 Checklist
- [ ] Site acessível via HTTPS
- [ ] Certificado SSL válido
- [ ] Login funcional
- [ ] Dashboard carrega corretamente
- [ ] Todas as páginas acessíveis
- [ ] Queue workers ativos
- [ ] Cron jobs configurados
- [ ] Backups funcionando
- [ ] Logs sem erros críticos

### 12.2 Testes de Smoke
```bash
# Verificar aplicação
curl -I https://yourdomain.com

# Verificar workers
sudo supervisorctl status

# Verificar logs
tail -n 50 /var/www/bscn-club/storage/logs/laravel.log
```

## 13. Troubleshooting

### Erro 500
```bash
# Verificar logs
tail -f /var/www/bscn-club/storage/logs/laravel.log
tail -f /var/log/nginx/error.log

# Verificar permissões
sudo chown -R www-data:www-data /var/www/bscn-club/storage
sudo chmod -R 775 /var/www/bscn-club/storage
```

### Queue não processa jobs
```bash
# Verificar workers
sudo supervisorctl status bscn-worker:*

# Reiniciar workers
sudo supervisorctl restart bscn-worker:*

# Verificar logs
tail -f /var/www/bscn-club/storage/logs/worker.log
```

### Performance lenta
```bash
# Verificar cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Otimizar database
sudo -u postgres psql bscn_club -c "VACUUM ANALYZE;"
```

## 14. Recursos Adicionais

### Monitorização (Opcional)
- **Laravel Telescope**: Para debugging em staging
- **Sentry**: Para tracking de erros
- **New Relic / Datadog**: Para APM

### CI/CD (Opcional)
- GitHub Actions
- GitLab CI
- Jenkins

## Suporte

Para questões sobre deployment:
- Email: devops@bscn.com
- Documentação: https://docs.bscn.com/deploy
- GitHub Issues: https://github.com/Bzuzinho/spark-original-ui/issues

---

**Última atualização**: Fevereiro 2026
