<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

/**
 * The unauthenticated at-rest format for 2FA secrets, which only tests write.
 *
 * `enc:` is aes-256-cbc with no authentication tag. TwoFactorAuthService stopped
 * writing it when `enc2:` arrived and still reads it, because a stored secret is
 * migrated when its owner next verifies -- so how long the last `enc:` blob
 * survives is a question about players, not about releases.
 *
 * That leaves the tests for the reading path with nothing to build a fixture
 * from, and this is the honest answer to it: the format is written out here,
 * once, where a reader can see exactly what the production code is being asked
 * to cope with. The alternative -- a test-only branch in the service -- would
 * put a way to write the weak format back into the shipped code to prove that
 * the shipped code no longer writes it.
 *
 * Every fixture built here is checked against decryptSecret() with its own key
 * before it is used, because a helper that drifted from the format would leave
 * those tests passing while exercising nothing.
 */
final class LegacyTwoFactorSecret
{
    /**
     * The cipher, the key derivation and the layout the legacy format used.
     *
     * Key derivation is a plain sha256 of the signing key, not the HKDF the
     * authenticated format uses -- deliberately, because this reproduces what is
     * on disk rather than what anyone would write today.
     */
    public static function encrypt(string $secret, string $key): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($secret, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('aes-256-cbc is unavailable, so no legacy fixture can be built');
        }

        return 'enc:' . rtrim(strtr(base64_encode($iv . $ciphertext), '+/', '-_'), '=');
    }

    /**
     * A legacy blob that the *wrong* key also decrypts into something non-empty.
     *
     * The collision is searched for rather than hard-coded: a constant would be
     * tied to whichever keys the harness happens to derive, and would quietly
     * stop exercising anything the day one of them changed. It is found in a few
     * hundred attempts on average -- the rate is about 1 in 255, since a wrong
     * key is refused only when the final block's PKCS#7 padding comes out
     * invalid.
     *
     * Returns null if the bound is exhausted, so a caller can say what that
     * means for the test it is in rather than getting an exception from here.
     *
     * @return array{secret: string, blob: string, garbage: string}|null
     */
    public static function findWrongKeyCollision(string $key, string $wrongKey, int $attempts = 20000): ?array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $secret = \TwoFactorAuthService::generateSecret();
            $blob = self::encrypt($secret, $key);
            $garbage = \TwoFactorAuthService::decryptSecret($blob, $wrongKey);

            if ($garbage !== '') {
                return ['secret' => $secret, 'blob' => $blob, 'garbage' => $garbage];
            }
        }

        return null;
    }
}
