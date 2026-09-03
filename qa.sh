#!/usr/bin/env bash
#
# Pre-push gate for both Goodahead modules.  ./qa.sh
#
# Runs from wherever this folder is installed inside a Magento tree; it locates the Magento
# root by walking up. Exits non-zero if any check fails, so it wires into a pre-push hook or
# CI unchanged.

set -uo pipefail

MODULE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

MAGENTO_ROOT="${MODULE_DIR}"
while [ "${MAGENTO_ROOT}" != "/" ] && [ ! -f "${MAGENTO_ROOT}/app/etc/di.xml" ]; do
    MAGENTO_ROOT=$(dirname "${MAGENTO_ROOT}")
done

if [ "${MAGENTO_ROOT}" = "/" ]; then
    echo "Not inside a Magento installation: no app/etc/di.xml above ${MODULE_DIR}." >&2
    exit 2
fi

if [ ! -x "${MAGENTO_ROOT}/vendor/bin/phpcs" ]; then
    echo "No PHP tooling at ${MAGENTO_ROOT}/vendor/bin." >&2
    echo "Run this where the Magento vendor tree is — inside the container, for a Docker setup." >&2
    exit 2
fi

MODULE_REL="${MODULE_DIR#"${MAGENTO_ROOT}"/}"

# The two modules by name, not the whole folder: docs/verification ships throwaway CLI
# scripts that legitimately echo, exit and reach for the ObjectManager.
MODULES="${MODULE_REL}/PaymentTiers ${MODULE_REL}/OrderSync"
MODULE_DIRS="${MODULE_DIR}/PaymentTiers ${MODULE_DIR}/OrderSync"
cd "${MAGENTO_ROOT}" || exit 2

bold=$'\033[1m'; red=$'\033[31m'; green=$'\033[32m'; off=$'\033[0m'
failed=()

run() {
    local name="$1"; shift
    printf '\n%s== %s ==%s\n' "$bold" "$name" "$off"

    if sh -c "$*"; then
        printf '%sPASS%s  %s\n' "$green" "$off" "$name"
    else
        printf '%sFAIL%s  %s\n' "$red" "$off" "$name"
        failed+=("$name")
    fi
}

# Errors only. The Magento2 standard's docblock warnings fire on typed accessors and are
# carried by Magento core itself at a higher density than by these modules; see
# docs/verification/tests-and-coding-standards.md.
run "Coding standards (Magento2)" \
    "vendor/bin/phpcs --standard=Magento2 --warning-severity=0 ${MODULES} 2>/dev/null"

run "Static analysis (PHPStan level 8)" \
    "vendor/bin/phpstan analyse --no-progress -c ${MODULE_REL}/phpstan.neon"

# --no-extensions: an installation shipping Allure without its config file fails to bootstrap
# the extension and turns a passing run into exit code 1. This drops reporting we do not use;
# it does not skip a single test.
run "Unit tests" \
    "vendor/bin/phpunit --no-extensions -c dev/tests/unit/phpunit.xml.dist --testsuite 'Magento_Unit_Tests_App_Code' --filter 'Goodahead'"

# Two rules the task's Definition of Done states outright. Cheap to check, expensive to
# discover during review.
printf '\n%s== Definition of Done invariants ==%s\n' "$bold" "$off"
dod_ok=1

if grep -rn "ObjectManager" ${MODULE_DIRS} --include='*.php' | grep -v '/Test/' ; then
    printf '%sFAIL%s  ObjectManager used in module code\n' "$red" "$off"
    dod_ok=0
fi

# The Definition of Done bans a preference "overriding a core or Stripe class where a plugin
# or documented extension point exists". Binding our own Api\Data interface to its own
# implementation is the ordinary way to declare a data type and is not what that forbids.
if grep -rn "<preference" ${MODULE_DIRS} --include='*.xml' | grep -v 'for="Goodahead' ; then
    printf '%sFAIL%s  preference overriding a class outside these modules\n' "$red" "$off"
    dod_ok=0
fi

if [ "$dod_ok" -eq 1 ]; then
    printf '%sPASS%s  no ObjectManager, no foreign preference\n' "$green" "$off"
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
