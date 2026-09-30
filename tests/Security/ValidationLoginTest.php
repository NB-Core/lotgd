<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\Csrf;
use Lotgd\Security\ValidationLogin;
use PHPUnit\Framework\TestCase;

/**
 * The login offered after a validation link.
 *
 * It used to post the account's stored password hash, and login.php took a
 * hash equal to the stored one as a password, so a leaked hash was as good
 * as the password. The grant that replaces it must be single-use, short-lived
 * and bound to one account in one session.
 */
final class ValidationLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session']);
    }

    public function testAGrantNamesTheAccountItWasIssuedFor(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);

        self::assertSame(
            ['acctid' => 42, 'login' => 'Violet', 'passwordReset' => false],
            ValidationLogin::consume($token, 1000)
        );
    }

    public function testTheTokenIsACsrfTokenInItsOwnScope(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);
        self::assertSame($token, Csrf::peek(Csrf::SCOPE_VALIDATION_LOGIN));

        ValidationLogin::consume($token, 1000);
        self::assertNull(Csrf::peek(Csrf::SCOPE_VALIDATION_LOGIN), 'redeeming forgets the scope');
    }

    public function testEveryGrantIssuesAFreshToken(): void
    {
        self::assertNotSame(ValidationLogin::grant(42, 'Violet', 1000), ValidationLogin::grant(42, 'Violet', 1000));
    }

    public function testAGrantIsUsableOnce(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);
        ValidationLogin::consume($token, 1000);

        self::assertNull(ValidationLogin::consume($token, 1000));
    }

    public function testAWrongTokenUsesUpTheGrant(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);

        self::assertNull(ValidationLogin::consume('guess', 1000));
        self::assertNull(ValidationLogin::consume($token, 1000), 'no second try after a wrong token');
    }

    public function testAGrantExpires(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);

        self::assertNull(ValidationLogin::consume($token, 1000 + ValidationLogin::LIFETIME + 1));
    }

    public function testAGrantLastsItsLifetime(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);

        self::assertNotNull(ValidationLogin::consume($token, 1000 + ValidationLogin::LIFETIME));
    }

    public function testOnlyTheLatestGrantCounts(): void
    {
        $first = ValidationLogin::grant(42, 'Violet', 1000);
        $second = ValidationLogin::grant(43, 'Dag', 1000);

        self::assertNull(ValidationLogin::consume($first, 1000));

        $third = ValidationLogin::grant(43, 'Dag', 1000);
        self::assertNotSame($second, $third);
        self::assertSame(43, ValidationLogin::consume($third, 1000)['acctid'] ?? null);
    }

    public function testNothingIsGrantedInAnotherSession(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000);
        $GLOBALS['session'] = [];

        self::assertNull(ValidationLogin::consume($token, 1000));
    }

    public function testOnlyAStringTokenIsAccepted(): void
    {
        // A fresh grant for each case: consume() clears the grant even when
        // it refuses, so a second case would otherwise meet no grant at all.
        $token = ValidationLogin::grant(42, 'Violet', 1000);
        self::assertNull(ValidationLogin::consume([$token], 1000));

        ValidationLogin::grant(42, 'Violet', 1000);
        self::assertNull(ValidationLogin::consume('', 1000));

        ValidationLogin::grant(42, 'Violet', 1000);
        self::assertNull(ValidationLogin::consume(null, 1000));
    }

    public function testAGrantFromAForgottenPasswordLinkSaysSo(): void
    {
        $token = ValidationLogin::grant(42, 'Violet', 1000, true);

        self::assertTrue(ValidationLogin::consume($token, 1000)['passwordReset'] ?? false);
    }

    public function testAPasswordResetIsOpenForItsAccountAndItsLifetimeOnly(): void
    {
        self::assertFalse(ValidationLogin::passwordResetAllowed(42, 1000), 'nothing open by default');

        ValidationLogin::allowPasswordReset(42, 1000);

        self::assertTrue(ValidationLogin::passwordResetAllowed(42, 1000));
        self::assertTrue(ValidationLogin::passwordResetAllowed(42, 1000 + ValidationLogin::RESET_LIFETIME));
        self::assertFalse(ValidationLogin::passwordResetAllowed(42, 1001 + ValidationLogin::RESET_LIFETIME));
        self::assertFalse(ValidationLogin::passwordResetAllowed(43, 1000), 'another account');
    }

    public function testAPasswordResetClosesOnceUsed(): void
    {
        ValidationLogin::allowPasswordReset(42, 1000);
        ValidationLogin::clearPasswordReset();

        self::assertFalse(ValidationLogin::passwordResetAllowed(42, 1000));
    }

    public function testTheButtonCarriesNoPassword(): void
    {
        $html = ValidationLogin::button("O'Brien", 'abc', 'Click "here"');

        self::assertStringNotContainsString("name='password'", $html);
        self::assertStringContainsString("name='" . ValidationLogin::FIELD . "' value='abc'", $html);
        self::assertStringContainsString('O&#039;Brien', $html);
        self::assertStringContainsString('Click &quot;here&quot;', $html);
    }

    public function testLinkTokensAreRandomAndFitTheColumns(): void
    {
        $plain = ValidationLogin::linkToken();
        $marked = ValidationLogin::linkToken('x');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $plain);
        self::assertMatchesRegularExpression('/^x[0-9a-f]{31}$/', $marked);
        self::assertNotSame($plain, ValidationLogin::linkToken());
    }

    /**
     * The stored hash must never again be a way in.
     */
    public function testNoPageOffersOrAcceptsTheHashAsAPassword(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['login.php', 'create.php'] as $page) {
            $source = (string) file_get_contents($root . '/' . $page);
            self::assertStringNotContainsString('!md52!', $source, $page);
        }
    }
}
