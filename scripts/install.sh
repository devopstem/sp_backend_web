#!/usr/bin/env bash
#
# Первичная установка Smart Planogram на чистый Ubuntu 22.04 / 24.04 VPS.
#
# Пример:
#   sudo DOMAIN=smart-planogramma.by ./scripts/install.sh
#   sudo DOMAIN=example.com APP_DIR=/var/www/sp_backend_web WITH_SSL=1 ./scripts/install.sh
#
# Не делает автоматически:
#   - git clone (репозиторий уже должен лежать в APP_DIR)
#   - Firebase credentials
#   - финальное заполнение всех секретов .env (создаёт шаблон + DB/Redis/Reverb basics)
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

DOMAIN="${DOMAIN:-}"
APP_DIR="${APP_DIR:-${SP_ROOT}}"
WEB_USER="${WEB_USER:-www-data}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_MAJOR="${NODE_MAJOR:-20}"
WITH_SSL="${WITH_SSL:-0}"
WITH_SEED="${WITH_SEED:-0}"
SKIP_APT="${SKIP_APT:-0}"
SKIP_DB="${SKIP_DB:-0}"
DB_NAME="${DB_NAME:-sp_backend}"
DB_USER="${DB_USER:-sp_app}"
DB_PASSWORD="${DB_PASSWORD:-}"
EMAIL="${EMAIL:-admin@${DOMAIN:-localhost}}"

usage() {
  cat <<'EOF'
Usage: sudo DOMAIN=your-domain.tld ./scripts/install.sh

Env / flags:
  DOMAIN          обязателен (серверное имя Nginx)
  APP_DIR         путь к проекту (по умолчанию корень репозитория)
  WITH_SSL=1      получить сертификат Let's Encrypt (certbot)
  WITH_SEED=1     php artisan db:seed --force
  SKIP_APT=1      не ставить пакеты (уже установлены)
  SKIP_DB=1       не создавать роль/БД PostgreSQL
  DB_NAME         имя БД (default: sp_backend)
  DB_USER         пользователь БД (default: sp_app)
  DB_PASSWORD     пароль БД (если пусто — сгенерируется)
  WEB_USER        пользователь PHP-FPM (default: www-data)
  PHP_VERSION     8.4 (default)
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

need_root "$@"
detect_ubuntu

[[ -n "${DOMAIN}" ]] || die "Укажи DOMAIN=your-domain.tld"
[[ -d "${APP_DIR}" ]] || die "Каталог приложения не найден: ${APP_DIR}"
[[ -f "${APP_DIR}/artisan" ]] || die "Не похоже на Laravel-проект (нет artisan) в ${APP_DIR}"

log "Установка Smart Planogram → ${DOMAIN}"
ok "APP_DIR=${APP_DIR}"

# ---------------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------------
if [[ "${SKIP_APT}" != "1" ]]; then
  log "apt: базовые пакеты + PHP ${PHP_VERSION} + Nginx + PostgreSQL + Redis"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y \
    ca-certificates curl gnupg lsb-release software-properties-common \
    unzip zip git build-essential \
    nginx redis-server supervisor \
    postgresql postgresql-contrib \
    certbot python3-certbot-nginx

  if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
  fi

  apt-get install -y \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-pgsql" \
    "php${PHP_VERSION}-redis" \
    "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-tokenizer" \
    "php${PHP_VERSION}-readline"

  # Node.js LTS
  if ! command -v node >/dev/null 2>&1; then
    log "Установка Node.js ${NODE_MAJOR}.x"
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt-get install -y nodejs
  else
    ok "Node уже установлен: $(node -v)"
  fi

  # Composer
  if ! command -v composer >/dev/null 2>&1; then
    log "Установка Composer"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  else
    ok "Composer уже установлен: $(composer -V 2>/dev/null | head -1)"
  fi

  systemctl enable --now nginx redis-server postgresql supervisor "php${PHP_VERSION}-fpm"
  ok "Сервисы включены"
else
  warn "SKIP_APT=1 — пакеты не трогаем"
fi

ensure_php_cli
PHP_BIN="$(command -v php)"
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "${PHP_SOCK}" ]]; then
  # fallback to whatever exists
  PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
  [[ -n "${PHP_SOCK}" ]] || die "Не найден PHP-FPM socket в /run/php/"
