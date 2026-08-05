#!/bin/sh
set -eu

target=/tmp/wordpress-develop
version="${WP_VERSION:-6.0.12}"
current=""

if [ -f "$target/.cinebot-wp-version" ]; then
    current="$(cat "$target/.cinebot-wp-version")"
fi

if [ "$current" != "$version" ]; then
    mkdir -p "$target"
    find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    git clone --depth 1 --branch "$version" \
        https://github.com/WordPress/wordpress-develop.git "$target"
    printf '%s' "$version" > "$target/.cinebot-wp-version"
fi

test -f "$target/tests/phpunit/includes/bootstrap.php"
