<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PasswordHelper;
use PHPUnit\Framework\TestCase;

/**
 * Validates password helper behavior for legacy and modern hashes.
 */
final class PasswordHelperTest extends TestCase
{
    /**
     * Ensure bcrypt hashes still verify when password_algo metadata is stale.
     */
    public function testVerifyAcceptsBcryptHashEvenWithLegacyAlgo(): void
    {
        $hash = password_hash('swordfish', PASSWORD_BCRYPT);
        $this->assertIsString($hash);

        $this->assertTrue(PasswordHelper::verify('swordfish', $hash, PasswordHelper::ALGO_LEGACY));
    }

    /**
     * A password is matched as typed, backslashes included.
     */
    public function testMatchTypedComparesThePasswordAsTyped(): void
    {
        $hash = PasswordHelper::hash('back\\slash');

        $this->assertSame('back\\slash', PasswordHelper::matchTyped('back\\slash', $hash, PasswordHelper::ALGO_MODERN));
        $this->assertNull(PasswordHelper::matchTyped('backslash', $hash, PasswordHelper::ALGO_MODERN));
    }

    /**
     * Hashes earlier releases stored without the backslashes still match,
     * and the answer names that form, which is the one a rehash must keep.
     */
    public function testMatchTypedAcceptsTheFormEarlierReleasesStored(): void
    {
        $bcrypt = PasswordHelper::hash('backslash');
        $this->assertSame('backslash', PasswordHelper::matchTyped('back\\slash', $bcrypt, PasswordHelper::ALGO_MODERN));

        $legacy = md5(md5('backslash'));
        $this->assertSame('backslash', PasswordHelper::matchTyped('back\\slash', $legacy, PasswordHelper::ALGO_LEGACY));
    }

    /**
     * Without a backslash there is nothing to strip and no second attempt.
     */
    public function testMatchTypedRejectsAWrongPassword(): void
    {
        $hash = PasswordHelper::hash('swordfish');

        $this->assertNull(PasswordHelper::matchTyped('swordfisk', $hash, PasswordHelper::ALGO_MODERN));
        $this->assertNull(PasswordHelper::matchTyped('', $hash, PasswordHelper::ALGO_MODERN));
    }

    /**
     * Ensure already-modern hashes are not repeatedly rehashed.
     */
    public function testNeedsRehashReturnsFalseForBcryptHashWithLegacyAlgo(): void
    {
        $hash = password_hash('swordfish', PASSWORD_BCRYPT);
        $this->assertIsString($hash);

        $this->assertFalse(PasswordHelper::needsRehash(PasswordHelper::ALGO_LEGACY, $hash));
    }

    /**
     * Ensure single-MD5 upgrade compatibility is centralized in PasswordHelper.
     */
    public function testVerifyLegacyUpgradeCredentialAcceptsSingleMd5(): void
    {
        $legacySingleMd5 = md5('swordfish');

        $this->assertTrue(PasswordHelper::verifyLegacyUpgradeCredential('swordfish', $legacySingleMd5));
    }

    /**
     * Ensure plaintext upgrade compatibility remains available for very old installs.
     */
    public function testVerifyLegacyUpgradeCredentialAcceptsPlaintext(): void
    {
        $this->assertTrue(PasswordHelper::verifyLegacyUpgradeCredential('swordfish', 'swordfish'));
    }

    /**
     * Single- and double-MD5 values share one conservative classification.
     */
    public function testLegacyHashClassificationDoesNotGuessMd5Generation(): void
    {
        $this->assertTrue(PasswordHelper::isLegacyHash(md5('swordfish')));
        $this->assertTrue(PasswordHelper::isLegacyHash(md5(md5('swordfish'))));
        $this->assertFalse(PasswordHelper::isLegacyHash('short plaintext'));
        $this->assertFalse(PasswordHelper::isLegacyHash(PasswordHelper::hash('swordfish')));
    }


    /**
     * Ensure malformed values with "$2" prefix are not treated as bcrypt hashes.
     */
    public function testIsModernHashRejectsMalformedBcryptPrefix(): void
    {
        $this->assertFalse(PasswordHelper::isModernHash('$2definitely-not-a-bcrypt-hash'));
        $this->assertTrue(PasswordHelper::needsRehash(PasswordHelper::ALGO_LEGACY, '$2definitely-not-a-bcrypt-hash'));
    }
}
