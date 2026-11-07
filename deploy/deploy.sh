#!/usr/bin/env bash
# Выкладка souzpir в прод: fetch -> checkout -> install -> build -> migrate -> переключение симлинка.
# Без секретов внутри: все значения приходят из переменных окружения деплой-хоста.
#
# Обязательные переменные окружения:
#   DEPLOY_REPO   - URL git-репозитория
# Опциональные (с дефолтами):
#   DEPLOY_ROOT     - каталог releases/shared/current (/var/www/souzpir)
#   PHP_BIN         - путь к php (php)
#   KEEP_RELEASES   - сколько релизов хранить (5)
#
# Использование: deploy/deploy.sh <тег-или-коммит>
set -euo pipefail

REF=${1:?"Usage: deploy.sh <tag-or-commit>"}
ROOT=${DEPLOY_ROOT:-/var/www/souzpir}
REPO=${DEPLOY_REPO:?"DEPLOY_REPO is required"}
PHP_BIN=${PHP_BIN:-php}
KEEP_RELEASES=${KEEP_RELEASES:-5}

RELEASES="$ROOT/releases"
SHARED="$ROOT/shared"
CURRENT="$ROOT/current"
RELEASE_DIR="$RELEASES/$(date +%Y%m%d%H%M%S)-$(echo "$REF" | tr -c 'A-Za-z0-9._-' '-')"

mkdir -p "$RELEASES" "$SHARED/storage/app" "$SHARED/storage/framework/cache" \
    "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" "$SHARED/storage/logs"

if [[ ! -f "$SHARED/.env" ]]; then
    echo "Missing $SHARED/.env — create it from .env.production.example first." >&2
    exit 1
fi

echo "==> Fetching $REF into $RELEASE_DIR"
git clone --quiet "$REPO" "$RELEASE_DIR"
git -C "$RELEASE_DIR" checkout --quiet "$REF"

ln -sfn "$SHARED/.env" "$RELEASE_DIR/.env"
ln -sfn "$SHARED/storage" "$RELEASE_DIR/storage"

echo "==> composer install"
(cd "$RELEASE_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)

echo "==> npm ci && build"
(cd "$RELEASE_DIR" && npm ci && npm run build)

echo "==> migrate --force"
(cd "$RELEASE_DIR" && "$PHP_BIN" artisan migrate --force)

echo "==> config/route/view cache"
(cd "$RELEASE_DIR" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache)

if [[ -L "$CURRENT" ]]; then
    readlink -f "$CURRENT" > "$ROOT/.previous-release"
fi

ln -sfn "$RELEASE_DIR" "$CURRENT"

echo "==> restarting services"
sudo systemctl restart souzpir-queue.service souzpir-schedule.service
sudo systemctl reload caddy

echo "==> pruning old releases (keep $KEEP_RELEASES)"
ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +"$((KEEP_RELEASES + 1))" | xargs -r rm -rf

echo "==> deployed $REF at $RELEASE_DIR"
