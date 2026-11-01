#!/usr/bin/env bash
# Start isolated browser fixtures on a disposable CI PostgreSQL service only.
set -euo pipefail
if [[ "${CI:-}" != true || "${APP_ENV:-}" != testing || "${DB_HOST:-}" != 127.0.0.1 || "${DB_PORT:-}" != 5432 ]]; then
  echo 'Browser fixture startup requires the isolated CI service.' >&2
  exit 1
fi
root=$(pwd -P)
# PHP on Windows needs a Windows path even when this script is run in Git Bash.
if [[ ${OS:-} == Windows_NT ]]; then root=$(cygpath -m "$root"); fi
tmp="$root/.local/tmp"
mkdir -p "$tmp" "$root/.local/runtime"
password=${DB_PASSWORD:?CI database password is required}
app_key=$(sed -n 's/^APP_KEY=//p' .env.testing | tail -n 1)
test -n "$app_key"
children=()
cleanup_on_error() { for pid in "${children[@]}"; do kill "$pid" 2>/dev/null || true; done; }
trap cleanup_on_error ERR
wait_http() {
  local url=$1
  for _ in {1..60}; do
    if curl --fail --silent "$url" >/dev/null; then return; fi
    sleep 1
  done
  echo "Fixture did not start: $url" >&2
  return 1
}
start_vite() {
  local name=$1 config=$2 port=$3 fixture=$4
  npx vite --config "$config" --host 127.0.0.1 --port "$port" --strictPort >".local/runtime/browser-ci-$name.log" 2>&1 &
  children+=("$!")
  wait_http "http://127.0.0.1:$port/$fixture"
}
start_vite e105 tests/Browser/E1-05/vite.config.ts 5215 tests/Browser/E1-05/ui-fixture.html
start_vite e106 tests/Browser/E1-06/vite.config.ts 5216 tests/Browser/E1-06/ui-fixture.html
start_vite e106d tests/Browser/E1-06/vite.config.ts 5232 tests/Browser/E1-06D/last-login-fixture.html
start_vite e108 tests/Browser/E1-08/vite.config.ts 5238 tests/Browser/E1-08/ui-fixture.html
start_vite mfa tests/Browser/E1-08/vite.config.ts 5268 tests/Browser/E1-08/ui-fixture.html
start_vite e802 tests/Browser/E8-02/vite.config.ts 5248 tests/Browser/E8-02/ui-fixture.html
start_vite e803 tests/Browser/E8-03/vite.config.ts 5258 tests/Browser/E8-03/pages-fixture.html

common=(APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_PASSWORD="$password"
  CACHE_STORE=file SESSION_DRIVER=file MAIL_MAILER=log AI_DRIVER=fake DELIVERY_DRIVER=fake QUEUE_CONNECTION=sync)

profile=("${common[@]}" DB_DATABASE=souzpir_test_sp_e1_08 DB_TEST_DATABASE=souzpir_test_sp_e1_08
  DB_USERNAME=sp_e1_08_app CABINET_ORGANIZATION_ID=01J00000000000000000000008
  APP_URL=http://127.0.0.1:8238 APP_STORAGE_PATH="$root/.local/runtime/testing/storage/e108"
  E108_FIXTURE_ENABLED=1 PROFILE_SETTINGS_ENABLED=true)
env "${profile[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
env "${profile[@]}" php -d "sys_temp_dir=$tmp" tests/Browser/E1-08/prepare-fixture.php
env "${profile[@]}" php -d "sys_temp_dir=$tmp" -d "upload_tmp_dir=$tmp" -S 127.0.0.1:8238 -t public scripts/ci/server.php >.local/runtime/browser-ci-profile.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8238/up

recovery=("${common[@]}" DB_DATABASE=souzpir_test_sp_e1_01g DB_TEST_DATABASE=souzpir_test_sp_e1_01g
  DB_USERNAME=sp_e101g_app APP_URL=http://127.0.0.1:8241
  APP_STORAGE_PATH="$root/.local/runtime/testing/storage/e101g" E101G_CI_FIXTURE=1)
env "${recovery[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
env "${recovery[@]}" php -d "sys_temp_dir=$tmp" -S 127.0.0.1:8241 -t public scripts/ci/server.php >.local/runtime/browser-ci-recovery.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8241/up

mfa=("${common[@]}" DB_DATABASE=souzpir_test_mfa_ci DB_TEST_DATABASE=souzpir_test_mfa_ci
  DB_USERNAME=sp_mfa_ci_app CABINET_ORGANIZATION_ID=01J00000000000000000000009
  APP_STORAGE_PATH="$root/.local/runtime/testing/storage/mfa-ci" APP_URL=http://souzpir.local:8268
  APP_INSTANCE=ci-mfa APP_KEY="$app_key" MFA_LOCAL_FIXTURE=1 MFA_LOCAL_PORT=8268 PROFILE_SETTINGS_ENABLED=true)
