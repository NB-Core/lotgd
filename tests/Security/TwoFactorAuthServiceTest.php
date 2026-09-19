<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Tests\Support\LegacyTwoFactorSecret;
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
     * The premise the check rests on, now scoped to the format that has it.
     *
     * aes-256-cbc carries no authentication tag, so openssl_decrypt() rejects a
     * wrong key only when the final block's PKCS#7 padding comes out invalid --
     * about 255 times in 256. The remaining case returns bytes, and bytes are
     * not '', so every caller testing `!== ''` accepts them.
     *
     * This used to build its fixture with encryptSecret(), and stopped
     * compiling the moment that method started writing `enc2:` -- with the
     * message its own assertion carried for the occasion. That is what it was
     * for: the check below guards a format, not a codebase, and a test that
     * silently followed the production code to the new format would have gone
     * on passing while asserting nothing about the old one, which is the one
     * still on disk.
     *
     * Asserted by finding a collision rather than by quoting a rate: a bounded
     * search is deterministic in outcome where a probability is not.
     */
    public function testAWrongKeyCanDecryptALegacyBlobIntoSomethingThatIsNotEmpty(): void
    {
        self::requireCbcStorage();

        $collision = LegacyTwoFactorSecret::findWrongKeyCollision('key-one', 'key-two');

        self::assertNotNull(
            $collision,
            'no legacy blob in 20000 decrypted under the wrong key, so nothing here was exercised'
        );

        self::assertSame(
            $collision['secret'],
            \TwoFactorAuthService::decryptSecret($collision['blob'], 'key-one'),
            'precondition: the fixture is a real legacy blob, not something this helper has drifted into'
        );

        self::assertNotSame('', $collision['garbage'], 'the premise: a wrong key produced something');
        self::assertFalse(
            \TwoFactorAuthService::isPlausibleSecret($collision['garbage']),
            'and the check refuses it: ' . bin2hex($collision['garbage'])
        );
        self::assertTrue(
            \TwoFactorAuthService::isPlausibleSecret($collision['secret']),
            'control: the right key still yields something the check accepts'
        );
    }

    /**
     * The authenticated format does not have that premise, which is the point.
     *
     * Where the test above searches for a wrong-key collision and expects to
     * find one, this searches for the same thing and expects to find none.
     *
     * The bound is chosen to make this a regression guard rather than a
     * restatement of what GCM promises. Forging a tag is 2^-128, so "none in
     * any number of attempts" is not in doubt; what is worth catching is
     * someone quietly putting the CBC path back, and at its 1-in-255 rate,
     * 2000 attempts miss a collision only 0.04% of the time. That is the number
     * this bound is for.
     *
     * Counted rather than asserted per iteration, so a failure reports how many
     * leaked instead of stopping at the first and so the suite's assertion
     * count stays a number someone can read.
     */
    public function testAWrongKeyNeverDecryptsAnAuthenticatedBlob(): void
    {
        self::requireAuthenticatedStorage();

        $attempts = 2000;
        $leaked = 0;
        $lastBlob = '';
        $lastSecret = '';

        for ($i = 0; $i < $attempts; $i++) {
            $lastSecret = \TwoFactorAuthService::generateSecret();
            $lastBlob = \TwoFactorAuthService::encryptSecret($lastSecret, 'key-one');

            if (\TwoFactorAuthService::decryptSecret($lastBlob, 'key-two') !== '') {
                $leaked++;
            }
        }

        self::assertSame(
            0,
            $leaked,
            "$leaked of $attempts authenticated blobs gave bytes to a wrong key, which the tag is supposed to prevent"
        );

        self::assertStringStartsWith('enc2:', $lastBlob, 'precondition: the authenticated format was written');
        self::assertSame(
            $lastSecret,
            \TwoFactorAuthService::decryptSecret($lastBlob, 'key-one'),
            'control: the right key reads it back, so "the wrong key got nothing" means something'
        );
    }

    /**
     * The two formats do not share a key, which is the one claim about this
     * change that behaviour cannot show.
     *
     * Everything else here is observable: swap the cipher, drop the tag, stop
     * reading the old format, and a test goes red. Not this one. Any
     * self-consistent derivation encrypts and decrypts perfectly well, so
     * replacing the HKDF with the legacy `sha256(key)` passes every other
     * assertion in this file -- measured, by doing it.
     *
     * So it is asserted where it lives rather than left as a sentence in a
     * docblock. What it buys is domain separation: the signing key is the same
     * value for both formats, and one key used under two cipher modes is the
     * kind of thing that is cheap to avoid now and awkward to change once blobs
     * exist. The info string names the format, so a third one would get its own
     * key by construction.
     */
    public function testTheAuthenticatedFormatDoesNotUseTheLegacyKey(): void
    {
        $derive = new \ReflectionMethod(\TwoFactorAuthService::class, 'aeadKey');
        $derive->setAccessible(true);

        $signingKey = 'a-signing-key';
        $aead = $derive->invoke(null, $signingKey);

        self::assertSame(32, strlen($aead), 'aes-256 wants 32 bytes');
        self::assertNotSame(
            hash('sha256', $signingKey, true),
            $aead,
            'the authenticated format derives the legacy key, so both ciphers share one key'
        );
        self::assertSame($aead, $derive->invoke(null, $signingKey), 'and it has to be deterministic, or nothing decrypts');
        self::assertNotSame(
            $aead,
            $derive->invoke(null, 'another-signing-key'),
            'a different signing key must give a different key'
        );
        self::assertSame(
            32,
            strlen($derive->invoke(null, '')),
            'an empty signing key is answered rather than raised from inside a crypto helper'
        );
    }

    /**
     * A single flipped bit is refused, which is what distinguishes a tag from a
     * checksum nobody checks.
     *
     * The byte chosen is in the ciphertext rather than the tag or the iv,
     * because tampering with the tag is the case anyone would think to test and
     * tampering with the payload is the case that matters: without
     * authentication, CBC lets an attacker who can write to the prefs table
     * make predictable changes to the plaintext.
     */
    public function testATamperedAuthenticatedBlobIsRefused(): void
    {
        self::requireAuthenticatedStorage();

        $secret = \TwoFactorAuthService::generateSecret();
        $blob = \TwoFactorAuthService::encryptSecret($secret, 'key-one');

        $raw = self::base64UrlDecode(substr($blob, 5));
        self::assertGreaterThan(28, strlen($raw), 'precondition: iv and tag and at least one byte of payload');

        $raw[28] = chr(ord($raw[28]) ^ 0x01);
        $tampered = 'enc2:' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        self::assertNotSame($blob, $tampered, 'precondition: the fixture was actually changed');
        self::assertSame(
            '',
            \TwoFactorAuthService::decryptSecret($tampered, 'key-one'),
            'one flipped bit in the ciphertext was accepted'
        );
    }

    /**
     * A blob written before this change is still readable, which is the whole
     * of what makes the migration safe to ship.
     *
     * Without it the change would lock out every account that has not verified
     * since -- the same outcome as the bug it fixes, applied to everybody.
     */
    public function testALegacyBlobIsStillReadable(): void
    {
        self::requireCbcStorage();

        $secret = \TwoFactorAuthService::generateSecret();
        $blob = LegacyTwoFactorSecret::encrypt($secret, 'key-one');

        self::assertSame($secret, \TwoFactorAuthService::decryptSecret($blob, 'key-one'));
    }

    /**
     * And it is reported as wanting rewriting, which is how it stops being one.
     *
     * The migration is opportunistic: a successful verification rewrites the
     * blob in the current format. So "needs rewriting" is not bookkeeping --
     * it is the only thing that ever moves an account off the weak format.
     */
    public function testEachStoredFormatKnowsWhetherItShouldBeRewritten(): void
    {
        self::requireAuthenticatedStorage();

        $secret = \TwoFactorAuthService::generateSecret();

        self::assertFalse(
            \TwoFactorAuthService::needsReencryption(\TwoFactorAuthService::encryptSecret($secret, 'key-one')),
            'what encryptSecret() just wrote is by definition the current format'
        );
        self::assertTrue(
            \TwoFactorAuthService::needsReencryption(LegacyTwoFactorSecret::encrypt($secret, 'key-one')),
            'the unauthenticated format is exactly what the migration is for'
        );
        self::assertTrue(
            \TwoFactorAuthService::needsReencryption('plain:' . rtrim(strtr(base64_encode($secret), '+/', '-_'), '=')),
            'and so is the fallback that stores the secret unencrypted'
        );
    }

    /**
     * Only the authenticated format claims a wrong key is refused.
     *
     * The compatibility read uses this to decide whether a non-empty
     * decryption still needs sanity-checking, so a predicate that said yes for
     * `enc:` would remove the check that #1547 added.
     */
    public function testOnlyTheAuthenticatedFormatIsReportedAsAuthenticated(): void
    {
        self::requireAuthenticatedStorage();

        $secret = \TwoFactorAuthService::generateSecret();

        self::assertTrue(
            \TwoFactorAuthService::isAuthenticatedFormat(\TwoFactorAuthService::encryptSecret($secret, 'key-one'))
        );
        self::assertFalse(
            \TwoFactorAuthService::isAuthenticatedFormat(LegacyTwoFactorSecret::encrypt($secret, 'key-one'))
        );
        self::assertFalse(\TwoFactorAuthService::isAuthenticatedFormat('plain:AAAA'));
        self::assertFalse(\TwoFactorAuthService::isAuthenticatedFormat(''));
    }

    /**
     * The check must not lock out a secret a player already has.
     *
     * generateSecret() emits upper-case base32 with no padding, but a secret
     * carrying the grouping spaces an authenticator app displays, or the
     * padding another implementation wrote, is one base32Decode() reads
     * perfectly well -- and rejecting it here would cause exactly the lockout
     * this check exists to prevent.
     *
     * The shapes below are the ones a stored secret plausibly has, not every
     * shape base32Decode() tolerates: it strips anything outside its alphabet,
     * so the set it accepts is far larger than this and naming the test after
     * it would claim more than the test checks.
     * Reported by Copilot.
     */
    public function testTheSecretShapesAPlayerMightHaveStoredPassTheCheck(): void
    {
        $generated = \TwoFactorAuthService::generateSecret();

        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret($generated));
        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret(chunk_split($generated, 4, ' ')));
        self::assertTrue(\TwoFactorAuthService::isPlausibleSecret('JBSWY3DPEHPK3PXP===='));

        self::assertFalse(\TwoFactorAuthService::isPlausibleSecret(''));
        self::assertFalse(\TwoFactorAuthService::isPlausibleSecret("\x00\x91\xfe"));
    }

    /**
     * Lower case is not a shape this decoder reads, and this test says so
     * rather than assuming the opposite -- which the first version of the test
     * above did, with an assertion that was flaky at about one run in a hundred
     * and went red in CI on its first attempt.
     *
     * base32Decode() strips before it uppercases:
     *
     *     strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded))
     *
     * so every lower-case letter is removed and only the digits 2-7 survive.
     * 'JBSWY3DPEHPK3PXP' decodes to the ten bytes it should; lower-cased, the
     * two surviving '3's decode to the single byte 0xde. So a lower-case secret
     * does not merely fail -- it silently becomes a different, much shorter one.
     *
     * That is why the answer isPlausibleSecret() gives for lower case is left
     * undefined here: it depends on how many digits happen to survive, which is
     * what made the earlier assertion flaky. 99.09% of lower-cased generated
     * secrets keep two or more, measured over 20000.
     *
     * Not fixed here. Normalising case in base32Decode() would change which
     * stored secrets verify, which is a change of its own -- in the harmless
     * direction, since nothing that works today would stop working.
     */
    public function testLowerCaseIsNotReadByTheDecoderAtAll(): void
    {
        $decode = new \ReflectionMethod(\TwoFactorAuthService::class, 'base32Decode');
        $decode->setAccessible(true);

        self::assertSame(
            'Hello!' . hex2bin('deadbeef'),
            $decode->invoke(null, 'JBSWY3DPEHPK3PXP'),
            'control: upper case decodes to what it should'
        );
        self::assertSame(
            hex2bin('de'),
            $decode->invoke(null, 'jbswy3dpehpk3pxp'),
            'lower-cased, only the two digits survive the strip'
        );
        self::assertSame(
            '',
            $decode->invoke(null, 'abcdefgh'),
            'and with no digits at all, nothing survives'
        );
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
     * A fixture about the authenticated format needs that format.
     *
     * A build without aes-256-gcm falls back to `enc:`, where a wrong key is
     * refused only most of the time -- so the assertions above would be asking
     * the weaker format to keep the stronger one's promise, and would fail for
     * a reason that has nothing to do with what they check.
     */
    private static function requireAuthenticatedStorage(): void
    {
        if (!\TwoFactorAuthService::supportsAuthenticatedStorage()) {
            self::markTestSkipped('this build cannot write aes-256-gcm, so the stored format is the older one');
        }
    }

    /**
     * The decoding half of the storage envelope, for the tampering fixture.
     */
    private static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }
}
