#!/usr/bin/env bash
# Publishes the current version to client sites.
# 1. Bump "Version:", DGWC_VERSION and "Stable tag:" and add a "= x.y.z =" changelog entry in readme.txt.
# 2. Commit, then run: bin/release.sh
# The zip is built from the committed code, tagged here, and published on the public releases repo,
# where every site's updater (includes/class-dgwc-updater.php) finds it.
set -euo pipefail

RELEASES_REPO="shahbazshafi/digitum-whatsapp-chat-releases"
SLUG="digitum-whatsapp-chat"
cd "$(dirname "$0")/.."

V=$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | tr -d '[:space:]')
[ -n "$V" ] || { echo "No Version: in $SLUG.php"; exit 1; }
grep -q "define( 'DGWC_VERSION', '$V' );" "$SLUG.php" || { echo "DGWC_VERSION does not match $V"; exit 1; }
grep -q "^Stable tag: $V\$" readme.txt || { echo "readme.txt Stable tag does not match $V"; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit your changes first"; exit 1; }
if git rev-parse -q --verify "refs/tags/v$V" >/dev/null; then echo "v$V is already released: bump the version"; exit 1; fi

NOTES=$(awk -v v="= $V =" '$0==v{f=1;next} f&&/^= /{exit} f' readme.txt)
[ -n "$NOTES" ] || { echo "Add a '= $V =' changelog entry to readme.txt"; exit 1; }

OUT=$(mktemp -d)
git archive --format=zip --prefix="$SLUG/" -o "$OUT/$SLUG.zip" HEAD
cp "$OUT/$SLUG.zip" "$OUT/$SLUG-v$V.zip"

git tag -a "v$V" -m "Version $V"
git push origin HEAD "v$V"
gh release create "v$V" "$OUT/$SLUG.zip" "$OUT/$SLUG-v$V.zip" \
  --repo "$RELEASES_REPO" --title "Version $V" --notes "$NOTES"
echo "Released v$V. Sites see it within 6 hours, or straight away with Dashboard > Updates > Check again."
