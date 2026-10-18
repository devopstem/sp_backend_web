# Деплой (публикация)

Инструкция для выкладки из GitHub на VPS / хостинг с PHP-FPM + Nginx (или аналог).

## Что НЕ выкладывать из git

- `.env` — создать на сервере
- Firebase JSON — скопировать отдельно в `storage/app/private/...`
- `node_modules/`, `vendor/` — ставить на сервере
- Локальные логи и кэш

## Быстрый старт скриптами

На **новом** Ubuntu 22.04/24.04 VPS (репозиторий уже склонирован):

```bash
cd /var/www/sp_backend_web
sudo DOMAIN=your-domain.tld WITH_SSL=1 ./scripts/install.sh
```

Обновление с GitHub:

```bash
cd /var/www/sp_backend_web
sudo ./scripts/deploy.sh
```

Опции: `./scripts/install.sh --help`, `./scripts/deploy.sh --help`.

Скрипты ставят Nginx, PHP 8.4, PostgreSQL, Redis, Supervisor (queue + reverb), cron `schedule:run`.  
**Не** кладут Firebase JSON и не заменяют ручную проверку `.env`/DNS.

---

## Чеклист на сервере (вручную)

1. **Клон репозитория**

```bash
git clone <repo> /var/www/sp_backend_web
cd /var/www/sp_backend_web
```

2. **Окружение**

```bash
cp .env.example .env
# заполнить APP_*, DB_*, Redis, Reverb, mail, Firebase
php artisan key:generate
```

Рекомендуемые значения продакшена:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.tld

DB_CONNECTION=pgsql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SECURE_HEADERS_HSTS=true

# Reverb через Nginx на 443 (не 8080 в браузере):
REVERB_HOST=your-domain.tld
REVERB_PORT=443
REVERB_SCHEME=https
# PHP публикует события напрямую в Reverb (не через публичный HTTPS):
REVERB_INTERNAL_HOST=127.0.0.1
REVERB_INTERNAL_PORT=8080
REVERB_INTERNAL_SCHEME=http
```

3. **Зависимости и сборка**

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --force   # только при первой установке / осознанно
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

4. **Права**

Пользователь PHP-FPM должен писать в `storage/` и `bootstrap/cache/`.

5. **Веб-сервер**

Document root → `public/`. Пример Nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.tld;
    root /var/www/sp_backend_web/public;

    index index.php;
    client_max_body_size 64M;

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types text/plain text/css application/json application/javascript
               application/xml image/svg+xml font/woff2;

    location ^~ /build/ {
        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Laravel Reverb (WebSocket) — клиент подключается к wss://domain/app/...
    location /app {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header SERVER_NAME $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_pass http://127.0.0.1:8080;
    }
}
```

Шаблон для `install.sh`: `scripts/templates/nginx-site.conf.tpl` (gzip + кэш `/build/`).

На **уже работающем** VPS добавь блоки `gzip` и `location ^~ /build/` в свой site-config, затем:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

6. **Фоновые процессы**

- `queue:work` (Supervisor)
- `reverb:start` (если нужны чат/уведомления realtime)
- scheduler: cron `* * * * * php /var/www/sp_backend_web/artisan schedule:run`

7. **Firebase**

Скопировать service account на сервер, выставить `FIREBASE_*` или загрузить через UI (Super Admin / Программист). См. [FIREBASE.md](FIREBASE.md).

## Обновление с GitHub

Предпочтительно:

```bash
sudo ./scripts/deploy.sh
```

Или вручную:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
# перезапуск queue/reverb workers
```

## После деплоя

- [ ] HTTPS и `SESSION_SECURE_COOKIE=true`
- [ ] `APP_DEBUG=false`
- [ ] Бэкапы PostgreSQL
- [ ] Логин Super Admin работает
- [ ] Экспорт в Firebase (если используется)
- [ ] Права файлов credentials `600`

## Архитектура на проде (минимум)

```text
Internet → Nginx/TLS → PHP-FPM (Laravel) → PostgreSQL
                     ↘ Redis
                     ↘ Reverb (WS)
                     ↘ Firebase RTDB (egress)
```
