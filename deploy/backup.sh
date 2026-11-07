#!/usr/bin/env bash
# pg_dump с ротацией 14 дней. Пароль берётся из ~/.pgpass хоста бэкапа, не из этой команды.
# Переменные: BACKUP_DIR (/var/backups/souzpir), RETENTION_DAYS (14),
# DB_HOST (127.0.0.1), DB_PORT (5432), DB_DATABASE, DB_USERNAME — обязательны.
set -euo pipefail

BACKUP_DIR=${BACKUP_DIR:-/var/backups/souzpir}
RETENTION_DAYS=${RETENTION_DAYS:-14}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-5432}
DB_DATABASE=${DB_DATABASE:?"DB_DATABASE is required"}
DB_USERNAME=${DB_USERNAME:?"DB_USERNAME is required"}

mkdir -p "$BACKUP_DIR"
FILE="$BACKUP_DIR/souzpir-$(date +%Y%m%d-%H%M%S).sql.gz"

pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" | gzip > "$FILE"

find "$BACKUP_DIR" -name 'souzpir-*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete

echo "==> backup saved to $FILE"
