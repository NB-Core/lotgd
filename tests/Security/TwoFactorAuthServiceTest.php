<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Tests\Support\LegacyTwoFactorSecret;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $authenticated = 0;
        $readBack = 0;

        for ($i = 0; $i < $attempts; $i++) {
            $secret = \TwoFactorAuthService::generateSecret();
            $blob = \TwoFactorAuthService::encryptSecret($secret, 'key-one');

            if (str_starts_with($blob, 'enc2:')) {
                $authenticated++;
            }

            if (\TwoFactorAuthService::decryptSecret($blob, 'key-one') === $secret) {
                $readBack++;
            }

            if (\TwoFactorAuthService::decryptSecret($blob, 'key-two') !== '') {
                $leaked++;
            }
        }

        self::assertSame(
            0,
            $leaked,
            "$leaked of $attempts authenticated blobs gave bytes to a wrong key, which the tag is supposed to prevent"
        );

        // Every iteration, not the last one. The first version of this checked
        // the blob left in the loop variable, so a run where encryptSecret()
        // fell back to `enc:` for some or all of the earlier iterations would
        // have measured the legacy format and still passed. Reported by Copilot.
        self::assertSame(
            $attempts,
            $authenticated,
            'some iterations did not write the authenticated format, so the count above measured the wrong thing'
        );
        self::assertSame(
            $attempts,
            $readBack,
            'control: the right key reads every one of them back, so "the wrong key got nothing" means something'
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

        // A prefix this class never wrote ranks below everything it can write.
        // Unreachable in practice -- a rewrite needs the plaintext, and the
        // plaintext comes from having read the blob -- so it is pinned here
        // rather than left to whichever way the comparison happened to fall.
        self::assertTrue(
            \TwoFactorAuthService::needsReencryption('something-else:' . $secret),
            'an unrecognised stored format is not treated as already good enough'
        );
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
     * What each openssl configuration can read and what it may write.
     *
     * A table rather than a test per case, because the two lockouts this file
     * exists to prevent were both *one row* of it, and both were introduced by
     * a fix for the row above. `decryptSecret()` gates the `enc2:` branch on a
     * capability, so any capability that needs more than decryption locks
     * migrated accounts out of their own secrets:
     *
     *   - a cipher list lookup, and disabling openssl_get_cipher_methods()
     *     alone did it;
     *   - an encrypt-then-decrypt round trip, and disabling openssl_encrypt()
     *     alone did it.
     *
     * Both reported by Copilot and both reproduced before fixing. The answer
     * was to stop asking one question: reading is probed against a known
     * `enc2:` value, writing against an encryption that must also be readable.
     *
     * `disable_functions` takes a list, which is the whole reason these
     * combinations exist. The last column is not a preference -- it is the only
     * format that row can read back, which is the rule the legacy branch was
     * quietly breaking.
     *
     * @return iterable<string, array{0: string, 1: bool, 2: bool, 3: string, 4: bool}>
     */
    public static function opensslConfigurations(): iterable
    {
        yield 'everything available' => ['', true, true, 'enc2:', true];
        yield 'cipher discovery disabled' => ['openssl_get_cipher_methods', true, true, 'enc2:', true];
        yield 'encryption disabled' => ['openssl_encrypt', true, false, 'plain:', false];
        yield 'decryption disabled' => ['openssl_decrypt', false, false, 'plain:', false];
        yield 'no openssl at all' => ['openssl_encrypt,openssl_decrypt', false, false, 'plain:', false];
    }

    #[DataProvider('opensslConfigurations')]
    public function testEachOpensslConfigurationReadsWhatItCanAndWritesOnlyWhatItReads(
        string $disabled,
        bool $canRead,
        bool $canWrite,
        string $expectedPrefix,
        bool $plainNeedsRewriting
    ): void {
        self::requireAuthenticatedStorage();

        $secret = \TwoFactorAuthService::generateSecret();
        $existing = \TwoFactorAuthService::encryptSecret($secret, 'a-key');

        self::assertStringStartsWith('enc2:', $existing, 'precondition: an already-migrated account');

        $result = self::runWithDisabledFunctions($disabled, $existing);

        self::assertSame($canRead, $result['read'], 'read capability');
        self::assertSame($canWrite, $result['write'], 'write capability');

        self::assertSame(
            $canRead ? $secret : '',
            $result['existing'],
            $canRead
                ? 'an account stored before this configuration is unreadable, which is a lockout'
                : 'it claimed not to be able to read and then read something anyway'
        );

        self::assertSame($expectedPrefix, $result['prefix'], 'the format chosen for a new secret');

        // The rule the whole table is for: never write what you cannot read.
        self::assertSame(
            'JBSWY3DPEHPK3PXP',
            $result['readback'],
            'this configuration wrote a format it cannot read back, so the next verification fails'
        );

        // And the rule that keeps the migration from spinning. needsReencryption()
        // used to decide for itself which formats were writable, and disagreed
        // with encryptSecret() in the row where only decryption is missing: a
        // `plain:` secret was reported as needing a rewrite, the rewrite produced
        // `plain:` again, and every successful verification wrote the preference
        // for nothing. Reported by Copilot.
        self::assertSame($plainNeedsRewriting, $result['plain_needs_rewriting'], 'a plain secret, in this configuration');
        self::assertSame($plainNeedsRewriting, $result['legacy_needs_rewriting'], 'a legacy secret, in this configuration');
        self::assertFalse(
            $result['own_needs_rewriting'],
            'it asked to rewrite what it had just written, which repeats on every verification'
        );

        // The migration only ever goes up. Asking whether the stored prefix is
        // the one this installation writes reads as the same thing and is not:
        // where `enc2:` can still be read but no longer written, that question
        // answers yes for an authenticated secret, and the rewrite stores it in
        // plaintext. A migration that can downgrade is worse than one that
        // stalls. Reported by Copilot.
        self::assertFalse(
            $result['authenticated_needs_rewriting'],
            'an already-authenticated secret was offered for rewriting, and this configuration '
                . 'would have written it as ' . $result['prefix']
        );
    }

    /**
     * The probes answer for a cipher this build does not have, and stay quiet.
     *
     * The branch they guard -- openssl present, aes-256-gcm absent -- cannot be
     * produced here: disable_functions turns functions off, not ciphers. So the
     * mechanism is tested with a name no build has, which is the same code path
     * reaching the same answer.
     *
     * The quietness is not decoration. openssl reports an unknown cipher as a
     * *warning*, and a probe that let it through would print one on every
     * request of an installation that has to take the fallback -- from a
     * capability check, about a condition the code handles deliberately.
     *
     * Asserted through error_get_last() rather than by installing a handler
     * around the call, which is how the first version was written and why it
     * could not fail: a handler returning false inside the probe falls through
     * to PHP's *internal* handler, not to the test's, so the test saw nothing
     * either way. error_get_last() is populated exactly when nothing handled
     * the error, which is the question being asked.
     */
    public function testTheProbesAnswerForAnAbsentCipherAndStayQuiet(): void
    {
        foreach (['cipherReadsTheVector', 'cipherEncrypts'] as $name) {
            $probe = new \ReflectionMethod(\TwoFactorAuthService::class, $name);
            $probe->setAccessible(true);

            error_clear_last();
            $absent = $probe->invoke(null, 'aes-256-not-a-cipher');
            $leaked = error_get_last();

            self::assertFalse($absent, "$name reported a cipher this build does not have as available");
            self::assertNull($leaked, "$name let an error escape: " . ($leaked['message'] ?? ''));

            if (\TwoFactorAuthService::supportsAuthenticatedStorage()) {
                self::assertTrue($probe->invoke(null, 'aes-256-gcm'), "control: $name accepts the real cipher");
            }
        }
    }

    /**
     * Run the storage round trip in a child with functions disabled.
     *
     * @return array{read: bool, write: bool, prefix: string, readback: string, existing: string,
     *     plain_needs_rewriting: bool, legacy_needs_rewriting: bool,
     *     authenticated_needs_rewriting: bool, own_needs_rewriting: bool}
     */
    private static function runWithDisabledFunctions(string $disabled, string $existingBlob): array
    {
        $script = <<<'PHP'
            require $argv[1] . '/modules/TwoFactorAuth/TwoFactorAuthService.php';

            $blob = TwoFactorAuthService::encryptSecret('JBSWY3DPEHPK3PXP', 'a-key');

            // JSON_THROW_ON_ERROR because json_encode() returns false for a
            // value that is not valid UTF-8 -- and a decryption that failed
            // yields arbitrary bytes. Without it the child printed nothing at
            // all and the assertion blamed the wrong thing.
            $plain = 'plain:' . rtrim(strtr(base64_encode('JBSWY3DPEHPK3PXP'), '+/', '-_'), '=');

            echo json_encode([
                'read' => TwoFactorAuthService::supportsAuthenticatedRead(),
                'write' => TwoFactorAuthService::supportsAuthenticatedStorage(),
                'prefix' => substr($blob, 0, strpos($blob, ':') + 1),
                'readback' => TwoFactorAuthService::decryptSecret($blob, 'a-key'),
                'existing' => TwoFactorAuthService::decryptSecret($argv[2], 'a-key'),
                'plain_needs_rewriting' => TwoFactorAuthService::needsReencryption($plain),
                'legacy_needs_rewriting' => TwoFactorAuthService::needsReencryption($argv[3]),
                'authenticated_needs_rewriting' => TwoFactorAuthService::needsReencryption($argv[2]),
                'own_needs_rewriting' => TwoFactorAuthService::needsReencryption($blob),
            ], JSON_THROW_ON_ERROR);
            PHP;

        $file = (string) tempnam(sys_get_temp_dir(), 'lotgd_2fa_');

        try {
            file_put_contents($file, "<?php\n" . $script);

            $output = (string) shell_exec(sprintf(
                '%s -d disable_functions=%s %s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($disabled),
                escapeshellarg($file),
                escapeshellarg(dirname(__DIR__, 2)),
                escapeshellarg($existingBlob),
                escapeshellarg(LegacyTwoFactorSecret::encrypt('JBSWY3DPEHPK3PXP', 'a-key'))
            ));
        } finally {
            @unlink($file);
        }

        $result = json_decode($output, true);

        self::assertIsArray($result, 'the child did not complete: ' . $output);

        return $result;
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
