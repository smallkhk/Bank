#!/usr/bin/env bash
# Build dist/bank-release.zip for cPanel upload:
#   bank/         private application files (outside the web root)
#   public_html/  the website (install.php, index.php, assets)
# Never includes config/config.php, logs, uploads or other runtime data.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT=dist; STAGE=$(mktemp -d)
mkdir -p "$STAGE/bank" "$OUT"
git ls-files -z | grep -zv '^public_html/' | grep -zv '^tools/' | xargs -0 -I{} cp --parents {} "$STAGE/bank/"
git ls-files -z public_html | xargs -0 -I{} cp --parents {} "$STAGE/"
mkdir -p "$STAGE/bank/storage/logs" "$STAGE/bank/storage/cache" "$STAGE/public_html/uploads"
rm -f "$OUT/bank-release.zip"
(cd "$STAGE" && zip -qr9 "$OLDPWD/$OUT/bank-release.zip" bank public_html -x '*.DS_Store')
rm -rf "$STAGE"
echo "Built $OUT/bank-release.zip ($(du -h "$OUT/bank-release.zip" | cut -f1))"
