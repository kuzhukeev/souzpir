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
export RUN_ONBOARDING_E2E=1 RUN_ORGANIZATIONS_E2E=1 RUN_BRANDS_E2E=1
export INVITATION_BROWSER_CI_FIXTURE=1 INVITATION_BROWSER_TEST_DATABASE=souzpir_test_sp_invitation_ci
export PHP_BINARY=php INVITATION_DB_PASSWORD=ci-only-password E802_LOCAL_BASE=http://souzpir.local:8249
browser_options=(--workers=1)
if [[ "${BROWSER_SHARD:-1/1}" =~ ^[123]/3$ ]]; then
  for part in 1 2 3; do
    FORCE_COLOR=0 npx playwright test --list --shard="$part/3" > ".local/runtime/browser-list-$part.txt"
  done
  python3 - <<'PY'
from pathlib import Path

shards = []
for part in (1, 2, 3):
    lines = Path(f'.local/runtime/browser-list-{part}.txt').read_text(encoding='utf-8').splitlines()
    tests = [line.strip() for line in lines if line.lstrip().startswith('[chromium] › ')]
    if not tests:
        raise SystemExit(f'No browser tests discovered in shard {part}')
    shards.append(tests)

before = [test for shard in shards for test in shard]
pages = [test for test in shards[1] if 'E8-02/pages.spec.ts:' in test.replace('\\', '/')]
if pages:
    shards[1] = [test for test in shards[1] if test not in pages]
    shards[2].extend(pages)
after = [test for shard in shards for test in shard]
if len(after) != len(before) or len(set(after)) != len(after):
    raise SystemExit('Browser shard selection lost or duplicated tests')
for part, tests in enumerate(shards, 1):
    Path(f'.local/runtime/browser-selected-{part}.txt').write_text('\n'.join(tests) + '\n', encoding='utf-8')
print(f'Browser shard tests: {[len(tests) for tests in shards]}; moved E8-02/pages.spec.ts: {len(pages)}')
PY
  browser_options+=("--test-list=.local/runtime/browser-selected-${BROWSER_SHARD%%/*}.txt")
else
  browser_options+=("--shard=${BROWSER_SHARD:-1/1}")
fi
npm run test:browser -- "${browser_options[@]}"
