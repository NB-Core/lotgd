#!/bin/sh
set -eu

# PHP performs the loopback request so the production image needs no HTTP CLI.
php -r '
    $result = file_get_contents("http://127.0.0.1/_internal/ready");
    $status = $http_response_header[0] ?? "";
    exit($result === "" && str_contains($status, " 204 ") ? 0 : 1);
' >/dev/null 2>&1
