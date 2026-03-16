<?php

declare(strict_types=1);

/**
 * Stateless helper for TOTP and signed recovery-token flows.
 */
class TwoFactorAuthService
{
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function buildOtpAuthUri(
        string $issuer,
        string $accountName,
        string $secret,
        int $digits,
        int $period,
        string $algorithm = 'SHA1'
    ): string {
        $label = rawurlencode($issuer . ':' . $accountName);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer),
            rawurlencode(strtoupper($algorithm)),
            $digits,
            $period
        );
    }

    public static function generateTokenAtTime(string $secret, int $digits, int $period, int $timestamp): string
    {
        $step = intdiv($timestamp, $period);

        return self::generateTotpForStep($secret, $digits, $step);
    }

    /**
     * @return array{valid:bool,timestep:int,reason:string}
     */
    public static function verifyTotp(
        string $secret,
        string $token,
        int $digits,
        int $period,
        int $window,
        int $lastUsedTimeStep,
        ?int $now = null
    ): array {
        $now ??= time();
        $token = trim($token);

        if ($token === '' || !ctype_digit($token) || strlen($token) !== $digits) {
            return ['valid' => false, 'timestep' => -1, 'reason' => 'format'];
        }

        $currentStep = intdiv($now, $period);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($step <= $lastUsedTimeStep) {
                continue;
            }

            if (hash_equals(self::generateTotpForStep($secret, $digits, $step), $token)) {
                return ['valid' => true, 'timestep' => $step, 'reason' => 'ok'];
            }
        }

        if ($lastUsedTimeStep >= ($currentStep - $window) && $lastUsedTimeStep <= ($currentStep + $window)) {
            return ['valid' => false, 'timestep' => -1, 'reason' => 'replay'];
        }

        return ['valid' => false, 'timestep' => -1, 'reason' => 'mismatch'];
    }

    public static function signDisableToken(int $acctId, string $email, int $expiresAt, string $signingKey): string
    {
        $payload = json_encode([
            'acctid' => $acctId,
            'email' => strtolower(trim($email)),
            'exp' => $expiresAt,
        ], JSON_THROW_ON_ERROR);

        $payloadEncoded = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $payloadEncoded, $signingKey, true);

        return $payloadEncoded . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @return array{valid:bool,acctid:int,email:string,exp:int}
     */
    public static function verifyDisableToken(string $token, string $signingKey, ?int $now = null): array
    {
        $now ??= time();
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return ['valid' => false, 'acctid' => 0, 'email' => '', 'exp' => 0];
        }

        [$payloadEncoded, $signatureEncoded] = $parts;
        $rawSignature = self::base64UrlDecode($signatureEncoded);
        if ($rawSignature === '') {
            return ['valid' => false, 'acctid' => 0, 'email' => '', 'exp' => 0];
        }

        $expected = hash_hmac('sha256', $payloadEncoded, $signingKey, true);
        if (!hash_equals($expected, $rawSignature)) {
            return ['valid' => false, 'acctid' => 0, 'email' => '', 'exp' => 0];
        }

        $payloadJson = self::base64UrlDecode($payloadEncoded);
        if ($payloadJson === '') {
            return ['valid' => false, 'acctid' => 0, 'email' => '', 'exp' => 0];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'acctid' => 0, 'email' => '', 'exp' => 0];
        }

        $acctId = (int) ($payload['acctid'] ?? 0);
        $email = (string) ($payload['email'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);

        if ($acctId < 1 || $email === '' || $exp < $now) {
            return ['valid' => false, 'acctid' => $acctId, 'email' => $email, 'exp' => $exp];
        }

        return ['valid' => true, 'acctid' => $acctId, 'email' => $email, 'exp' => $exp];
    }


    /**
     * Build a QR-code provider URL for an otpauth payload.
     */
    public static function buildQrCodeUrl(string $endpoint, string $payload, int $size = 220): string
    {
        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $query = http_build_query([
            'size' => sprintf('%dx%d', $size, $size),
            'data' => $payload,
        ]);

        return rtrim($endpoint) . $separator . $query;
    }

    public static function encryptSecret(string $secret, string $key): string
    {
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt($secret, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
            if (is_string($ciphertext)) {
                return 'enc:' . self::base64UrlEncode($iv . $ciphertext);
            }
        }

        return 'plain:' . self::base64UrlEncode($secret);
    }

    public static function decryptSecret(string $storedSecret, string $key): string
    {
        if (str_starts_with($storedSecret, 'enc:') && function_exists('openssl_decrypt')) {
            $raw = self::base64UrlDecode(substr($storedSecret, 4));
            if (strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $ciphertext = substr($raw, 16);
                $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
                if (is_string($decrypted)) {
                    return $decrypted;
                }
            }
        }

        if (str_starts_with($storedSecret, 'plain:')) {
            return self::base64UrlDecode(substr($storedSecret, 6));
        }

        return '';
    }

    /**
     * Check whether a request URI matches at least one allowed route.
     *
     * Matching is path-aware and query-parameter-aware to tolerate
     * parameter ordering/encoding differences across requests.
     *
     * Path checks are normalized to tolerate:
     * - leading-slash variations (`/async/process.php` vs `async/process.php`)
     * - deployment subdirectory prefixes (`/lotgd/async/process.php`)
     *
     * @param array<int, string> $allowed
     */
    public static function isUriAllowed(string $requestUri, array $allowed): bool
    {
        $requestPath = ltrim((string) parse_url($requestUri, PHP_URL_PATH), '/');
        $requestQuery = (string) parse_url($requestUri, PHP_URL_QUERY);
        $requestParams = [];
        parse_str($requestQuery, $requestParams);

        foreach ($allowed as $uri) {
            $allowedPath = ltrim((string) parse_url($uri, PHP_URL_PATH), '/');
            if ($allowedPath !== '' && $allowedPath !== $requestPath) {
                // Accept installations hosted in a subdirectory by matching script basename.
                $allowedBase = basename($allowedPath);
                $requestBase = basename($requestPath);
                if ($allowedBase === '' || $requestBase === '' || $allowedBase !== $requestBase) {
                    continue;
                }
            }

            $allowedQuery = (string) parse_url($uri, PHP_URL_QUERY);
            if ($allowedQuery === '') {
                return true;
            }

            $allowedParams = [];
            parse_str($allowedQuery, $allowedParams);

            $matched = true;
            foreach ($allowedParams as $key => $value) {
                if (!array_key_exists($key, $requestParams) || (string) $requestParams[$key] !== (string) $value) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function buildAllowedChallengeNavs(?string $confirmDisableUri = null): array
    {
        $allowed = [
            'runmodule.php?module=twofactorauth&op=challenge',
            'runmodule.php?module=twofactorauth&op=verify',
            // During a pending challenge, Jaxon transport must still reach async/process.php.
            // Redirecting these requests to the challenge page returns HTML instead of JSON,
            // which breaks the browser-side parser for passkey/challenge async flows.
            'async/process.php',
            'runmodule.php?module=twofactorauth&op=begin_passkey_auth',
            'runmodule.php?module=twofactorauth&op=verify_passkey',
            'runmodule.php?module=twofactorauth&op=disable_email',
            'login.php?op=logout',
        ];

        if (is_string($confirmDisableUri) && $confirmDisableUri !== '') {
            $allowed[] = $confirmDisableUri;
        }

        return $allowed;
    }

    private static function generateTotpForStep(string $secret, int $digits, int $step): string
    {
        $binarySecret = self::base32Decode($secret);
        if ($binarySecret === '') {
            return str_repeat('0', $digits);
        }

        $stepBytes = pack('N*', 0) . pack('N*', $step);
        $hmac = hash_hmac('sha1', $stepBytes, $binarySecret, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $value = unpack('N', substr($hmac, $offset, 4))[1] & 0x7fffffff;
        $mod = 10 ** $digits;

        return str_pad((string) ($value % $mod), $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
        if ($encoded === '') {
            return '';
        }

        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        foreach (str_split($encoded) as $char) {
            if (!isset($alphabet[$char])) {
                return '';
            }
            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }
}
