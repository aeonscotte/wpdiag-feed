#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf data/packs/* data/work/* keys/*
export WPDIAG_RETENTION_COUNT=10

# 1. Keypair.
php bin/gen-keypair.php > /dev/null
[ "$(stat -c '%a' keys/signing-secret.key)" = "600" ]
echo "1. Keypair OK"

# 2. Fixture build.
php bin/refresh.php --file=fixtures/wordfence-sample.json --force
LATEST=$(php -r '$i=json_decode(file_get_contents("data/packs/index.json"),true); echo $i["latest"];')
[ -n "$LATEST" ]
PACK="data/packs/pack-${LATEST}.wpdiag-pack"
[ -f "$PACK" ]
COUNT=$(php -r '$i=json_decode(file_get_contents("data/packs/index.json"),true); echo $i["packs"][0]["advisory_count"];')
[ "$COUNT" = "5" ]
echo "2. Fixture build OK (${COUNT} advisories)"

# 3. Self-verify.
php bin/verify-pack.php "$PACK" > /dev/null
echo "3. Verify OK"

# 4. Tamper rejection.
php tests/tamper.php "$PACK" data/work/tampered.pack > /dev/null
if php bin/verify-pack.php data/work/tampered.pack > /dev/null 2>&1; then
  echo "Tampered pack was accepted"; exit 1
fi
rm -f data/work/tampered.pack
echo "4. Tamper rejected OK"

# 5. Interval skip.
set +e
php bin/refresh.php --file=fixtures/wordfence-sample.json > /dev/null 2>&1
CODE=$?
set -e
[ "$CODE" -eq 3 ]
echo "5. Interval skip OK"

# 6. Serving.
php -S 127.0.0.1:8099 public/index.php > /dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT
sleep 1
STATUS=$(curl -s -o /tmp/wpdiag-latest.pack -w "%{http_code}" http://127.0.0.1:8099/latest)
[ "$STATUS" = "200" ]
cmp -s /tmp/wpdiag-latest.pack "$PACK"
[ "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8099/v/bogus)" = "404" ]
curl -s http://127.0.0.1:8099/meta.json | php -r '$m=json_decode(stream_get_contents(STDIN),true); exit(isset($m["latest"])?0:1);'
kill $SRV 2>/dev/null || true
trap - EXIT
echo "6. Serving OK"

# 7. Retention.
export WPDIAG_RETENTION_COUNT=3
for v in 2026.01.01.0001 2026.01.01.0002 2026.01.01.0003 2026.01.01.0004 2026.01.01.0005; do
  php bin/refresh.php --file=fixtures/wordfence-sample.json --force --version="$v" > /dev/null
done
PACKS=$(ls data/packs/pack-*.wpdiag-pack | wc -l)
[ "$PACKS" = "3" ]
LATEST2=$(php -r '$i=json_decode(file_get_contents("data/packs/index.json"),true); echo $i["latest"];')
[ "$LATEST2" = "2026.01.01.0005" ]
[ -f "data/packs/pack-${LATEST2}.wpdiag-pack" ]
echo "7. Retention OK"

# 8. Failure retention.
BEFORE=$(php -r '$i=json_decode(file_get_contents("data/packs/index.json"),true); echo $i["latest"];')
set +e
php bin/refresh.php --file=fixtures/broken.json --force > /dev/null 2>&1
CODE=$?
set -e
[ "$CODE" -eq 2 ]
AFTER=$(php -r '$i=json_decode(file_get_contents("data/packs/index.json"),true); echo $i["latest"];')
[ "$BEFORE" = "$AFTER" ]
echo "8. Failure retention OK"

echo "Feed server verification: PASS"
