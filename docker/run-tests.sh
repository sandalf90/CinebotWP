#!/bin/sh
set -eu

suite="${1:-all}"
shift || true
bash docker/prepare-tests.sh

if [ "$suite" = "all" ]; then
    exec vendor/bin/phpunit -c phpunit.xml.dist "$@"
fi

exec vendor/bin/phpunit -c phpunit.xml.dist --testsuite "$suite" "$@"
