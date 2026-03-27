<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Central runtime hardening helpers for session and HTTP response handling.
 */
class RuntimeHardening
{
    /**
     * Build hardening options from configuration data.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function buildOptions(array $config = []): array
    {
        return [
            'session_cookie_path' => self::normalizeString($config['SESSION_COOKIE_PATH'] ?? '/'),
            'session_cookie_domain' => self::normalizeString($config['SESSION_COOKIE_DOMAIN'] ?? ''),
            'session_cookie_samesite' => self::normalizeSameSite($config['SESSION_COOKIE_SAMESITE'] ?? 'Lax'),
            'session_cookie_secure_auto' => self::toBool($config['SESSION_COOKIE_SECURE_AUTO'] ?? true),
            'session_cookie_secure_force' => self::toBool($config['SESSION_COOKIE_SECURE_FORCE'] ?? false),
            'security_headers_enabled' => self::toBool($config['SECURITY_HEADERS_ENABLED'] ?? true),
            'security_frame_options' => self::normalizeString($config['SECURITY_FRAME_OPTIONS'] ?? 'SAMEORIGIN'),
            'security_referrer_policy' => self::normalizeString($config['SECURITY_REFERRER_POLICY'] ?? 'strict-origin-when-cross-origin'),
            'security_use_csp_frame_ancestors' => self::toBool($config['SECURITY_USE_CSP_FRAME_ANCESTORS'] ?? false),
            'security_csp_frame_ancestors' => self::normalizeString($config['SECURITY_CSP_FRAME_ANCESTORS'] ?? "'self'"),
            'security_hsts_enabled' => self::toBool($config['SECURITY_HSTS_ENABLED'] ?? false),
            'security_hsts_max_age' => max(0, (int) ($config['SECURITY_HSTS_MAX_AGE'] ?? 31536000)),
            'security_hsts_include_subdomains' => self::toBool($config['SECURITY_HSTS_INCLUDE_SUBDOMAINS'] ?? false),
            'security_hsts_preload' => self::toBool($config['SECURITY_HSTS_PRELOAD'] ?? false),
            'security_trust_forwarded_proto' => self::toBool($config['SECURITY_TRUST_FORWARDED_PROTO'] ?? false),
            'security_trusted_proxies' => self::normalizeTrustedProxies($config['SECURITY_TRUSTED_PROXIES'] ?? ''),
        ];
    }

    /**
     * Determine whether the current request is HTTPS, including proxy headers.
     */
    public static function isHttpsRequest(array $server, array $options = []): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }

        if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        if ((bool) ($options['security_trust_forwarded_proto'] ?? false)) {
            $forwardedProto = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');
            if ($forwardedProto !== '' && self::isTrustedProxy($server, $options)) {
                $parts = explode(',', $forwardedProto);
                $first = strtolower(trim((string) ($parts[0] ?? '')));
                if ($first === 'https') {
                    return true;
                }
            }
        }

        return strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on';
    }

    /**
     * Build session cookie parameters for session_set_cookie_params().
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function buildSessionCookieParams(array $options, bool $isHttps): array
    {
        $sameSite = self::normalizeSameSite($options['session_cookie_samesite'] ?? 'Lax');
        $secure = (bool) ($options['session_cookie_secure_force'] ?? false);
        if (!$secure && (bool) ($options['session_cookie_secure_auto'] ?? true)) {
            $secure = $isHttps;
        }
        if ($sameSite === 'None') {
            // Browsers reject SameSite=None without Secure, which would cause
            // session cookie drops and login/session loops.
            $secure = true;
        }

        $params = [
            'lifetime' => 0,
            'path' => (string) ($options['session_cookie_path'] ?? '/'),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];

        $domain = trim((string) ($options['session_cookie_domain'] ?? ''));
        if ($domain !== '') {
            $params['domain'] = $domain;
        }

        return $params;
    }

    /**
     * Configure PHP session cookie parameters before session_start().
     *
     * @param array<string, mixed> $options
     */
    public static function configureSessionCookie(array $options, bool $isHttps): void
    {
        if (headers_sent()) {
            return;
        }

        session_set_cookie_params(self::buildSessionCookieParams($options, $isHttps));
    }

    /**
     * Build defensive headers for HTML responses.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    public static function buildHtmlHeaders(array $options, bool $isHttps): array
    {
        $headers = [];

        if (!(bool) ($options['security_headers_enabled'] ?? true)) {
            return $headers;
        }

        if ((bool) ($options['security_use_csp_frame_ancestors'] ?? false)) {
            $headers['Content-Security-Policy'] = "frame-ancestors " . (string) ($options['security_csp_frame_ancestors'] ?? "'self'");
        } else {
            $headers['X-Frame-Options'] = (string) ($options['security_frame_options'] ?? 'SAMEORIGIN');
        }

        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['Referrer-Policy'] = (string) ($options['security_referrer_policy'] ?? 'strict-origin-when-cross-origin');

        if ($isHttps && (bool) ($options['security_hsts_enabled'] ?? false)) {
            $hsts = 'max-age=' . max(0, (int) ($options['security_hsts_max_age'] ?? 31536000));
            if ((bool) ($options['security_hsts_include_subdomains'] ?? false)) {
                $hsts .= '; includeSubDomains';
            }
            if ((bool) ($options['security_hsts_preload'] ?? false)) {
                $hsts .= '; preload';
            }
            $headers['Strict-Transport-Security'] = $hsts;
        }

        return $headers;
    }

    /**
     * Send central hardening headers for HTML responses.
     *
     * @param array<string, mixed> $options
     */
    public static function applyHtmlHeaders(array $options, bool $isHttps): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        foreach (self::buildHtmlHeaders($options, $isHttps) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Regenerate the active session ID for fixation resistance.
     *
     * @return bool True when the ID was regenerated in this request.
     */
    public static function regenerateSessionIdFor(string $reason): bool
    {
        static $regeneratedInRequest = false;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if ($regeneratedInRequest) {
            return false;
        }

        if (!session_regenerate_id(true)) {
            return false;
        }

        if (!isset($_SESSION['__security'])) {
            $_SESSION['__security'] = [];
        }
        $_SESSION['__security']['regenerated_reason'] = $reason;
        $_SESSION['__security']['regenerated_at'] = time();
        $regeneratedInRequest = true;

        return true;
    }

    /**
     * Regenerate when superuser flags increase during the current session.
     */
    public static function regenerateOnPrivilegeElevation(array &$session): bool
    {
        $currentFlags = (int) ($session['user']['superuser'] ?? 0);
        $security = $session['security'] ?? [];
        $previousFlags = (int) ($security['superuser_snapshot'] ?? $currentFlags);

        if (($currentFlags & ~$previousFlags) !== 0) {
            $regenerated = self::regenerateSessionIdFor('privilege-elevation');
            $security['superuser_snapshot'] = $currentFlags;
            $session['security'] = $security;

            return $regenerated;
        }

        $security['superuser_snapshot'] = $currentFlags;
        $session['security'] = $security;

        return false;
    }

    private static function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private static function normalizeSameSite(mixed $value): string
    {
        $sameSite = ucfirst(strtolower(trim((string) $value)));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            return 'Lax';
        }

        return $sameSite;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<string>
     */
    private static function normalizeTrustedProxies(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string) $value);
        }

        $proxies = [];
        foreach ($items as $item) {
            $ip = trim((string) $item);
            if ($ip !== '') {
                $proxies[] = $ip;
            }
        }

        return $proxies;
    }

    /**
     * Determine whether the client IP belongs to a trusted reverse proxy.
     *
     * @param array<string, mixed> $options
     */
    private static function isTrustedProxy(array $server, array $options): bool
    {
        $trustedProxies = $options['security_trusted_proxies'] ?? [];
        if (!is_array($trustedProxies) || $trustedProxies === []) {
            // Trust is explicitly enabled; without a list we accept forwarded
            // proto from any immediate peer.
            return true;
        }

        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return false;
        }

        return in_array($remoteAddr, $trustedProxies, true);
    }
}
