#!/usr/bin/env bash
# Builds SquidForm into dist/:
#   pkg_squidform-<version>.zip                 the package to install
#   plg_squidform_scannertools-<v>.zip          the optional scanner add-on (its own version; only if its folder is here)
#   squidform-<version>-SHA256SUMS.txt          every file inside them
#   squidform-<version>-IT-review.md            the IT review kit (from docs/IT-REVIEW.md)
#   pkg_squidform-<version>-source.zip          everything: code, tests, docs, this script (keep it with the release)
#
# Usage:  ./build.sh                 (needs: zip, php)
#         PHPCS=/path/to/phpcs ./build.sh   also checks PHP 8.2–8.5 compatibility (PHPCompatibility standard)
#
# It refuses to build when a shipped file:
#   - has a PHP syntax error;
#   - calls proc_open, shell_exec, passthru, exec(, system(, popen( or eval( (the scanner add-on may use proc_open);
#   - contains the EICAR test string, or is named like something.php.ext;
#   - has an outside address (http:// or https://) in shipped JavaScript or CSS;
#   - has a version that doesn't match pkg_squidform.xml.
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg_squidform.xml | head -1)
OUT=dist
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PARTS=(com_squidform mod_squidform mod_squidform_viewer plg_system_squidform)
ADDON=plg_squidform_scannertools
fail() { echo "BUILD STOPPED: $*" >&2; exit 1; }

echo "SquidForm $VERSION"

echo "· versions match…"
for m in com_squidform/squidform.xml mod_squidform/mod_squidform.xml mod_squidform_viewer/mod_squidform_viewer.xml plg_system_squidform/squidform.xml; do
  v=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$m" | head -1)
  [ "$v" = "$VERSION" ] || fail "$m says $v, pkg_squidform.xml says $VERSION"
done
grep -q "^## $VERSION " CHANGELOG.md || fail "CHANGELOG.md has no \"## $VERSION\" entry"

echo "· PHP syntax…"
find "${PARTS[@]}" script.php -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
[ -d "$ADDON" ] && find "$ADDON" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

if [ -n "${PHPCS:-}" ]; then
  echo "· PHP 8.2–8.5 compatibility…"
  $PHPCS -q --standard=PHPCompatibility --runtime-set testVersion 8.2-8.5 --extensions=php "${PARTS[@]}" script.php
fi

echo "· nothing that runs programs or code…"
if grep -RInE '\b(proc_open|shell_exec|passthru|popen|pcntl_exec)\s*\(|(^|[^A-Za-z_>:$])(exec|system|eval)\s*\(' --include='*.php' "${PARTS[@]}" script.php; then
  fail "a shipped PHP file runs programs or code (see above)"
fi
if grep -RIl 'X5O!P%@AP\[4\\PZX54(P^)7CC)7}\$EICAR' . --exclude-dir=.git --exclude-dir=dist --exclude-dir=node_modules 2>/dev/null; then
  fail "the EICAR test string is in a file (it must only exist base64-encoded in the add-on)"
fi
if find "${PARTS[@]}" -type f | grep -E '\.php\.[A-Za-z0-9]+$'; then
  fail "a file is named like something.php.ext"
fi

echo "· offline: no outside addresses in shipped JavaScript or CSS…"
if grep -RInE '(https?:)?//[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+\.[A-Za-z]{2,}' --include='*.js' --include='*.mjs' --include='*.css' com_squidform/media | grep -vE 'www\.w3\.org/(2000/svg|1999/xlink)'; then
  fail "an outside address in media/: SquidForm must run offline"
fi

echo "· packaging…"
mkdir -p "$WORK/packages" "$OUT"
for p in "${PARTS[@]}"; do (cd "$p" && zip -qrX "$WORK/packages/$p.zip" . -x '*.DS_Store'); done
cp pkg_squidform.xml script.php "$WORK/"
rm -f "$OUT/pkg_squidform-$VERSION.zip"
(cd "$WORK" && zip -qrX "$OLDPWD/$OUT/pkg_squidform-$VERSION.zip" .)

ADDON_VERSION=""
if [ -d "$ADDON" ]; then
  ADDON_VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ADDON/scannertools.xml" | head -1)
  rm -f "$OUT/$ADDON-$ADDON_VERSION.zip"
  (cd "$ADDON" && zip -qrX "$OLDPWD/$OUT/$ADDON-$ADDON_VERSION.zip" . -x '*.DS_Store')
else
  echo "  (no $ADDON folder here: the add-on zip isn't rebuilt; it hasn't changed since 1.0.2)"
fi

echo "· checksums and the IT review kit…"
SUMS="$OUT/squidform-$VERSION-SHA256SUMS.txt"
{
  echo "# SquidForm $VERSION: SHA-256 of every file, as installed from each zip"
  for p in "${PARTS[@]}"; do (find "$p" -type f ! -name '.DS_Store' -print0 | sort -z | xargs -0 sha256sum); done
  [ -d "$ADDON" ] && (find "$ADDON" -type f ! -name '.DS_Store' -print0 | sort -z | xargs -0 sha256sum)
  true
} > "$SUMS"
FILECOUNT=$(grep -vc '^#' "$SUMS")
FINGERPRINTS=$(cd "$OUT" && sha256sum "pkg_squidform-$VERSION.zip"; [ -n "$ADDON_VERSION" ] && sha256sum "$ADDON-$ADDON_VERSION.zip" || true)
[ -z "$ADDON_VERSION" ] && ADDON_VERSION="1.0.2"
python3 - "$VERSION" "$ADDON_VERSION" "$FILECOUNT" "$FINGERPRINTS" "$OUT/squidform-$VERSION-IT-review.md" <<'PY'
import sys
v, av, n, fp, dst = sys.argv[1:6]
s = open('docs/IT-REVIEW.md').read()
s = s.split('-->', 1)[1].lstrip('\n') if s.startswith('<!--') else s
for k, val in {'{VERSION}': v, '{ADDON_VERSION}': av, '{FILECOUNT}': n, '{FINGERPRINTS}': fp}.items():
    s = s.replace(k, val)
open(dst, 'w').write(s)
PY
(cd "$OUT" && sha256sum "pkg_squidform-$VERSION.zip" > "pkg_squidform-$VERSION.zip.sha256")

echo "· checking the package installs what's in the source…"
CHECK=$(mktemp -d)
unzip -q "$OUT/pkg_squidform-$VERSION.zip" -d "$CHECK"
for p in "${PARTS[@]}"; do
  mkdir -p "$CHECK/x/$p" && unzip -q "$CHECK/packages/$p.zip" -d "$CHECK/x/$p"
  diff -rq "$p" "$CHECK/x/$p" -x '.DS_Store' > /dev/null || fail "$p in the zip differs from the source"
done
rm -rf "$CHECK"

echo "· source zip (keep it with the release)…"
rm -f "$OUT/pkg_squidform-$VERSION-source.zip"
zip -qrX "$OUT/pkg_squidform-$VERSION-source.zip" . -x 'dist/*' -x '.git/*' -x '*/node_modules/*' -x 'node_modules/*' -x '*.DS_Store' -x 'tests/results/*'

echo "Built $OUT/pkg_squidform-$VERSION.zip ($FILECOUNT files checked), the IT review kit and the source zip."
cat "$OUT/pkg_squidform-$VERSION.zip.sha256"