fi
ok "PHP-FPM socket: ${PHP_SOCK}"

# ---------------------------------------------------------------------------
# PostgreSQL
# ---------------------------------------------------------------------------
if [[ "${SKIP_DB}" != "1" ]]; then
  log "PostgreSQL: роль и база"
  if [[ -z "${DB_PASSWORD}" ]]; then
    DB_PASSWORD="$(rand_secret)"
    warn "Сгенерирован DB_PASSWORD (сохрани): ${DB_PASSWORD}"
  fi

  # Escape single quotes for SQL string literals: ' → ''
  DB_PASSWORD_SQL="${DB_PASSWORD//\'/\'\'}"

  sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
    CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASSWORD_SQL}';
  ELSE
    ALTER ROLE ${DB_USER} WITH PASSWORD '${DB_PASSWORD_SQL}';
  END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec

GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
SQL
  ok "БД ${DB_NAME} / пользователь ${DB_USER}"
else
  warn "SKIP_DB=1 — PostgreSQL не создаём"
fi

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------
log "Конфигурация .env"
ENV_FILE="${APP_DIR}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
  cp "${APP_DIR}/.env.example" "${ENV_FILE}"
  ok "Скопирован .env.example → .env"
else
  warn ".env уже есть — обновим ключевые ключи, остальное не затрём"
fi

set_env_kv "${ENV_FILE}" "APP_NAME" "Smart Planogram"
set_env_kv "${ENV_FILE}" "APP_ENV" "production"
set_env_kv "${ENV_FILE}" "APP_DEBUG" "false"
set_env_kv "${ENV_FILE}" "APP_URL" "https://${DOMAIN}"

set_env_kv "${ENV_FILE}" "DB_CONNECTION" "pgsql"
set_env_kv "${ENV_FILE}" "DB_HOST" "127.0.0.1"
set_env_kv "${ENV_FILE}" "DB_PORT" "5432"
set_env_kv "${ENV_FILE}" "DB_DATABASE" "${DB_NAME}"
set_env_kv "${ENV_FILE}" "DB_USERNAME" "${DB_USER}"
if [[ -n "${DB_PASSWORD}" ]]; then
  set_env_kv "${ENV_FILE}" "DB_PASSWORD" "${DB_PASSWORD}"
fi

set_env_kv "${ENV_FILE}" "SESSION_DRIVER" "redis"
set_env_kv "${ENV_FILE}" "SESSION_SECURE_COOKIE" "true"
set_env_kv "${ENV_FILE}" "CACHE_STORE" "redis"
set_env_kv "${ENV_FILE}" "QUEUE_CONNECTION" "redis"
set_env_kv "${ENV_FILE}" "BROADCAST_CONNECTION" "reverb"
set_env_kv "${ENV_FILE}" "SECURE_HEADERS_HSTS" "true"

set_env_kv "${ENV_FILE}" "REDIS_HOST" "127.0.0.1"
set_env_kv "${ENV_FILE}" "REDIS_PORT" "6379"

set_env_kv "${ENV_FILE}" "REVERB_HOST" "${DOMAIN}"
set_env_kv "${ENV_FILE}" "REVERB_PORT" "443"
set_env_kv "${ENV_FILE}" "REVERB_SCHEME" "https"
set_env_kv "${ENV_FILE}" "REVERB_SERVER_HOST" "0.0.0.0"
set_env_kv "${ENV_FILE}" "REVERB_SERVER_PORT" "8080"

if ! grep -qE '^REVERB_APP_ID=.+' "${ENV_FILE}"; then
  set_env_kv "${ENV_FILE}" "REVERB_APP_ID" "$(rand_secret | head -c 12)"
fi
if ! grep -qE '^REVERB_APP_KEY=.+' "${ENV_FILE}"; then
  set_env_kv "${ENV_FILE}" "REVERB_APP_KEY" "$(rand_secret)"
fi
if ! grep -qE '^REVERB_APP_SECRET=.+' "${ENV_FILE}"; then
  set_env_kv "${ENV_FILE}" "REVERB_APP_SECRET" "$(rand_secret)"
fi

