<?php

declare(strict_types=1);

/**
 * Stateless helper for TOTP and signed recovery-token flows.
 */
class TwoFactorAuthService
{
    /**
     * The authenticated at-rest format.
     *
     * `enc2:` is base64url(iv || tag || ciphertext) under aes-256-gcm. The tag
     * is what the format exists for: `enc:` is aes-256-cbc with nothing to
     * verify, so openssl_decrypt() there rejects a wrong key only when the
     * final block's PKCS#7 padding comes out invalid -- about 255 times in 256,
     * and the 256th returns bytes a caller cannot tell from a secret. That is
     * not a theoretical hole: it locked roughly one account in 262 out of 2FA
     * permanently, because the blob and the keys are fixed per account, so the
     * collision either happens for you every time or never. See #1547.
     *
     * With a tag, a wrong key fails. Forging one is 2^-128, not 2^-8.
     */
    private const AEAD_CIPHER = 'aes-256-gcm';
    private const AEAD_PREFIX = 'enc2:';
    private const AEAD_IV_BYTES = 12;
    private const AEAD_TAG_BYTES = 16;

    /**
     * The legacy unauthenticated format, still read and never written.
     */
    private const LEGACY_PREFIX = 'enc:';

    /**
     * The no-openssl fallback, which does not consult the key at all.
     */
    private const PLAIN_PREFIX = 'plain:';

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

    /**
     * Whether this installation can write the authenticated format.
     *
     * aes-256-gcm needs both openssl and a build that offers the mode, so it is
     * asked rather than assumed -- a build without it must keep working, on the
     * older format, instead of failing to store a secret at all.
     */
    public static function supportsAuthenticatedStorage(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $supported = function_exists('openssl_encrypt')
                && function_exists('openssl_decrypt')
                // Asked separately rather than assumed to come with the other
                // two: disable_functions takes a list, so an installation can
                // and does disable them one at a time. Without this, an
                // installation that had disabled only this one fatalled here --
                // on 2FA setup and on every successful verification of a legacy
                // secret, since needsReencryption() asks the same question.
                // Reproduced with `php -d disable_functions=openssl_get_cipher_methods`.
                // Reported by Codex.
                && function_exists('openssl_get_cipher_methods')
                && in_array(self::AEAD_CIPHER, openssl_get_cipher_methods(), true);
        }

