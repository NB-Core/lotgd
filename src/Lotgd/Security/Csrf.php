<?php

declare(strict_types=1);

namespace Lotgd\Security;

use Lotgd\Http;

/**
 * Per-session CSRF tokens.
 *
 * Seven places grew their own copy of the same recipe — a lazily generated
 * `bin2hex(random_bytes())` in the session, `hash_equals()` on the way back in,
 * `htmlspecialchars()` on the way out — and each ended up slightly different:
 * one used half the entropy, one skipped the empty-string check, one generated
 * in a different file from the one that validated. None of them was wrong; the
 * problem was the eighth copy someone would write next.
 *
 * The class validates and nothing else. It returns booleans, never logs, never
 * sets a status code, never outputs. Callers keep their own failure behaviour —
 * 400, a redirect, a JSON payload, blanking `$op` — which is what lets the
 * existing sites move onto it without changing what a player or admin sees. It
 * is also what makes it callable from async/process.php, where anything but
 * JSON breaks the client contract.
 *
 * ## The invariant
 *
 * Only {@see self::token()}, {@see self::escapedToken()} and
 * {@see self::hiddenField()} create a token. Every inspection and validation
 * path — {@see self::peek()}, {@see self::matches()}, the `validate*()` family —
 * leaves the session untouched and fails when nothing is stored.
 *
 * Two things depend on this. A mistyped scope has no stored token, so it fails
 * closed rather than silently minting one that always matches. And a validator
 * running after `session_write_close()` (async/process.php releases the session
 * lock before dispatch for read-only callables) cannot generate a token that is
 * then thrown away, which would look like an intermittent mismatch.
 */
final class Csrf
{
    /** Form field and JSON key carrying the token. */
    public const FIELD = 'csrf_token';

    /**
     * The field {@see \Lotgd\Forms::csrfField()} renders.
     *
     * Deliberately not {@see self::FIELD}. A page may render its own token and
     * *also* contain a form built by `showForm()` -- creatures.php, mounts.php
     * and companions.php all wrap `module_objpref_edit()`, which reaches
     * `Forms::showForm()`, inside a form that already carries an editor token.
     * Two inputs of the same name in one form is not an error in HTML: PHP
     * keeps the last one, so the page's token would be silently replaced and
     * its validation would start failing. Separate names cannot collide.
     */
    public const FORM_FIELD = 'form_csrf_token';

    /** Request header carrying the token, for callers that cannot post a field. */
    public const HEADER = 'X-LotGD-Csrf';

    public const SCOPE_ARMOR_EDITOR = 'armor_editor';
    public const SCOPE_WEAPON_EDITOR = 'weapon_editor';
    public const SCOPE_MOUNT_EDITOR = 'mount_editor';
    public const SCOPE_COMPANION_EDITOR = 'companion_editor';
    public const SCOPE_CREATURE_EDITOR = 'creature_editor';
    public const SCOPE_MOTD_VOTE = 'motd_vote';

    /**
     * Deliberately separate from SCOPE_MOTD_VOTE. The vote token is rendered
     * for every logged-in player who sees a poll; the editing token belongs to
     * SU_POST_MOTD holders and has no business being emitted that widely.
     */
    public const SCOPE_MOTD_EDIT = 'motd_edit';
    public const SCOPE_TWOFACTORAUTH = 'twofactorauth';
    public const SCOPE_CHARRESTORE = 'charrestore_restore';

    /** The user editor's destructive operations (user.php). */
    public const SCOPE_USER_EDITOR = 'user_editor';

    /**
     * A player deleting their own character (prefs.php).
     *
     * Deliberately its own scope rather than a shared preferences token: it is
     * the only irreversible thing that page can do, and a token minted for
     * saving preferences has no business authorising it.
     */
    public const SCOPE_SELF_DELETE = 'self_delete';

    /**
     * rawsql.php, which executes whatever it is given.
     *
     * Separate from every editor scope on purpose. This one is worth more than
     * all the others put together, so it is never issued by a page that only
     * needs to edit a creature.
     */
    public const SCOPE_RAW_SQL = 'raw_sql';

    /**
     * The async endpoint. Issued once per session when async/setup.php renders
     * the polling client, and carried back in the {@see self::HEADER} request
     * header, because the Jaxon client serialises its own request body.
     */
    public const SCOPE_ASYNC = 'async';

    /**
     * 32 bytes, so every scope gets the same strength. The 2FA module used 16;
     * 128 bits is not breakable either, but an unexplained outlier invites the
     * next person to copy the smaller number.
     */
    private const TOKEN_BYTES = 32;

