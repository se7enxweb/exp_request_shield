#!/usr/bin/env bash
# exp_request_shield: builds the bundled library from a request-shield release tag.
#
#   RECTOR=<rector workspace>/vendor/bin/rector bin/update-library.sh <request-shield clone> <tag>
#   e.g. RECTOR=~/rector/vendor/bin/rector bin/update-library.sh ../request-shield v0.4.0
#
# Checks the tag out in a temporary worktree of the clone (inside the clone's .git, removed afterwards), builds the
# five single-file editions with the library's own build/single-file.php (--build=<tag>), copies them, the license and
# examples/exponential/*.rules into this extension; then turns the five files into the PHP 7.4 build in
# lib/requestshield/php74/ with Rector (build/rector-php74.php; RECTOR names its binary, required) and runs check and
# test on the extension's default rules with every PHP named in PHPS (default: "$PHP" only).
# Prints PASS or FAIL. Nothing is committed. Then update doc/library.md, ezinfo.php and doc/CHANGELOG.md.
# Copyright (C) 1998 - 2026 Exponential Foundation and 7x. GNU General Public License v2.0 (or any later version), see LICENSE.
set -euo pipefail

[ -n "${RECTOR:-}" ] && [ -x "$RECTOR" ] || { echo "FAIL: RECTOR must name the rector binary (composer require --dev rector/rector in a work directory)"; exit 1; }
clone=${1:?usage: bin/update-library.sh <request-shield clone> <tag>}
tag=${2:?usage: bin/update-library.sh <request-shield clone> <tag>}
ext=$(cd "$(dirname "$0")/.." && pwd)
php=${PHP:-php}

git -C "$clone" rev-parse --verify --quiet "refs/tags/$tag" >/dev/null || { echo "FAIL: no tag $tag in $clone (git fetch --tags)"; exit 1; }
work=$(git -C "$clone" rev-parse --absolute-git-dir)/exp-request-shield-build-$$
trap 'git -C "$clone" worktree remove --force "$work" >/dev/null 2>&1 || true' EXIT
git -C "$clone" worktree add --detach --quiet "$work" "$tag"

for pair in mini:request-shield stats:request-shield-stats api:request-shield-api waf:request-shield-waf cache:request-shield-cache; do
    edition=${pair%%:*}
    file=${pair#*:}.php
    "$php" "$work/build/single-file.php" --edition="$edition" --out="$ext/lib/requestshield/$file" --build="$tag"
done
cp -f "$work/LICENSE" "$ext/lib/requestshield/LICENSE"
for f in exponential.rules exponential-admin-uri.rules exponential-admin-host.rules; do
    cp -f "$work/examples/exponential/$f" "$ext/rules/$f"
done

mkdir -p "$ext/lib/requestshield/php74"
for f in request-shield request-shield-stats request-shield-api request-shield-waf request-shield-cache; do
    cp -f "$ext/lib/requestshield/$f.php" "$ext/lib/requestshield/php74/$f.php"
done
"$php" -d memory_limit=4G "$RECTOR" process "$ext/lib/requestshield/php74" --config "$ext/build/rector-php74.php" --no-progress-bar --no-diffs

"$php" "$ext/bin/request-shield" version
# check exits non-zero for warnings too (the shipped rules have some by design); its "ok:" line means it compiled.
fail=0
for p in ${PHPS:-$php}; do
    checked=$("$p" "$ext/bin/request-shield" check "$ext/rules/request-shield.rules" 2>&1 || true)
    tested=$("$p" "$ext/bin/request-shield" test "$ext/rules/request-shield.rules" 2>&1 || true)
    if grep -q '^ok:' <<<"$checked" && tail -1 <<<"$tested" | grep -q ' pass\.$'; then
        echo "  $("$p" -r 'echo PHP_VERSION;'): check ok, $(tail -1 <<<"$tested")"
    else
        echo "  $p: FAILED"; fail=1
    fi
done
if [ $fail = 0 ]; then
    echo "PASS: lib/requestshield and lib/requestshield/php74 built from $tag; the default rules check and test clean"
else
    echo "FAIL: the default rules do not check or test clean with $tag on every PHP -- run bin/request-shield check/test"
    exit 1
fi
