<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\Csrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_LOTGD_CSRF']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['session'] = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_LOTGD_CSRF'], $_SERVER['REQUEST_METHOD']);
    }

    public function testTokenIsCreatedOnceAndIsStableWithinTheRequest(): void
    {
        $first = Csrf::token(Csrf::SCOPE_ARMOR_EDITOR);

        self::assertSame(64, strlen($first), '32 bytes, hex encoded');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame($first, Csrf::token(Csrf::SCOPE_ARMOR_EDITOR));
    }

    public function testScopesAreIndependent(): void
    {
        self::assertNotSame(
            Csrf::token(Csrf::SCOPE_ARMOR_EDITOR),
            Csrf::token(Csrf::SCOPE_WEAPON_EDITOR)
        );
    }

    /**
     * The invariant the rest of the design rests on. If inspection created a
     * token, a mistyped scope would mint one on the spot and then match itself,
     * and a validator running after session_write_close() would compare against
     * a token that is discarded when the request ends.
     */
    public function testInspectionNeverWritesToTheSession(): void
    {
        $before = $GLOBALS['session'];

        self::assertNull(Csrf::peek('never_used'));
        self::assertFalse(Csrf::matches('never_used', 'anything'));
        self::assertFalse(Csrf::validatePost('never_used'));
        self::assertFalse(Csrf::validateRequest('never_used'));
        self::assertSame('', Csrf::requestToken());

        self::assertSame($before, $GLOBALS['session'], 'the session must be untouched');
    }

    /**
     * A scope nobody ever rendered has no token, so it cannot be satisfied.
     * This is what makes a typo fail closed.
     */
    public function testUnknownScopeFailsClosed(): void
    {
        Csrf::token(Csrf::SCOPE_ARMOR_EDITOR);

        self::assertFalse(Csrf::matches('armour_editor', Csrf::token(Csrf::SCOPE_ARMOR_EDITOR)));
    }

    public function testMatchesAcceptsTheStoredToken(): void
    {
        $token = Csrf::token(Csrf::SCOPE_MOTD_VOTE);

        self::assertTrue(Csrf::matches(Csrf::SCOPE_MOTD_VOTE, $token));
    }

    /**
     * @param mixed $provided
     */
    #[DataProvider('provideRejectedValues')]
    public function testMatchesRejects(mixed $provided): void
    {
        Csrf::token(Csrf::SCOPE_MOTD_VOTE);

        self::assertFalse(Csrf::matches(Csrf::SCOPE_MOTD_VOTE, $provided));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideRejectedValues(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'false, as Http::post returns for a missing field' => [false],
            // name[]=x turns the field into an array; hash_equals() would throw.
            'array' => [['x']],
            'integer' => [0],
            'wrong token' => [str_repeat('a', 64)],
        ];
    }

    /**
     * A stored token that is somehow empty must not be satisfiable by an empty
     * submission. mounts.php was the one site missing this check.
     */
    public function testEmptyStoredTokenIsNeverValid(): void
    {
        $GLOBALS['session']['csrf'][Csrf::SCOPE_MOUNT_EDITOR] = '';

        self::assertFalse(Csrf::matches(Csrf::SCOPE_MOUNT_EDITOR, ''));
        self::assertNull(Csrf::peek(Csrf::SCOPE_MOUNT_EDITOR));
    }

    public function testValidatePostReadsTheFormField(): void
    {
        $token = Csrf::token(Csrf::SCOPE_WEAPON_EDITOR);
        $_POST[Csrf::FIELD] = $token;

        self::assertTrue(Csrf::validatePost(Csrf::SCOPE_WEAPON_EDITOR));
    }

    public function testValidatePostRequestRejectsANonPostMethod(): void
    {
        $token = Csrf::token(Csrf::SCOPE_MOUNT_EDITOR);
        $_POST[Csrf::FIELD] = $token;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertFalse(Csrf::validatePostRequest(Csrf::SCOPE_MOUNT_EDITOR));
        self::assertTrue(Csrf::validatePost(Csrf::SCOPE_MOUNT_EDITOR), 'the token itself is fine');
    }

    public function testRequestTokenPrefersJsonBodyThenPostThenHeader(): void
    {
        $_POST[Csrf::FIELD] = 'from-post';
        $_SERVER['HTTP_X_LOTGD_CSRF'] = 'from-header';

        self::assertSame('from-body', Csrf::requestToken([Csrf::FIELD => 'from-body']));

        self::assertSame('from-post', Csrf::requestToken());

        unset($_POST[Csrf::FIELD]);
        self::assertSame('from-header', Csrf::requestToken());

        unset($_SERVER['HTTP_X_LOTGD_CSRF']);
        self::assertSame('', Csrf::requestToken());
    }

    public function testValidateRequestAcceptsTheHeaderTransport(): void
    {
        $token = Csrf::token(Csrf::SCOPE_TWOFACTORAUTH);
        $_SERVER['HTTP_X_LOTGD_CSRF'] = $token;

        self::assertTrue(Csrf::validateRequest(Csrf::SCOPE_TWOFACTORAUTH));
    }

    public function testHeaderTransportCanBeDisabled(): void
    {
        $token = Csrf::token(Csrf::SCOPE_TWOFACTORAUTH);
        $_SERVER['HTTP_X_LOTGD_CSRF'] = $token;

        self::assertFalse(Csrf::validateRequest(Csrf::SCOPE_TWOFACTORAUTH, [], Csrf::FIELD, null));
    }

    public function testHiddenFieldEscapesTheToken(): void
    {
        Csrf::seed(Csrf::SCOPE_ARMOR_EDITOR, 'a"b<c>d\'e&f');

        $markup = Csrf::hiddenField(Csrf::SCOPE_ARMOR_EDITOR);

        self::assertStringContainsString('a&quot;b&lt;c&gt;d&#039;e&amp;f', $markup);
        self::assertStringNotContainsString('a"b<c>', $markup);
        self::assertStringContainsString("name='csrf_token'", $markup);
    }

    /**
     * Sessions that predate the move to a common key keep working, so nobody
     * holding an open form at deploy time is rejected.
     */
    public function testLegacyFlatKeyIsHonouredAndMigrated(): void
    {
        $GLOBALS['session']['twofactorauth_csrf'] = 'legacy-token-value';

        self::assertSame('legacy-token-value', Csrf::peek(Csrf::SCOPE_TWOFACTORAUTH));
        self::assertTrue(Csrf::matches(Csrf::SCOPE_TWOFACTORAUTH, 'legacy-token-value'));

        // Reading must not migrate; only a render does.
        self::assertArrayNotHasKey('csrf', $GLOBALS['session']);

        self::assertSame('legacy-token-value', Csrf::token(Csrf::SCOPE_TWOFACTORAUTH));
        self::assertSame('legacy-token-value', $GLOBALS['session']['csrf'][Csrf::SCOPE_TWOFACTORAUTH]);
        self::assertArrayNotHasKey('twofactorauth_csrf', $GLOBALS['session']);
    }

    /**
     * A 16-byte token from the 2FA module keeps validating; only new tokens are
     * 32 bytes. hash_equals() compares length first, so a shorter stored token
     * is not a problem, it just never matches a longer submission.
     */
    public function testShorterLegacyTokenStillValidates(): void
    {
        $short = bin2hex(random_bytes(16));
        $GLOBALS['session']['twofactorauth_csrf'] = $short;

        self::assertTrue(Csrf::matches(Csrf::SCOPE_TWOFACTORAUTH, $short));
        self::assertFalse(Csrf::matches(Csrf::SCOPE_TWOFACTORAUTH, $short . $short));
    }

    public function testStripFromRemovesOnlyTheTokenField(): void
    {
        $post = ['name' => 'x', Csrf::FIELD => 'secret', 'value' => '1'];

        self::assertSame(['name' => 'x', 'value' => '1'], Csrf::stripFrom($post));
        self::assertArrayHasKey(Csrf::FIELD, $post, 'the caller keeps its own array');
    }

    public function testForgetDropsBothStorageLocations(): void
    {
        Csrf::token(Csrf::SCOPE_COMPANION_EDITOR);
        $GLOBALS['session']['companion_editor_csrf'] = 'stale';

        Csrf::forget(Csrf::SCOPE_COMPANION_EDITOR);

        self::assertNull(Csrf::peek(Csrf::SCOPE_COMPANION_EDITOR));
    }

    public function testTokenWorksWithNoSessionAtAll(): void
    {
        unset($GLOBALS['session']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Csrf::token(Csrf::SCOPE_CHARRESTORE));
    }
}
