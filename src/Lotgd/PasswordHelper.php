<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Central helper for password hashing and verification.
 *
 * Supports transparent migration from legacy md5(md5()) hashes to
 * modern bcrypt hashes produced by password_hash().
 *
 * Passwords are now submitted as plaintext over HTTPS (the former
 * client-side MD5 hashing has been removed).
 */
final class PasswordHelper
{
    /** Legacy double-MD5 algorithm identifier. */
    public const ALGO_LEGACY = 0;

    /** Modern bcrypt algorithm identifier. */
    public const ALGO_MODERN = 1;

    /**
     * Hash a plaintext password for storage using bcrypt.
     *
     * @param string $plaintext The plaintext password.
     *
     * @return string Bcrypt hash string (~60 characters).
     */
    public static function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_BCRYPT);
    }

    /**
     * Verify a submitted plaintext password against a stored hash.
     *
     * Handles both legacy md5(md5()) and modern bcrypt hashes.
     *
     * @param string $plaintext   Plaintext password from the form.
     * @param string $storedHash  Hash value from the database.
     * @param int    $algo        Algorithm identifier (ALGO_LEGACY or ALGO_MODERN).
     *
     * @return bool True if the password matches.
     */
    public static function verify(string $plaintext, string $storedHash, int $algo): bool
    {
        if ($algo === self::ALGO_MODERN || self::isModernHash($storedHash)) {
            return password_verify($plaintext, $storedHash);
        }

        // Legacy: stored hash is md5(md5(plaintext)).
        return hash_equals($storedHash, md5(md5($plaintext)));
    }

    /**
     * Verify very old installer-upgrade credentials.
     *
     * Historical 0.9.7-era data could store the password as plaintext or as
     * a single MD5 hash. This helper keeps that compatibility logic in one
     * place so installer code does not reimplement hashing checks.
     *
     * @param string $plaintext Submitted plaintext password.
     * @param string $storedHash Stored database value for the password column.
     *
     * @return bool True when the legacy upgrade credential format matches.
     */
    public static function verifyLegacyUpgradeCredential(string $plaintext, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            return hash_equals(strtolower($storedHash), md5($plaintext));
        }

        return hash_equals($storedHash, $plaintext);
    }

    /**
     * Check whether the stored hash should be upgraded to a modern algorithm.
     *
     * @param int $algo Algorithm identifier from the database.
     *
     * @return bool True if the password should be re-hashed.
     */
    public static function needsRehash(int $algo, string $storedHash = ''): bool
    {
        if (self::isModernHash($storedHash)) {
            return false;
        }

        return $algo !== self::ALGO_MODERN;
    }

    /**
     * Check whether an account still uses the legacy hash.
     *
     * @param int $algo Algorithm identifier from the database.
     *
     * @return bool True if the account uses md5(md5()).
     */
    public static function isLegacy(int $algo): bool
    {
        return $algo === self::ALGO_LEGACY;
    }

    /**
     * Detect whether the stored hash is already a modern password hash.
     *
     * Uses password_get_info() instead of prefix matching so malformed strings
     * like "$2broken" are not treated as valid bcrypt hashes.
     */
    public static function isModernHash(string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        $info = password_get_info($storedHash);

        return ($info['algo'] ?? null) !== null && $info['algo'] !== 0;
    }
}