set_env_kv "${ENV_FILE}" "VITE_REVERB_APP_KEY" '"${REVERB_APP_KEY}"'
set_env_kv "${ENV_FILE}" "VITE_REVERB_HOST" '"${REVERB_HOST}"'
set_env_kv "${ENV_FILE}" "VITE_REVERB_PORT" '"${REVERB_PORT}"'
set_env_kv "${ENV_FILE}" "VITE_REVERB_SCHEME" '"${REVERB_SCHEME}"'

set_env_kv "${ENV_FILE}" "SANCTUM_STATEFUL_DOMAINS" "${DOMAIN},www.${DOMAIN}"

(
  cd "${APP_DIR}"
  if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
  else
    ok "APP_KEY уже задан"
  fi
)

# ---------------------------------------------------------------------------
# App build
# ---------------------------------------------------------------------------
log "Composer + npm build + migrate"
app_permissions "${APP_DIR}" "${WEB_USER}"
composer_install_prod "${APP_DIR}"
frontend_build "${APP_DIR}"
artisan_optimize "${APP_DIR}"

if [[ "${WITH_SEED}" == "1" ]]; then
  log "db:seed"
  (cd "${APP_DIR}" && php artisan db:seed --force)
fi

# ---------------------------------------------------------------------------
# Nginx
# ---------------------------------------------------------------------------
log "Nginx site"
SITE_AVAILABLE="/etc/nginx/sites-available/sp-backend"
SITE_ENABLED="/etc/nginx/sites-enabled/sp-backend"
sed \
  -e "s|__DOMAIN__|${DOMAIN}|g" \
  -e "s|__APP_DIR__|${APP_DIR}|g" \
  -e "s|__PHP_SOCK__|${PHP_SOCK}|g" \
  "${SCRIPT_DIR}/templates/nginx-site.conf.tpl" > "${SITE_AVAILABLE}"

ln -sfn "${SITE_AVAILABLE}" "${SITE_ENABLED}"
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
ok "Nginx: http://${DOMAIN}"

if [[ "${WITH_SSL}" == "1" ]]; then
  log "Certbot Let's Encrypt"
  certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${EMAIL}" --redirect || \
    warn "Certbot не удался — настрой SSL вручную, DNS должен указывать на этот сервер"
fi

# ---------------------------------------------------------------------------
# Supervisor + cron
# ---------------------------------------------------------------------------
log "Supervisor (queue + reverb)"
SUPERVISOR_CONF="/etc/supervisor/conf.d/sp-backend.conf"
sed \
  -e "s|__APP_DIR__|${APP_DIR}|g" \
  -e "s|__PHP_BIN__|${PHP_BIN}|g" \
  -e "s|__WEB_USER__|${WEB_USER}|g" \
  "${SCRIPT_DIR}/templates/supervisor-sp.conf.tpl" > "${SUPERVISOR_CONF}"

supervisorctl reread
supervisorctl update
supervisorctl start sp-queue:* 2>/dev/null || supervisorctl restart sp-queue:* || true
supervisorctl start sp-reverb:* 2>/dev/null || supervisorctl restart sp-reverb:* || true

CRON_LINE="* * * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1"
if ! grep -qF "${APP_DIR}/artisan schedule:run" /etc/crontab 2>/dev/null; then
  echo "${CRON_LINE}" >> /etc/crontab
  ok "Cron schedule:run добавлен"
else
  ok "Cron schedule:run уже есть"
fi

app_permissions "${APP_DIR}" "${WEB_USER}"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
cat <<EOF

$(ok "Установка завершена")

Дальше вручную:
  1. Проверь .env (почта, Firebase, APP_URL)
  2. Загрузи Firebase credentials через UI или в storage/app/private/...
  3. Если WITH_SSL=0 — настрой HTTPS и поставь SESSION_SECURE_COOKIE=true
  4. Создай Super Admin (seeder или вручную)
  5. Обновления:  sudo ./scripts/deploy.sh

Полезное:
  APP_DIR=${APP_DIR}
  DOMAIN=${DOMAIN}
  DB=${DB_NAME} / ${DB_USER}
  supervisorctl status
  tail -f ${APP_DIR}/storage/logs/laravel.log

EOF