        return $supported;
    }

    /**
     * Whether a stored secret should be rewritten in a better format.
     *
     * The policy lives here rather than in the caller's boolean, because the
     * caller had to know the prefixes to ask it and there is now more than one
     * older format to know about. A blob already in the best format this
     * installation can write needs nothing; anything else is rewritten the next
     * time its owner verifies successfully, which is how `plain:` and the
     * legacy key have always been migrated.
     */
    public static function needsReencryption(string $storedSecret): bool
    {
        if (self::supportsAuthenticatedStorage()) {
            return !str_starts_with($storedSecret, self::AEAD_PREFIX);
        }

        if (function_exists('openssl_encrypt')) {
            return !str_starts_with($storedSecret, self::LEGACY_PREFIX);
        }

        return false;
    }

    public static function encryptSecret(string $secret, string $key): string
    {
        if (self::supportsAuthenticatedStorage()) {
            $iv = random_bytes(self::AEAD_IV_BYTES);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $secret,
                self::AEAD_CIPHER,
                self::aeadKey($key),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::AEAD_TAG_BYTES
            );

            if (is_string($ciphertext) && strlen($tag) === self::AEAD_TAG_BYTES) {
                return self::AEAD_PREFIX . self::base64UrlEncode($iv . $tag . $ciphertext);
            }
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt($secret, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
            if (is_string($ciphertext)) {
                return self::LEGACY_PREFIX . self::base64UrlEncode($iv . $ciphertext);
            }
        }

        return self::PLAIN_PREFIX . self::base64UrlEncode($secret);
    }

    public static function decryptSecret(string $storedSecret, string $key): string
    {
        if (str_starts_with($storedSecret, self::AEAD_PREFIX) && self::supportsAuthenticatedStorage()) {
            $raw = self::base64UrlDecode(substr($storedSecret, strlen(self::AEAD_PREFIX)));
            if (strlen($raw) > self::AEAD_IV_BYTES + self::AEAD_TAG_BYTES) {
                $decrypted = openssl_decrypt(
                    substr($raw, self::AEAD_IV_BYTES + self::AEAD_TAG_BYTES),
                    self::AEAD_CIPHER,
                    self::aeadKey($key),
                    OPENSSL_RAW_DATA,
                    substr($raw, 0, self::AEAD_IV_BYTES),
                    substr($raw, self::AEAD_IV_BYTES, self::AEAD_TAG_BYTES)
                );

                if (is_string($decrypted)) {
                    return $decrypted;
                }
            }

            // A tag that does not verify is a wrong key or a tampered blob, and
            // both mean this value is not readable. Falling through to the
            // other formats would only ask them about bytes that are not
            // theirs.
            return '';
        }

        if (str_starts_with($storedSecret, self::LEGACY_PREFIX) && function_exists('openssl_decrypt')) {
            $raw = self::base64UrlDecode(substr($storedSecret, strlen(self::LEGACY_PREFIX)));
            if (strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $ciphertext = substr($raw, 16);
                $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
                if (is_string($decrypted)) {
                    return $decrypted;
                }
            }
        }

        if (str_starts_with($storedSecret, self::PLAIN_PREFIX)) {
            return self::base64UrlDecode(substr($storedSecret, strlen(self::PLAIN_PREFIX)));
        }

        return '';
    }

    /**
     * The key the authenticated format uses, separated from the legacy one.
     *
     * The signing key is the same value either way; deriving a distinct subkey
     * for this cipher keeps one key from being used under two modes, which is
     * cheap here and is the kind of thing that is awkward to change later. The
     * info string names the format, so a third one would get its own.
     *
     * hash_hkdf() rejects an empty key, and an empty signing key is a
     * configuration this module already treats as "no key" -- the compat list
     * filters those out -- so it is answered with a value that decrypts nothing
     * rather than with an exception from inside a crypto helper.
     */
    private static function aeadKey(string $key): string
    {
        if ($key === '') {
            return str_repeat("\0", 32);
        }

        return hash_hkdf('sha256', $key, 32, 'lotgd-2fa-secret-v2');
    }

    /**
     * Whether a decrypted value can be a TOTP secret at all.
     *
     * decryptSecret() cannot always tell a wrong key from a right one, and this
     * is what a caller asks when it cannot.
     *
     * For `enc2:` it can: the tag either verifies or it does not, so a wrong
     * key yields '' and this check has nothing left to decide. The check is
     * still here because `enc:` exists on disk. That format is aes-256-cbc with
     * no tag, and openssl_decrypt() fails only when the final block's PKCS#7
     * padding is invalid -- which random bytes satisfy about once in 255. So a
     * blob encrypted under one key "decrypts" under another roughly 0.4% of the
     * time, into garbage that is not empty and is therefore indistinguishable
     * from a secret to any caller testing `!== ''`. Measured over 300000
     * secrets: 1176 of them, 0.392%.
     *
     * Nothing writes `enc:` any more, and nothing sweeps it either: the
     * migration happens when an account next verifies, so how long the last one
     * survives is a question about players, not about releases. Removing this
     * check on a schedule would therefore be removing it on a guess.
     *
     * That is what this answers, and it is a caller's question rather than
     * decryptSecret()'s, because a caller with a second key to try wants to try
     * it while a caller with only one wants to give up.
     *
     * The character class is deliberately wider than what generateSecret()
     * emits (upper-case base32, no padding). A stored secret carrying the
     * grouping spaces authenticator apps display, or the padding another
     * implementation wrote, must not be locked out by a check meant to keep
     * people in -- and width costs nothing here: the values this rejects are 47
     * random bytes, so even a class of 128 characters rejects them with
     * probability 1 - 2^-47. Over the same 300000 measured above, neither this
     * class nor a strict upper-case one let a single garbage decryption
     * through, and neither rejected a single real secret.
     *
     * Lower case is in the class but decides nothing, and that is worth saying
     * because it is easy to read the other way: base32Decode() strips before it
     * uppercases -- `strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded))` --
     * so lower-case letters are removed and only the digits 2-7 survive. A
     * lower-case secret has therefore never produced the right token in this
     * codebase, with or without this check, and what the second half answers
     * for one is incidental. Left in the class rather than excluded so that the
     * day base32Decode() normalises case first, this follows it.
     *
     * The second half is not redundant with the first. base32Decode() strips
     * everything outside its alphabet, so a value made only of padding,
     * whitespace and dashes passes the character class and then decodes to
     * nothing -- and a value that decodes to nothing cannot produce a token,
     * which is the whole question here. Accepting one would skip the legacy key
     * in exactly the case this check exists to catch. Asking the decoder is
     * also the honest way to put it: what makes a value plausible is that the
     * code which consumes it gets something out of it.
     * Reported by Copilot.
     */
    public static function isPlausibleSecret(string $secret): bool
    {
        return preg_match('/^[A-Za-z2-7=\s-]+$/', $secret) === 1
            && self::base32Decode($secret) !== '';
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
