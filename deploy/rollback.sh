#!/usr/bin/env bash
# Откат на предыдущий релиз одной командой: deploy/rollback.sh
set -euo pipefail

ROOT=${DEPLOY_ROOT:-/var/www/souzpir}
CURRENT="$ROOT/current"
PREVIOUS_FILE="$ROOT/.previous-release"

if [[ ! -f "$PREVIOUS_FILE" ]]; then
    echo "No previous release recorded in $PREVIOUS_FILE" >&2
    exit 1
fi

PREVIOUS=$(cat "$PREVIOUS_FILE")
if [[ ! -d "$PREVIOUS" ]]; then
    echo "Previous release $PREVIOUS no longer exists" >&2
    exit 1
fi

ln -sfn "$PREVIOUS" "$CURRENT"

sudo systemctl restart souzpir-queue.service souzpir-schedule.service
sudo systemctl reload caddy

echo "==> rolled back to $PREVIOUS"
