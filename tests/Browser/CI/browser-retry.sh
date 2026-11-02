#!/usr/bin/env bash
set -euo pipefail
root=$(pwd -P)
tmp="$root/.local/ci09-retry-test"
mkdir -p "$tmp/bin" "$tmp/.local/runtime"
sed -n '/^# Keep the first output separately:/,$p' scripts/ci/browser-smoke.sh > "$tmp/run.sh"
cat > "$tmp/bin/npm" <<'NPM'
#!/usr/bin/env bash
count=0
[[ -f count ]] && count=$(cat count)
count=$((count + 1))
printf '%s' "$count" > count
printf 'spec failure attempt %s\n' "$count"
if [[ ${FAIL_BOTH:-0} == 1 || $count == 1 ]]; then exit 42; fi
NPM
chmod +x "$tmp/bin/npm"
cd "$tmp"
export PATH="$tmp/bin:$PATH" BROWSER_SHARD=1/3
bash -c 'set -euo pipefail; browser_options=(--workers=1 --shard=1/3); source ./run.sh'
test "$(cat count)" = 2
grep -q 'spec failure attempt 1' .local/runtime/browser-first-attempt.log
grep -q 'spec failure attempt 2' .local/runtime/browser-retry.log
rm count
if FAIL_BOTH=1 bash -c 'set -euo pipefail; browser_options=(--workers=1 --shard=1/3); source ./run.sh'; then
  echo 'A second failed attempt must fail the shard' >&2
  exit 1
fi
test "$(cat count)" = 2
echo 'Browser retry checks passed.'