    /**
     * Return the token for a scope, creating it on first use.
     *
     * Render-side only. Never call this from a validation path — see the class
     * comment on the invariant.
     */
    public static function token(string $scope): string
    {
        $existing = self::peek($scope);
        if ($existing !== null) {
            // Move a token stored under the pre-2.0 flat key into its slot, so
            // the fallback in peek() only ever has to serve sessions that
            // predate this deploy.
            self::store($scope, $existing);

            return $existing;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        self::store($scope, $token);

        return $token;
    }

    /**
     * The scope's token, escaped for an HTML attribute.
     */
    public static function escapedToken(string $scope): string
    {
        return htmlspecialchars(self::token($scope), ENT_QUOTES, 'UTF-8');
    }

    /**
     * A ready-made hidden input carrying the scope's token.
     */
    public static function hiddenField(string $scope, string $field = self::FIELD): string
    {
        return "<input type='hidden' name='"
            . htmlspecialchars($field, ENT_QUOTES, 'UTF-8')
            . "' value='" . self::escapedToken($scope) . "'>";
    }

    /**
     * The stored token, or null when the scope has none.
     *
     * Does not create one, and does not write to the session.
     */
    public static function peek(string $scope): ?string
    {
        $store = self::sessionRef();

        $token = $store['csrf'][$scope] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }

        // Sessions created before the tokens moved under a common key. Kept for
        // one minor release so nobody holding an open form at deploy time eats
        // a rejection; see docs/Deprecations.md.
        $legacy = $store[self::legacyKey($scope)] ?? null;

        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }

    /**
     * Constant-time comparison of a supplied value against the stored token.
     *
     * False when nothing is stored, when the supplied value is not a non-empty
     * string, and when they differ. Anything other than a string — an array
     * from `name[]=x`, null, an int — is a mismatch, not a type error.
     */
    public static function matches(string $scope, mixed $provided): bool
    {
        $expected = self::peek($scope);
        if ($expected === null) {
            return false;
        }

        return is_string($provided) && $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Validate the token posted in a form field.
     */
    public static function validatePost(string $scope, string $field = self::FIELD): bool
    {
        return self::matches($scope, Http::post($field));
    }

    /**
     * Validate the posted token and require the request to be a POST.
     *
     * The method check matters independently of the token: it keeps a
     * state-changing action off a URL that could be put in an `<img>` or a
     * link, where the token would have to travel in the query string.
     */
    public static function validatePostRequest(string $scope, string $field = self::FIELD): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        return self::validatePost($scope, $field);
    }

    /**
     * Read the token a request carries, without validating it.
     *
     * Three transports, in the order they were introduced: a decoded JSON body
     * (passed in, since reading it is the caller's business), a form field, and
     * a request header for callers that cannot add a field to the payload.
     *
     * @param array<string, mixed> $jsonBody Decoded request body, when the caller has one.
     */
    public static function requestToken(
        array $jsonBody = [],
        string $field = self::FIELD,
        ?string $header = self::HEADER
    ): string {
        $fromBody = $jsonBody[$field] ?? '';
        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        $fromPost = Http::post($field);
        if (is_string($fromPost) && $fromPost !== '') {
            return $fromPost;
        }

        if ($header !== null) {
            $fromHeader = $_SERVER[self::headerServerKey($header)] ?? '';
            if (is_string($fromHeader) && $fromHeader !== '') {
                return $fromHeader;
            }
        }

        return '';
    }

    /**
     * Validate a token arriving through any of the supported transports.
     *
     * @param array<string, mixed> $jsonBody Decoded request body, when the caller has one.
     */
    public static function validateRequest(
        string $scope,
        array $jsonBody = [],
        string $field = self::FIELD,
        ?string $header = self::HEADER
    ): bool {
        return self::matches($scope, self::requestToken($jsonBody, $field, $header));
    }

    /**
     * Drop the token field from a posted key/value map.
     *
     * Several pages hand the whole POST body to a setting or preference writer.
     * Without this the token would be persisted as data — configuration.php
     * would write it into the extended settings table. One implementation, so
     * there is a single thing to grep for when a new such page appears.
     *
     * Both token fields go, always: a page that hands its POST body to a
     * writer must not persist either, and a caller naming one explicitly is
     * asking for that field *as well*, not instead. Getting this wrong is
     * silent -- the token becomes a row in the settings table.
     *
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    public static function stripFrom(array $post, string $field = self::FIELD): array
    {
        unset($post[$field], $post[self::FIELD], $post[self::FORM_FIELD]);

        return $post;
    }

    /**
     * Discard a scope's token. The next render issues a new one.
     */
    public static function forget(string $scope): void
    {
        $store = &self::sessionRef();

        unset($store['csrf'][$scope], $store[self::legacyKey($scope)]);
    }

    /**
     * Set a scope's token explicitly.
     *
     * For tests, which need a known value without a real session. Production
     * code has no reason to choose the token.
     */
    public static function seed(string $scope, string $token): void
    {
        self::store($scope, $token);
    }

    /**
     * Session key used before the tokens moved under a common parent.
     */
    private static function legacyKey(string $scope): string
    {
        return $scope . '_csrf';
    }

    /**
     * `$_SERVER` key for a request header, e.g. X-LotGD-Csrf -> HTTP_X_LOTGD_CSRF.
     */
    private static function headerServerKey(string $header): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    }

    private static function store(string $scope, string $token): void
    {
        $store = &self::sessionRef();

        if (!isset($store['csrf']) || !is_array($store['csrf'])) {
            $store['csrf'] = [];
        }

        $store['csrf'][$scope] = $token;
        unset($store[self::legacyKey($scope)]);
    }

    /**
     * Reference to the game session array.
     *
     * There is no session object in this codebase: `$session` is a reference to
     * `$_SESSION['session']` established in common.php, and every caller shares
     * it through `$GLOBALS`. Going through `$GLOBALS` directly rather than
     * `global $session` keeps the reference valid when the class is used before
     * any caller has declared the global, and lets tests set `$GLOBALS['session']`
     * without starting a PHP session.
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
