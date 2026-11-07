# Runbook: прод souzpir

## Первая установка

1. На сервере создать пользователя `souzpir` и каталог `/var/www/souzpir` с подкаталогами `releases/`, `shared/`.
2. `cp .env.production.example /var/www/souzpir/shared/.env`, заполнить секреты (`APP_KEY`, `DB_*`, почта/AI при включении), `chmod 600`. `APP_KEY` сгенерировать: `php artisan key:generate --show` (на любой машине с тем же кодом) и вставить вручную — не хранить в репозитории.
3. Создать БД и роль PostgreSQL 16 с правами на `DB_DATABASE` из `.env`.
4. Установить systemd-юниты: `cp deploy/systemd/*.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable souzpir-queue souzpir-schedule`.
5. Установить Caddy, скопировать `deploy/Caddyfile.example` в `/etc/caddy/Caddyfile`, подставить `APP_DOMAIN`; `systemctl enable --now caddy`.
6. Выполнить первую выкладку (см. ниже).
7. Добавить `deploy/crontab.example` в crontab пользователя `souzpir` (`crontab -e`), подставив реальные `DB_DATABASE`/`DB_USERNAME`; создать `~/.pgpass` с паролем БД для бэкапов.

## Выкладка

```
DEPLOY_REPO=<git-url> deploy/deploy.sh <тег-или-коммит>
```

Клонирует код в новый `releases/<timestamp>-<ref>`, линкует `shared/.env` и `shared/storage`, `composer install --no-dev`, `npm ci && npm run build`, `migrate --force`, кеширует config/route/view, переключает симлинк `current`, перезапускает `souzpir-queue`, `souzpir-schedule` и Caddy. Хранит последние 5 релизов (`KEEP_RELEASES`).

## Откат

```
deploy/rollback.sh
```

Переключает `current` на предыдущий релиз (записан в `.previous-release` предыдущей выкладкой) и перезапускает сервисы. Откатывает только код — миграции назад не прогоняет; при несовместимой миграции чинить вручную.

## Бэкап и восстановление

- Бэкап: `deploy/backup.sh` (запускается по cron, см. `deploy/crontab.example`) — `pg_dump` в `BACKUP_DIR` (по умолчанию `/var/backups/souzpir`), ротация 14 дней (`RETENTION_DAYS`). Пароль — из `~/.pgpass`, не из командной строки.
- Восстановление: `gunzip -c /var/backups/souzpir/souzpir-<дата>.sql.gz | psql -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE`. Перед восстановлением в боевую БД остановить `souzpir-queue`/`souzpir-schedule` и веб (Caddy), иначе параллельные записи конфликтуют с восстановлением.

## Включение живых флагов

Все внешние каналы выключены по умолчанию (`AI_DRIVER=fake`, `DELIVERY_DRIVER=fake`, `NOTIFICATION_DRIVER=fake`, `VISION_LIVE_MEASUREMENTS_ENABLED=false`). Включать по одному, после проверки `/health` и профильного прогона конкретного канала; `LIVE_DRAFT_ENABLED` и `VISION_LIVE_MEASUREMENTS_ENABLED` — только по решению владельца.

## HTTPS и доверенный прокси

Caddy в шаблоне сам терминирует TLS и обращается к PHP-FPM напрямую — без `TRUSTED_PROXIES` это единственная безопасная схема по умолчанию (см. `docs/runbooks/https-forwarding.md`). Если перед Caddy стоит внешний прокси/LB — задать `TRUSTED_PROXIES` точным IP/CIDR этого прокси и настроить `trusted_proxies` в Caddyfile; wildcard и общие сети не принимаются. Ссылка восстановления пароля отклоняет forwarding-заголовки всегда — её реальную доставку проверить отдельно до включения почты.

## Проверка `/health`

`GET /health` — 200 `{"status":"ok", "database": true, "queue": {"healthy": true, "lag_seconds": N, "failed_jobs": N}}`, иначе 503 со `status: "degraded"`. Без секретов в ответе. `/up` (стандартный Laravel health) не менять — используется отдельно для простого liveness.
