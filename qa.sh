#!/usr/bin/env bash
#
# Pre-push gate. Run from the repository root:  ./qa.sh
#
# Everything runs inside the phpfpm container. Exits non-zero if any check fails, so it can
# be wired into a git pre-push hook or CI unchanged.

set -uo pipefail

MODULE_ROOT="app/code/Goodahead"
PHPSTAN_CONFIG="${MODULE_ROOT}/PaymentTiers/phpstan.neon"

bold=$'\033[1m'; red=$'\033[31m'; green=$'\033[32m'; off=$'\033[0m'
failed=()

run() {
    local name="$1"; shift
    printf '\n%s== %s ==%s\n' "$bold" "$name" "$off"

    if bin/cli sh -c "$*"; then
        printf '%sPASS%s  %s\n' "$green" "$off" "$name"
    else
        printf '%sFAIL%s  %s\n' "$red" "$off" "$name"
        failed+=("$name")
    fi
}

# Errors only. The Magento2 standard's docblock warnings fire on typed accessors and are
# carried by Magento core itself at a higher density than by this module; see
# docs/verification/tests-and-coding-standards.md.
run "Coding standards (Magento2)" \
    "vendor/bin/phpcs --standard=Magento2 --warning-severity=0 ${MODULE_ROOT} 2>/dev/null"

run "Static analysis (PHPStan, level in ${PHPSTAN_CONFIG})" \
    "vendor/bin/phpstan analyse --no-progress -c ${PHPSTAN_CONFIG}"

# --no-extensions: this installation ships Allure without its config file, so the extension
# fails to bootstrap and turns a passing run into exit code 1. Disabling extensions drops
# reporting we do not use; it does not skip a single test.
run "Unit tests" \
    "vendor/bin/phpunit --no-extensions -c dev/tests/unit/phpunit.xml.dist --testsuite 'Magento_Unit_Tests_App_Code' --filter 'Goodahead'"

# Two rules the task's Definition of Done states outright. Cheap to check, expensive to
# discover during review.
printf '\n%s== Definition of Done invariants ==%s\n' "$bold" "$off"
dod_ok=1

if grep -rn "ObjectManager" "src/${MODULE_ROOT}" --include='*.php' | grep -v '/Test/' ; then
    printf '%sFAIL%s  ObjectManager used in module code\n' "$red" "$off"
    dod_ok=0
fi

if grep -rn "<preference" "src/${MODULE_ROOT}" --include='*.xml' ; then
    printf '%sFAIL%s  preference used instead of a plugin\n' "$red" "$off"
    dod_ok=0
fi

if [ "$dod_ok" -eq 1 ]; then
    printf '%sPASS%s  no ObjectManager, no preference\n' "$green" "$off"
else
    failed+=("Definition of Done invariants")
fi

printf '\n%s========================%s\n' "$bold" "$off"

if [ ${#failed[@]} -eq 0 ]; then
    printf '%sAll checks passed.%s\n' "$green" "$off"
    exit 0
fi

printf '%sFailed:%s %s\n' "$red" "$off" "${failed[*]}"
exit 1
