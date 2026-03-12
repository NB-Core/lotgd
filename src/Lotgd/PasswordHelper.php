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
        if ($algo === self::ALGO_MODERN) {
            return password_verify($plaintext, $storedHash);
        }

        // Legacy: stored hash is md5(md5(plaintext)).
        return hash_equals($storedHash, md5(md5($plaintext)));
    }

    /**
     * Check whether the stored hash should be upgraded to a modern algorithm.
     *
     * @param int $algo Algorithm identifier from the database.
     *
     * @return bool True if the password should be re-hashed.
     */
    public static function needsRehash(int $algo): bool
    {
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
}
