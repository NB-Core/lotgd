#!/bin/sh
set -eu

# The application image must only assemble pre-built runtime artifacts. Keep
# compilation in the upstream runtime image and out of ordinary application CI.
forbidden='docker-php-ext-(install|configure)|(^|[^[:alnum:]_-])phpize([^[:alnum:]_-]|$)|(^|[[:space:]])make([[:space:]]|$)|(^|[[:space:]])(gcc|g[+][+]|clang|build-essential|autoconf)([[:space:]\\]|$)'

if grep -En "$forbidden" Dockerfile; then
    echo "Dockerfile must not install or invoke a PHP/native build toolchain" >&2
    exit 1
fi

echo "Dockerfile contains no native extension build toolchain"
