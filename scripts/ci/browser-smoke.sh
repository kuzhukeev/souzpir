#!/usr/bin/env bash
set -euo pipefail

if [[ ${CI:-} != true ]]; then
  echo 'Browser smoke requires a disposable CI runner.' >&2
  exit 1
fi

mkdir -p .local/runtime
# Disposable GitHub runner: Node HTTP clients must resolve the local fixture host.
printf '127.0.0.1 souzpir.local\n' | sudo tee -a /etc/hosts >/dev/null
php -S 127.0.0.1:8101 -t public scripts/ci/server.php > .local/runtime/browser-server.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:8101/up && break; sleep 1; done
curl --fail --silent http://127.0.0.1:8101/up > /dev/null
npx vite build --config vite.layout.config.ts
npx vite build --config tests/Browser/E1-02/vite.config.ts
npx vite build --config tests/Browser/E1-04/vite.config.ts
npx vite --config tests/Browser/E1-10/vite.config.ts --host 127.0.0.1 > .local/runtime/e110-vite.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:5237/tests/Browser/E1-10/ui-fixture.html > /dev/null && break; sleep 1; done
curl --fail --silent http://127.0.0.1:5237/tests/Browser/E1-10/ui-fixture.html > /dev/null
npx vite --config tests/Browser/E6-09/vite.config.ts --host 127.0.0.1 > .local/runtime/e609-vite.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:5269/tests/Browser/E6-09/ui-fixture.html > /dev/null && break; sleep 1; done
curl --fail --silent http://127.0.0.1:5269/tests/Browser/E6-09/ui-fixture.html > /dev/null
php -S 127.0.0.1:5183 -t .local/runtime/layout-fixture > .local/runtime/layout-vite.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:5183/tests/Browser/Layout/fixture.html > /dev/null && break; sleep 1; done
curl --fail --silent http://127.0.0.1:5183/tests/Browser/Layout/fixture.html > /dev/null
curl --fail --silent http://127.0.0.1:5183/tests/Browser/E1-02/ui-fixture.html > /dev/null
E102_FIXTURE_ENABLED=1 E102_FIXTURE_ORG=01J00000000000000000000002 \
  CABINET_ORGANIZATION_ID=01J00000000000000000000002 php tests/Browser/E1-02/prepare-fixture.php
CABINET_ORGANIZATION_ID=01J00000000000000000000002 APP_URL=http://127.0.0.1:8102 \
  APP_INSTANCE=ci-e102 SESSION_COOKIE=ci_e102_session \
  php artisan serve --env=testing --no-reload --host=127.0.0.1 --port=8102 > .local/runtime/onboarding-server.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:8102/up && break; sleep 1; done
curl --fail --silent http://127.0.0.1:8102/up > /dev/null
E104_FIXTURE_ENABLED=1 E104_FIXTURE_ORG=01J00000000000000000000004 \
  CABINET_ORGANIZATION_ID=01J00000000000000000000004 php tests/Browser/E1-04/prepare-fixture.php
CABINET_ORGANIZATION_ID=01J00000000000000000000004 APP_URL=http://127.0.0.1:8104 \
  APP_INSTANCE=ci-e104 SESSION_COOKIE=ci_e104_session \
  php artisan serve --env=testing --no-reload --host=127.0.0.1 --port=8104 > .local/runtime/organizations-server.log 2>&1 &
for attempt in {1..20}; do curl --fail --silent http://127.0.0.1:8104/up && break; sleep 1; done
curl --fail --silent http://127.0.0.1:8104/up > /dev/null
bash scripts/ci/browser-fixtures.sh
RUN_ONBOARDING_E2E=1 RUN_ORGANIZATIONS_E2E=1 RUN_BRANDS_E2E=1 \
  INVITATION_BROWSER_CI_FIXTURE=1 INVITATION_BROWSER_TEST_DATABASE=souzpir_test_sp_invitation_ci \
  PHP_BINARY=php INVITATION_DB_PASSWORD=ci-only-password E802_LOCAL_BASE=http://souzpir.local:8249 npm run test:browser -- --workers=1 --shard="${BROWSER_SHARD:-1/1}"
