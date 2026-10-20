# Локальная установка

## Требования

- PHP 8.3+ с расширениями Laravel (pgsql/sqlite, redis, mbstring, openssl, …)
- Composer 2
- Node.js 20+ / npm
- PostgreSQL 16 (рекомендуется) или SQLite для быстрого старта
- Redis 7 (рекомендуется для кэша/очередей/сессий в проде)
- Опционально: Firebase project для экспорта

## Шаги

```bash
git clone <your-repo-url> sp_backend_web
cd sp_backend_web

cp .env.example .env
composer install
php artisan key:generate
```

### База данных

**Вариант A — PostgreSQL (как в продакшене):**

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sp_backend_db
DB_USERNAME=...
DB_PASSWORD=...
```

**Вариант B — SQLite (быстрый старт, как в `.env.example`):**

```env
DB_CONNECTION=sqlite
# файл database/database.sqlite создастся при migrate
```

```bash
touch database/database.sqlite   # если ещё нет
php artisan migrate --seed
```

Сидер создаёт роли и Super Admin из переменных:

```env
SUPER_ADMIN_NAME=
SUPER_ADMIN_EMAIL=
SUPER_ADMIN_PASSWORD=
```

На production после `php artisan config:cache` сидер читает их через `config('services.super_admin.*')`.
Если админ не создаётся — сначала `php artisan config:clear`, затем снова `config:cache` (или seed до кэша).

### Frontend

```bash
npm install
npm run dev      # разработка (Vite HMR)
# или
npm run build    # production assets
```

### Запуск

```bash
php artisan serve
# при необходимости отдельно:
php artisan reverb:start
php artisan queue:work
```

Откройте `APP_URL` (по умолчанию `http://localhost:8000`).

## Полезные переменные

См. `.env.example`. Критичные:

| Переменная | Зачем |
|------------|--------|
| `APP_KEY` | Шифрование / сессии |
| `APP_URL` | URL приложения |
| `DB_*` | База |
| `REDIS_*` / `CACHE_STORE` | Кэш |
| `BROADCAST_CONNECTION` + `REVERB_*` | Realtime |
| `FIREBASE_*` | Fallback интеграций |
| `SESSION_SECURE_COOKIE` | `false` на чистом HTTP локально |

Для локального HTTP часто нужно:

```env
SESSION_SECURE_COOKIE=false
APP_DEBUG=true
```

## Права на storage

```bash
php artisan storage:link
chmod -R ug+rwx storage bootstrap/cache
```

## Проверка

1. Логин Super Admin
2. Создать tenant / магазин в UI
3. Товары → Product Bot (габариты) при необходимости
4. Настройки → Интеграции (если нужен Firebase)
5. Экспорт → тест выгрузки

Дальше: [DEPLOYMENT.md](DEPLOYMENT.md), [FIREBASE.md](FIREBASE.md).
