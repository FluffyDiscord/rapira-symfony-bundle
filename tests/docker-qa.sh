#!/usr/bin/env bash
# Runs the bundle's static analysis and unit tests inside a pinned PHP container,
# never on the host. Dependencies are baked into the QA image; the working tree's
# source is mounted so analysis and tests run without reinstalling on each run.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="rapira-symfony-bundle-qa"

docker build -q -f "$ROOT/tests/docker/qa.Dockerfile" -t "$IMAGE" "$ROOT" >/dev/null

docker run --rm \
    -v "$ROOT/src":/app/src:ro \
    -v "$ROOT/config":/app/config:ro \
    -v "$ROOT/tests":/app/tests:ro \
    -v "$ROOT/phpstan":/app/phpstan:ro \
    -v "$ROOT/phpstan.neon":/app/phpstan.neon:ro \
    -v "$ROOT/phpunit.xml.dist":/app/phpunit.xml.dist:ro \
    "$IMAGE" bash -c '
        set -e
        echo "=== PHPStan (level max) ==="
        php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
        echo "=== PHPUnit ==="
        php vendor/bin/phpunit
    '
