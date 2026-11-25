#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
SRV=""
cleanup() {
  if [ -n "$SRV" ]; then
    kill "$SRV" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

export WPDIAG_PACKS_DIR="$TMP/packs"
export WPDIAG_WORK_DIR="$TMP/work"
export WPDIAG_LOG_DIR="$TMP/logs"
export WPDIAG_STATE_DIR="$TMP/state"
export WPDIAG_SIGNING_SECRET_KEY_PATH="$TMP/keys/signing-secret.key"
export WPDIAG_RETENTION_COUNT=10

# 1. Keypair (generated in the temp tree; real keys are never touched).
php bin/gen-keypair.php > /dev/null
[ "$(stat -c '%a' "$WPDIAG_SIGNING_SECRET_KEY_PATH")" = "600" ]
echo "1. Keypair OK"

# 2. Durable state logic.
php tests/state.php > /dev/null
echo "2. Durable state OK"

# 3. Normalizer against the real v3-shaped fixture.
php tests/normalizer.php > /dev/null
echo "3. Normalizer OK"

# 4. Fixture build.
php bin/refresh.php --file=fixtures/wordfence-sample.json --force
LATEST=$(php -r '$i=json_decode(file_get_contents($argv[1]),true); echo $i["latest"];' "$WPDIAG_PACKS_DIR/index.json")
[ -n "$LATEST" ]
PACK="$WPDIAG_PACKS_DIR/pack-${LATEST}.wpdiag-pack"
[ -f "$PACK" ]
COUNT=$(php -r '$i=json_decode(file_get_contents($argv[1]),true); echo $i["packs"][0]["advisory_count"];' "$WPDIAG_PACKS_DIR/index.json")
[ "$COUNT" = "5" ]
echo "4. Fixture build OK (${COUNT} advisories)"

# 5. Self-verify.
php bin/verify-pack.php "$PACK" > /dev/null
echo "5. Verify OK"

# 6. Tamper rejection.
php tests/tamper.php "$PACK" "$WPDIAG_WORK_DIR/tampered.pack" > /dev/null
if php bin/verify-pack.php "$WPDIAG_WORK_DIR/tampered.pack" > /dev/null 2>&1; then
  echo "Tampered pack was accepted"; exit 1
fi
rm -f "$WPDIAG_WORK_DIR/tampered.pack"
echo "6. Tamper rejected OK"

# 7. Durable six-hour interval guard, including a fresh PHP process (restart).
php -r 'require "lib/bootstrap.php"; $s=new Fetch_State(); $s->mark_started($s->read(), time());'
php bin/refresh.php --status > "$TMP/status.json"
php -r '$s=json_decode(file_get_contents($argv[1]),true); exit((($s["fetch_allowed"]??true)===false && ($s["reason"]??"")==="interval")?0:1);' "$TMP/status.json"
rm -f "$WPDIAG_STATE_DIR/fetch-state.json"
php bin/refresh.php --status > "$TMP/status.json"
php -r '$s=json_decode(file_get_contents($argv[1]),true); exit(($s["fetch_allowed"]??false)===true?0:1);' "$TMP/status.json"
echo "7. Interval guard survives a new process OK"

# 8. Serving.
php -S 127.0.0.1:8099 public/index.php > /dev/null 2>&1 &
SRV=$!
sleep 1
STATUS=$(curl -s -o "$TMP/latest.pack" -w "%{http_code}" http://127.0.0.1:8099/latest)
[ "$STATUS" = "200" ]
cmp -s "$TMP/latest.pack" "$PACK"
[ "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8099/v/bogus)" = "404" ]
curl -s http://127.0.0.1:8099/meta.json | php -r '$m=json_decode(stream_get_contents(STDIN),true); exit(isset($m["latest"])?0:1);'
curl -s http://127.0.0.1:8099/status.json | php -r '$m=json_decode(stream_get_contents(STDIN),true); exit(array_key_exists("fetch_allowed",$m)?0:1);'
kill "$SRV" 2>/dev/null || true
SRV=""
echo "8. Serving OK"

# 9. 429 handling: Retry-After extends the durable guard.
php -S 127.0.0.1:8098 tests/rate-limit-router.php > /dev/null 2>&1 &
SRV=$!
sleep 1
rm -f "$WPDIAG_STATE_DIR/fetch-state.json"
set +e
WPDIAG_WORDFENCE_API_URL=http://127.0.0.1:8098/feed WORDFENCE_API_KEY=fake-key php bin/refresh.php > "$TMP/rate-limit.out" 2>&1
CODE=$?
set -e
[ "$CODE" -eq 2 ]
php bin/refresh.php --status > "$TMP/status.json"
php -r '$s=json_decode(file_get_contents($argv[1]),true); exit((($s["last_status"]??"")==="rate_limited" && ($s["last_http_status"]??0)===429 && ($s["next_allowed_at"]??0)>=time()+29990 && ($s["rate_limited_until"]??0)>=time()+29990)?0:1);' "$TMP/status.json"
kill "$SRV" 2>/dev/null || true
SRV=""
rm -f "$WPDIAG_STATE_DIR/fetch-state.json"
echo "9. 429 guard OK"

# 10. Retention.
export WPDIAG_RETENTION_COUNT=3
for v in 2026.01.01.0001 2026.01.01.0002 2026.01.01.0003 2026.01.01.0004 2026.01.01.0005; do
  php bin/refresh.php --file=fixtures/wordfence-sample.json --force --version="$v" > /dev/null
done
PACKS=$(ls "$WPDIAG_PACKS_DIR"/pack-*.wpdiag-pack | wc -l)
[ "$PACKS" = "3" ]
LATEST2=$(php -r '$i=json_decode(file_get_contents($argv[1]),true); echo $i["latest"];' "$WPDIAG_PACKS_DIR/index.json")
[ "$LATEST2" = "2026.01.01.0005" ]
[ -f "$WPDIAG_PACKS_DIR/pack-${LATEST2}.wpdiag-pack" ]
echo "10. Retention OK"

# 11. Failure retention.
BEFORE=$(php -r '$i=json_decode(file_get_contents($argv[1]),true); echo $i["latest"];' "$WPDIAG_PACKS_DIR/index.json")
set +e
php bin/refresh.php --file=fixtures/broken.json --force > /dev/null 2>&1
CODE=$?
set -e
[ "$CODE" -eq 2 ]
AFTER=$(php -r '$i=json_decode(file_get_contents($argv[1]),true); echo $i["latest"];' "$WPDIAG_PACKS_DIR/index.json")
[ "$BEFORE" = "$AFTER" ]
echo "11. Failure retention OK"

# 12. Release asset staging.
php bin/build-release.php --dist="$TMP/dist" > /dev/null
[ -f "$TMP/dist/latest.wpdiag-pack" ]
[ -f "$TMP/dist/meta.json" ]
[ -f "$TMP/dist/status.json" ]
[ -f "$TMP/dist/index.html" ]
echo "12. Release assets OK"

echo "Feed server verification: PASS"
