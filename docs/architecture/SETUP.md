# Setup Guide

## Development Setup

### Option 1: With Docker (Recommended)

1. **Start services:**
   ```bash
   docker-compose up -d
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Run migrations:**
   ```bash
   php artisan migrate:fresh
   ```

5. **Start development:**
   ```bash
   npm run dev
   php artisan serve
   ```

### Option 2: Local Development (File-based)

1. **Configure environment:**
   ```bash
   cp .env.local.example .env
   php artisan key:generate
   ```

2. **Use SQLite + File cache:**
   ```env
   DB_CONNECTION=sqlite
   CACHE_STORE=file
   SESSION_DRIVER=file
   QUEUE_CONNECTION=sync
   ```

3. **Run migrations:**
   ```bash
   touch database/database.sqlite
   php artisan migrate:fresh
   ```

## Production Setup

### Requirements
- PostgreSQL 16+
- Redis 7+
- PHP 8.3+
- Composer 2+
- Node.js 20+

### Environment Configuration

```env
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=pgsql
DB_HOST=your-postgres-host
DB_DATABASE=your-database
DB_USERNAME=your-user
DB_PASSWORD=your-password

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
```

### Deployment Steps

1. Install dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm install && npm run build
   ```

2. Optimize Laravel:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. Run migrations:
   ```bash
   php artisan migrate --force
   ```

4. Clear/warm caches:
   ```bash
   php artisan cache:clear
   php artisan cache:warmup
   ```
