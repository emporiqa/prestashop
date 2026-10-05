#!/usr/bin/env bash
# Runs every module test: tests/*Test.php with PHP, tests/*Test.js with Node.
# No PrestaShop, PHPUnit or Composer needed. Exits non-zero if any test fails.
#
# Usage: tests/run.sh            (from anywhere)
#        PHP=php8.0 tests/run.sh (pick the PHP binary)
set -u

cd "$(dirname "$0")/.." || exit 1
PHP="${PHP:-php}"
NODE="${NODE:-node}"

failed=()
ran=0
for test in tests/*Test.php tests/*Test.js; do
    [ -e "$test" ] || continue
    case "$test" in
        *.php) runner="$PHP" ;;
        *.js) runner="$NODE" ;;
    esac
    echo "== $test"
    if ! "$runner" "$test"; then
        failed+=("$test")
    fi
    ran=$((ran + 1))
    echo
done

if [ "${#failed[@]}" -gt 0 ]; then
    echo "${#failed[@]} of $ran test file(s) FAILED: ${failed[*]}"
    exit 1
fi
echo "All $ran test files passed"
