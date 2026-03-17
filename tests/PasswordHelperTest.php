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
     * Ensure malformed values with "$2" prefix are not treated as bcrypt hashes.
     */
    public function testIsModernHashRejectsMalformedBcryptPrefix(): void
    {
        $this->assertFalse(PasswordHelper::isModernHash('$2definitely-not-a-bcrypt-hash'));
        $this->assertTrue(PasswordHelper::needsRehash(PasswordHelper::ALGO_LEGACY, '$2definitely-not-a-bcrypt-hash'));
    }
}
