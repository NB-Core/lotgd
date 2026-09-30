<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A new password or email address takes the current password, and every
 * password field says which kind it is.
 *
 * prefs.php changed both with nothing but an open session, so whoever held
 * one -- a shared computer, a stolen cookie -- could take the account for
 * good. The pages are checked at the source because they are not run in unit
 * tests; the password rules themselves are exercised in PasswordHelperTest.
 *
 * The autocomplete tokens follow the HTML standard: "current-password" where
 * an existing password is asked for, "new-password" where one is chosen, and
 * "username" beside them, so browsers and password managers fill and offer
 * the right thing.
 */
final class AccountChangeReauthenticationTest extends TestCase
{
    private static function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source, $path);

        return $source;
    }

    public function testThePreferencesFormAsksForTheCurrentPassword(): void
    {
        $prefs = self::source('prefs.php');

        self::assertMatchesRegularExpression('/"oldpass" => "[^"]*,password,current-password"/', $prefs);
        self::assertMatchesRegularExpression('/"pass1" => "[^"]*,password,new-password"/', $prefs);
        self::assertMatchesRegularExpression('/"pass2" => "[^"]*,password,new-password"/', $prefs);
    }

    public function testTheCurrentPasswordIsCheckedAndNeverStoredAsAPreference(): void
    {
        $prefs = self::source('prefs.php');

        self::assertStringContainsString("Http::post('oldpass')", $prefs);
        self::assertStringContainsString('PasswordHelper::matchTyped(', $prefs);
        self::assertStringContainsString('"oldpass" => 1,', $prefs, 'listed in $nonsettings, so it is not saved as a pref');
    }

    public function testNeitherThePasswordNorTheAddressChangesWithoutIt(): void
    {
        $prefs = self::source('prefs.php');

        self::assertStringContainsString('if (!$identityConfirmed) {', $prefs);
        self::assertStringContainsString('if ($identityConfirmed && $email != $session[\'user\'][\'emailaddress\']) {', $prefs);
        self::assertStringContainsString('SecurityLog::event(', $prefs);
    }

    /**
     * A forgotten-password login cannot know the current password, so that
     * session may set a new one once without it -- and only the password.
     */
    public function testAForgottenPasswordLoginMaySetANewPasswordWithoutTheOldOne(): void
    {
        $prefs = self::source('prefs.php');
        self::assertStringContainsString('ValidationLogin::passwordResetAllowed(', $prefs);
        self::assertStringContainsString('if ($changesEmail || ($changesPassword && !$resettingPassword)) {', $prefs);
        self::assertStringContainsString('ValidationLogin::clearPasswordReset();', $prefs);

        self::assertStringContainsString('ValidationLogin::allowPasswordReset(', self::source('login.php'));
        self::assertStringContainsString(
            "ValidationLogin::grant((int) \$row['acctid'], (string) \$row['login'], null, true)",
            self::source('create.php')
        );
    }

    public function testNewPasswordsFollowTheConfiguredMinimum(): void
    {
        foreach (['prefs.php', 'create.php', 'install/lib/Installer.php', 'pages/user/user_save.php'] as $path) {
            self::assertStringContainsString('PasswordHelper::isTooShort(', self::source($path), $path);
        }
    }

    public function testTheBrowserCheckUsesTheSameMinimumAndEscapesItsMessage(): void
    {
        $prefs = self::source('prefs.php');

        self::assertStringContainsString('passbox.value.length < {$minLength}', $prefs);
        self::assertStringContainsString('alert({$warn})', $prefs);
        self::assertStringContainsString('$warn = Escape::js(', $prefs);
    }

    public function testTheUserEditorMarksItsPasswordFieldAsNew(): void
    {
        self::assertStringContainsString(
            '"newpassword" => "New Password,password,new-password"',
            self::source('src/Lotgd/Config/user_account.php')
        );
    }

    public function testRegistrationMarksItsFields(): void
    {
        $create = self::source('create.php');

        self::assertStringContainsString("<input name='name' autocomplete='username'>", $create);
        self::assertStringContainsString("name='pass1' id='pass1' autocomplete='new-password'", $create);
        self::assertStringContainsString("name='pass2' id='pass2' autocomplete='new-password'", $create);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loginTemplates(): iterable
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob($root . '/templates/*.htm') ?: [], glob($root . '/templates_twig/*/login.twig') ?: []);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match("/type=['\"]password['\"]/", $source) === 1) {
                yield substr($file, strlen($root) + 1) => [$file];
            }
        }
    }

    #[DataProvider('loginTemplates')]
    public function testEveryLoginFormMarksItsFields(string $file): void
    {
        $source = (string) file_get_contents($file);

        self::assertMatchesRegularExpression("/autocomplete=['\"]current-password['\"]/", $source);
        self::assertMatchesRegularExpression("/autocomplete=['\"]username['\"]/", $source);
    }
}