env "${mfa[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
env "${mfa[@]}" APP_ENV=local php -d "sys_temp_dir=$tmp" tests/Browser/E1-08/mfa-local-fixture.php
env "${mfa[@]}" APP_ENV=local php -d "sys_temp_dir=$tmp" -S 127.0.0.1:8268 -t public tests/Browser/E1-08/mfa-local-router.php >.local/runtime/browser-ci-mfa.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8268/up

profile_local=("${profile[@]}" APP_ENV=local APP_INSTANCE=ci-profile-local
  APP_URL=http://souzpir.local:8269 APP_KEY="$app_key" APP_STORAGE_PATH="$root/.local/runtime/testing/storage/profile-local"
  MFA_LOCAL_FIXTURE=1 MFA_LOCAL_PORT=8269 PROFILE_TELEGRAM_BOT_USERNAME=synthetic_souzpir_bot
  PROFILE_TELEGRAM_WEBHOOK_SECRET=synthetic-ci-only-secret)
env "${profile_local[@]}" php -d "sys_temp_dir=$tmp" -S 127.0.0.1:8269 -t public tests/Browser/E1-08/mfa-local-router.php >.local/runtime/browser-ci-profile-local.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8269/up
invitation=("${common[@]}" DB_DATABASE=souzpir_test_sp_invitation_ci DB_TEST_DATABASE=souzpir_test_sp_invitation_ci
  DB_USERNAME=sp_invitation_ci_app APP_URL=http://127.0.0.1:8244
  APP_STORAGE_PATH="$root/.local/runtime/testing/invitation/storage" INVITATION_BROWSER_CI_FIXTURE=1
  INVITATION_BROWSER_TEST_DATABASE=souzpir_test_sp_invitation_ci)
env "${invitation[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
env "${invitation[@]}" php -d "sys_temp_dir=$tmp" artisan serve --env=testing --no-reload --host=127.0.0.1 --port=8244 >.local/runtime/browser-ci-invitation.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8244/up

brand=("${common[@]}" DB_DATABASE=souzpir_test_sp_e1_05 DB_TEST_DATABASE=souzpir_test_sp_e1_05
  DB_USERNAME=sp_e1_05_app CABINET_ORGANIZATION_ID=01J00000000000000000000005
  APP_URL=http://127.0.0.1:8215 APP_STORAGE_PATH="$root/.local/runtime/testing/storage/e105")
env "${brand[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
env "${brand[@]}" E105_FIXTURE_ENABLED=1 E105_FIXTURE_ORG=01J00000000000000000000005 php -d "sys_temp_dir=$tmp" tests/Browser/E1-05/prepare-fixture.php
env "${brand[@]}" php -d "sys_temp_dir=$tmp" artisan serve --env=testing --no-reload --host=127.0.0.1 --port=8215 >.local/runtime/browser-ci-brand.log 2>&1 &
children+=("$!")
wait_http http://127.0.0.1:8215/up

start_vision() {
  local name=$1 db=$2 role=$3 port=$4 mode=$5 directory=E8-02
  local -a settings=("${common[@]}" DB_DATABASE="$db" DB_TEST_DATABASE="$db" DB_USERNAME="$role"
    APP_URL="http://127.0.0.1:$port" APP_STORAGE_PATH="$root/.local/runtime/testing/storage/$name")
  if [[ $name == e803 ]]; then
    directory=E8-03
    settings+=(E803_FIXTURE_ENABLED=1 E803_CI_FIXTURE=1 E803_CI_BIND_LOOPBACK=1 E803_LOCAL_HTTP_TEST=1 APP_URL="http://souzpir.local:$port")
  else
    settings+=(E802_FIXTURE_ENABLED=1 E802_CI_FIXTURE="$mode" E802_CI_BIND_LOOPBACK=1)
    if [[ $mode == local ]]; then settings+=(E802_LOCAL_HTTP_TEST=1 APP_URL="http://souzpir.local:$port"); fi
  fi
  env "${settings[@]}" php -d "sys_temp_dir=$tmp" scripts/ci/assert-browser-database.php
  env "${settings[@]}" php -d "sys_temp_dir=$tmp" artisan migrate --env=testing --force
  env "${settings[@]}" php -d "sys_temp_dir=$tmp" "tests/Browser/$directory/prepare-fixture.php"
  env "${settings[@]}" php -d "sys_temp_dir=$tmp" -d "upload_tmp_dir=$tmp" -S "127.0.0.1:$port" -t public "tests/Browser/$directory/server.php" >".local/runtime/browser-ci-$name.log" 2>&1 &
  children+=("$!")
  wait_http "http://127.0.0.1:$port/up"
}
# Local HTTP and loopback tests mutate the same questionnaire; their databases and
# processes are distinct, so Playwright workers can run them concurrently.
start_vision e802 souzpir_test_sp_e8_02 sp_e8_02_app 8248 1
start_vision e802_local souzpir_test_sp_e8_02_local sp_e8_02_local_app 8249 local
start_vision e803 souzpir_test_sp_e8_03 sp_e8_03_app 8258 1
trap - ERR
