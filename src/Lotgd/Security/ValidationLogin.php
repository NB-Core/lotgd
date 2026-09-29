<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * The one-click login offered after a player validates an email address or
 * a forgotten-password link (create.php).
 *
 * That button used to post the account's stored password hash, prefixed with
 * "!md52!", and login.php accepted a hash equal to the stored one as a
 * password. The hash was then a credential of its own: anyone holding it --
 * from a database dump, a backup, or the page source the button sat in --
 * could log in without knowing or cracking the password, which undid the
 * point of storing bcrypt hashes at all.
 *
 * What replaces it never leaves the server except as a random token. The
 * token is a {@see Csrf} token in its own scope, issued fresh for each grant
 * and forgotten on redemption; beside it the session keeps which account it
 * is for and until when. It works only in the browser that opened the link,
 * for that one account, for a few minutes, and once, whether that use
 * succeeds or not.
 */
final class ValidationLogin
{
    /** Form field carrying the token. */
    public const FIELD = 'validation_login';

    /** Seconds a grant stays usable. */
    public const LIFETIME = 300;

    /** Where the session keeps the account and expiry the token is for. */
    private const SESSION_KEY = 'validation_login';

    /**
     * Allow one login to this account from the current session.
     *
     * Replaces any earlier grant, so only the most recently validated link
     * can be used.
     *
     * @return string The token to post back
     */
    public static function grant(int $acctid, string $login, ?int $now = null): string
    {
        Csrf::forget(Csrf::SCOPE_VALIDATION_LOGIN);
        $session = &self::sessionRef();
        $session[self::SESSION_KEY] = [
            'acctid' => $acctid,
            'login' => $login,
            'expires' => ($now ?? time()) + self::LIFETIME,
        ];

        return Csrf::token(Csrf::SCOPE_VALIDATION_LOGIN);
    }

    /**
     * Redeem a posted token.
     *
     * The grant is removed before anything is compared, so a wrong or late
     * token cannot be followed by a second try against the same grant.
     *
     * @return array{acctid:int,login:string}|null The account the grant names,
     *         or null when there is no grant, it expired, or the token differs
     */
    public static function consume(mixed $provided, ?int $now = null): ?array
    {
        $tokenMatches = Csrf::matches(Csrf::SCOPE_VALIDATION_LOGIN, $provided);
        Csrf::forget(Csrf::SCOPE_VALIDATION_LOGIN);
        $session = &self::sessionRef();
        $grant = $session[self::SESSION_KEY] ?? null;
        unset($session[self::SESSION_KEY]);

        if (!$tokenMatches || !is_array($grant)) {
            return null;
        }

        $expires = $grant['expires'] ?? null;
        $acctid = $grant['acctid'] ?? null;
        $login = $grant['login'] ?? null;
        if (!is_int($expires) || !is_int($acctid) || !is_string($login) || ($now ?? time()) > $expires) {
            return null;
        }

        return ['acctid' => $acctid, 'login' => $login];
    }

    /**
     * A random token for an emailed validation or password-reset link.
     *
     * These tokens were md5() of the current time and the email address, or
     * of the time and the password hash. Whoever registered or changed an
     * address knew both, so could build the link without the mail arriving.
     *
     * @param string $prefix Kept in front, as the callers mark some tokens
     *                       with "x"; the whole token fits the 32-character
     *                       columns that store it
     */
    public static function linkToken(string $prefix = ''): string
    {
        return $prefix . substr(bin2hex(random_bytes(16)), 0, 32 - strlen($prefix));
    }

    /**
     * The login button for a granted account.
     */
    public static function button(string $login, string $token, string $label): string
    {
        return "<form action='login.php' method='POST'>"
            . "<input type='hidden' name='name' value='" . Escape::html($login) . "'>"
            . "<input type='hidden' name='" . self::FIELD . "' value='" . Escape::html($token) . "'>"
            . "<input type='submit' class='button' value='" . Escape::html($label) . "'>"
            . '</form>';
    }

    /**
     * Reference to the game session array, the one {@see Csrf} uses.
     *
     * @return array<string, mixed>
     */
    private static function &sessionRef(): array
    {
        if (!isset($GLOBALS['session']) || !is_array($GLOBALS['session'])) {
            $GLOBALS['session'] = [];
        }

        return $GLOBALS['session'];
    }
}
