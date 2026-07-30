#!/usr/bin/env bash
#
# Docker-based QA runner. This host has no local PHP/Composer — everything runs in
# containers. Dev tooling installs into dev/vendor (git-ignored, never shipped).
#
#   dev/qa.sh install    # install dev tooling into dev/vendor
#   dev/qa.sh test        # PHPUnit (real PHP 7.4, our floor)
#   dev/qa.sh lint        # PHPCS  (PSR-12 + WP security sniffs + PHP 7.4 compat)
#   dev/qa.sh fix         # PHPCBF (auto-fix what PHPCS can)
#   dev/qa.sh analyze     # PHPStan
#   dev/qa.sh all         # lint + analyze + test
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_IMAGE="wordpress:cli-php7.4"   # PHP 7.4 (floor) with dom/mbstring/xml/zip/… preinstalled
COMPOSER_IMAGE="composer:2"

run_php() {
    docker run --rm -v "$REPO":/plugin -w /plugin --user root \
        -e HOME=/tmp --entrypoint php "$PHP_IMAGE" "$@"
}

cmd="${1:-}"; shift || true
case "$cmd" in
    install)
        docker run --rm -v "$REPO":/plugin -w /plugin/dev \
            -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp \
            "$COMPOSER_IMAGE" install --no-interaction --no-progress "$@"
        ;;
    test)    run_php dev/vendor/bin/phpunit -c phpunit.xml.dist "$@" ;;
    lint)    run_php dev/vendor/bin/phpcs   -p "$@" ;;
    fix)     run_php dev/vendor/bin/phpcbf  "$@" ;;
    analyze) run_php dev/vendor/bin/phpstan analyse --no-progress "$@" ;;
    all)
        "${BASH_SOURCE[0]}" lint
        "${BASH_SOURCE[0]}" analyze
        "${BASH_SOURCE[0]}" test
        ;;
    *)
        echo "usage: dev/qa.sh {install|test|lint|fix|analyze|all}" >&2
        exit 1
        ;;
esac
