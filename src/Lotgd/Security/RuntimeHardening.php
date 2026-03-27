<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Runtime security helpers used by request hardening checks.
 */
final class RuntimeHardening
{
    /**
     * Determine whether a request should be treated as HTTPS.
     *
     * This API is option-driven so callers can explicitly control whether
     * forwarded proxy headers are trusted.
     *
     * Supported options:
     * - SECURITY_TRUST_FORWARDED_PROTO (bool)
     * - SECURITY_TRUSTED_PROXIES (comma-separated exact IP list)
     *
     * @param array<string, mixed> $server  Request server map (typically $_SERVER).
     * @param array<string, mixed> $options Runtime hardening options.
     */
    public static function isHttpsRequest(array $server, array $options = []): bool
    {
        $trustForwarded = !empty($options['SECURITY_TRUST_FORWARDED_PROTO']);

        if ($trustForwarded && !self::isTrustedProxy($server, $options)) {
            $trustForwarded = false;
        }

        if ($trustForwarded) {
            $proto = self::extractForwardedProto($server);
            if ($proto === 'https') {
                return true;
            }

            $forwardedSsl = strtolower(trim((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')));
            if ($forwardedSsl === 'on') {
                return true;
            }

            $frontEndHttps = strtolower(trim((string) ($server['HTTP_FRONT_END_HTTPS'] ?? '')));
            if ($frontEndHttps === 'on') {
                return true;
            }

            $requestScheme = strtolower(trim((string) ($server['REQUEST_SCHEME'] ?? '')));
            if ($requestScheme === 'https') {
                return true;
            }
        }

        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));

        return ($https !== '' && $https !== 'off' && $https !== '0')
            || (($server['SERVER_PORT'] ?? 80) == 443);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $options
     */
    private static function isTrustedProxy(array $server, array $options): bool
    {
        $trustedProxies = trim((string) ($options['SECURITY_TRUSTED_PROXIES'] ?? ''));
        if ($trustedProxies === '') {
            return true;
        }

        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            // CLI/test contexts may not include REMOTE_ADDR.
            return PHP_SAPI === 'cli';
        }

        $allowed = array_map('trim', explode(',', $trustedProxies));
        $allowed = array_filter($allowed, static fn (string $ip): bool => $ip !== '');

        return in_array($remoteAddr, $allowed, true);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function extractForwardedProto(array $server): string
    {
        $candidate = (string) (
            $server['HTTP_X_FORWARDED_PROTO']
            ?? $server['X_FORWARDED_PROTO']
            ?? $server['HTTP_X_FORWARDED_PROTOCOL']
            ?? $server['HTTP_FORWARDED_PROTO']
            ?? $server['FORWARDED_PROTO']
            ?? $server['HTTP_X_URL_SCHEME']
            ?? ''
        );

        if ($candidate !== '') {
            return strtolower(trim(explode(',', $candidate)[0]));
        }

        $forwarded = (string) ($server['HTTP_FORWARDED'] ?? '');
        if ($forwarded === '') {
            return '';
        }

        if (preg_match('/(?:^|[;,\\s])proto=([^;,\\s]+)/i', $forwarded, $match) !== 1) {
            return '';
        }

        return strtolower(trim($match[1], " \t\n\r\0\x0B\"'"));
    }
}
