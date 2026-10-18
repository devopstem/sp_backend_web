#!/usr/bin/env bash
# Shared helpers for Smart Planogram install/deploy scripts.
# shellcheck shell=bash

set -euo pipefail

SP_SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SP_ROOT="$(cd "${SP_SCRIPTS_DIR}/.." && pwd)"

log()  { printf '\n\033[1;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    die "Запусти от root: sudo $0 $*"
  fi
}

detect_ubuntu() {
  if [[ ! -f /etc/os-release ]]; then
    die "Не удалось определить ОС (/etc/os-release)."
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  if [[ "${ID:-}" != "ubuntu" ]]; then
    die "Скрипт рассчитан на Ubuntu 22.04/24.04. Сейчас: ${PRETTY_NAME:-unknown}"
  fi
  case "${VERSION_ID:-}" in
    22.04|24.04) ok "ОС: ${PRETTY_NAME}" ;;
    *)
      warn "Непроверенная версия Ubuntu (${VERSION_ID}). Продолжаем на свой риск."
      ;;
  esac
}

rand_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 32 | tr -d '/+=' | head -c 32
  else
    head -c 48 /dev/urandom | base64 | tr -d '/+=' | head -c 32
  fi
}

set_env_kv() {
  local file="$1" key="$2" value="$3"
  local escaped
  escaped="$(printf '%s' "${value}" | sed -e 's/[\\/&|]/\\&/g')"
  if grep -qE "^${key}=" "${file}"; then
    sed -i -E "s|^${key}=.*|${key}=${escaped}|" "${file}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${file}"
  fi
}

ensure_php_cli() {
  command -v php >/dev/null 2>&1 || die "php не найден в PATH"
  local ver
  ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  if [[ "${ver}" != "8.3" && "${ver}" != "8.4" ]]; then
    warn "Ожидался PHP 8.4+, сейчас ${ver}"
  else
    ok "PHP ${ver}"
  fi
}

app_permissions() {
  local app_dir="$1" web_user="${2:-www-data}"
  mkdir -p \
    "${app_dir}/storage/framework/"{cache,sessions,views} \
    "${app_dir}/storage/logs" \
    "${app_dir}/storage/app/private" \
    "${app_dir}/bootstrap/cache"
  chown -R "${web_user}:${web_user}" "${app_dir}/storage" "${app_dir}/bootstrap/cache"
  find "${app_dir}/storage" "${app_dir}/bootstrap/cache" -type d -exec chmod 775 {} \;
  find "${app_dir}/storage" "${app_dir}/bootstrap/cache" -type f -exec chmod 664 {} \; || true
  ok "Права storage/ и bootstrap/cache → ${web_user}"
}

composer_install_prod() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    if [[ -f composer.lock ]]; then
      composer install --no-dev --optimize-autoloader --no-interaction
    else
      composer install --no-dev --optimize-autoloader --no-interaction
    fi
  )
}

frontend_build() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    if [[ -f package-lock.json ]]; then
      npm ci
    else
      npm install
    fi
    npm run build
  )
}

artisan_optimize() {
  local app_dir="$1"
  (
    cd "${app_dir}"
    php artisan storage:link 2>/dev/null || true
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache 2>/dev/null || true
  )
}

reload_services() {
  systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php*-fpm 2>/dev/null || true
  systemctl reload nginx 2>/dev/null || true
  if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl reread >/dev/null 2>&1 || true
    supervisorctl update >/dev/null 2>&1 || true
    supervisorctl restart sp-queue:* 2>/dev/null || true
    supervisorctl restart sp-reverb:* 2>/dev/null || true
  fi
}
