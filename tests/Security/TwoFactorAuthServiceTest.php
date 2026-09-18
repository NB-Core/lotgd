<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/TwoFactorAuth/TwoFactorAuthService.php';

class TwoFactorAuthServiceTest extends TestCase
{
    public function testSetupEnableFlowAcceptsValidToken(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;
        $token = \TwoFactorAuthService::generateTokenAtTime($secret, 6, 30, $now);

        $result = \TwoFactorAuthService::verifyTotp($secret, $token, 6, 30, 1, 0, $now);

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertGreaterThan(0, $result['timestep']);
    }

    public function testIncorrectTokenIsRejected(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;

        $result = \TwoFactorAuthService::verifyTotp($secret, '000000', 6, 30, 1, 0, $now);

        $this->assertFalse($result['valid']);
    }

    public function testReplayTokenIsRejectedUsingLastUsedStep(): void
    {
        $secret = \TwoFactorAuthService::generateSecret();
        $now = 1700000000;
        $token = \TwoFactorAuthService::generateTokenAtTime($secret, 6, 30, $now);
        $step = intdiv($now, 30);

        $result = \TwoFactorAuthService::verifyTotp($secret, $token, 6, 30, 1, $step, $now);

        $this->assertFalse($result['valid']);
    }

    public function testNavigationLockWhitelist(): void
    {
        $allowed = \TwoFactorAuthService::buildAllowedChallengeNavs('runmodule.php?module=twofactorauth&op=confirm_disable&token=abc');

        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?module=twofactorauth&op=challenge', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?module=twofactorauth&op=challenge&c=1', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('runmodule.php?op=challenge&module=twofactorauth&c=1', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('async/process.php', $allowed));
        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('/async/process.php', $allowed));
        $this->assertFalse(\TwoFactorAuthService::isUriAllowed('village.php', $allowed));
    }

    public function testDisableTokenValidationAndExpiry(): void
    {
        $token = \TwoFactorAuthService::signDisableToken(123, 'test@example.com', 1700000300, 'secret-key');

        $valid = \TwoFactorAuthService::verifyDisableToken($token, 'secret-key', 1700000000);
        $expired = \TwoFactorAuthService::verifyDisableToken($token, 'secret-key', 1700000400);

        $this->assertTrue($valid['valid']);
        $this->assertSame(123, $valid['acctid']);
        $this->assertFalse($expired['valid']);
    }

    public function testQrCodeUrlBuilderIncludesPayloadAndSize(): void
    {
        $url = \TwoFactorAuthService::buildQrCodeUrl(
            'https://api.qrserver.com/v1/create-qr-code/',
            'otpauth://totp/Example',
            180
        );

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('180x180', $query['size'] ?? null);
        $this->assertSame('otpauth://totp/Example', $query['data'] ?? null);
    }

    public function testNavigationLockWhitelistAcceptsSubdirectoryPath(): void
    {
        $allowed = \TwoFactorAuthService::buildAllowedChallengeNavs();

        $this->assertTrue(\TwoFactorAuthService::isUriAllowed('/lotgd/runmodule.php?module=twofactorauth&op=challenge', $allowed));
    }

    /**
     * The premise the whole fix rests on: decrypting with the wrong key is not
     * reliably an error.
     *
     * aes-256-cbc carries no authentication tag, so openssl_decrypt() rejects a
     * wrong key only when the final block's PKCS#7 padding comes out invalid --
     * about 255 times in 256. The remaining case returns bytes, and bytes are
     * not '', so every caller testing `!== ''` accepts them.
     *
     * Asserted by finding one rather than by quoting a rate: a bounded search
     * for a colliding secret is deterministic in outcome where a probability is
     * not, and it keeps the test honest if the stored format ever becomes
     * authenticated -- then no collision exists, the search runs out, and this
     * says so instead of passing quietly.
     */
    public function testAWrongKeySometimesDecryptsIntoSomethingThatIsNotEmpty(): void
    {
        self::requireCbcStorage();

        $collision = self::findWrongKeyCollision('key-one', 'key-two');

        self::assertNotNull(
            $collision,
            'no blob in 20000 decrypted under the wrong key -- if the stored format is now '
                . 'authenticated, this test and the check it guards have both outlived their purpose'
        );

        [, $blob, $garbage] = $collision;

        self::assertNotSame('', $garbage, 'the premise: a wrong key produced something');
        self::assertFalse(
            \TwoFactorAuthService::isPlausibleSecret($garbage),
            'and the check refuses it: ' . bin2hex($garbage)
        );
        self::assertTrue(
            \TwoFactorAuthService::isPlausibleSecret(\TwoFactorAuthService::decryptSecret($blob, 'key-one')),
            'control: the right key still yields something the check accepts'
        );
    }

    /**
     * The check must not lock out a secret a player already has.
     *
     * generateSecret() emits upper-case base32 with no padding, but a secret
     * that was written by hand or pasted with the grouping spaces an
     * authenticator app displays is one base32Decode() reads perfectly well --
     * and rejecting it here would cause exactly the lockout this check exists
     * to prevent.
     *
     * The four shapes below are the ones a stored secret plausibly has, not
     * every shape base32Decode() tolerates: it strips anything outside its
     * alphabet, so the set it accepts is far larger than this and naming the
     * test after it would claim more than the test checks.
     * Reported by Copilot.
     */
    public function testTheSecretShapesAPlayerMightHaveStoredPassTheCheck(): void
    {
        $generated = \TwoFactorAuthService::generateSecret();

        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret($generated));
        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret(strtolower($generated)));
        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret(chunk_split($generated, 4, ' ')));
        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret('JBSWY3DPEHPK3PXP===='));

        self::assertFalse(\TwoFactorAuthService::isPlausibleSecret(''));
        self::assertFalse(\TwoFactorAuthService::isPlausibleSecret("\x00\x91\xfe"));
    }

    /**
     * A value the character class lets through but the decoder cannot use.
     *
     * The first form of this check was the character class alone, and these
     * pass it: base32Decode() strips padding, whitespace and dashes, so what
     * reaches the token arithmetic is nothing at all. Calling such a value
     * plausible would skip the legacy key in exactly the case the check exists
     * to catch -- unreachable in practice, since the values it screens are 47
     * random bytes, but the predicate is supposed to mean what its name says.
     *
     * A single character is here for the same reason: five bits do not fill a
     * byte, so it decodes to nothing too.
     * Reported by Copilot.
     */
    public function testSeparatorsAloneAreNotASecret(): void
    {
        foreach (['====', '   ', '-', " -=\t", 'A'] as $value) {
            self::assertFalse(
                \TwoFactorAuthService::isPlausibleSecret($value),
                var_export($value, true) . ' decodes to nothing, so it cannot produce a token'
            );
        }
    }

    /**
     * A fixture about the CBC format needs the CBC format.
     *
     * encryptSecret() falls back to `plain:` when openssl is unavailable, and
     * that format does not consult the key at all -- so the "wrong" key returns
     * the real secret, there is no collision to find, and a test looking for
     * one fails for a reason that has nothing to do with what it asks.
     * Measured with both functions disabled: it does.
     * Reported by Codex.
     */
    private static function requireCbcStorage(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            self::markTestSkipped('without openssl the stored format is `plain:`, which ignores the key');
        }
    }

    /**
     * Search for a blob that decrypts under a key it was not encrypted with.
     *
     * @return array{0: string, 1: string, 2: string}|null secret, blob, garbage
     */
    private static function findWrongKeyCollision(string $key, string $otherKey): ?array
    {
        for ($i = 0; $i < 20000; $i++) {
            $secret = \TwoFactorAuthService::generateSecret();
            $blob = \TwoFactorAuthService::encryptSecret($secret, $key);
            $garbage = \TwoFactorAuthService::decryptSecret($blob, $otherKey);

            if ($garbage !== '') {
                return [$secret, $blob, $garbage];
            }
        }

        return null;
    }
}
