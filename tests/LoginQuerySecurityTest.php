<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for login SQL hardening and failed-attempt flow.
 */
final class LoginQuerySecurityTest extends TestCase
{
    private function readLoginScript(): string
    {
        $path = dirname(__DIR__) . '/login.php';

        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testAuthenticationUsesBoundParametersAndTargetedExceptions(): void
    {
        $content = $this->readLoginScript();

        self::assertStringContainsString('WHERE login = :login AND password = :password AND locked = 0', $content);
        self::assertStringContainsString('catch (DbalException | \\mysqli_sql_exception $exception)', $content);

        self::assertStringNotContainsString("WHERE login = '$name'", $content);
        self::assertStringNotContainsString("password='$password'", $content);
    }

    public function testFailedLoginPathKeepsGenericMessageAndCountsQueryFailures(): void
    {
        $content = $this->readLoginScript();

        self::assertStringContainsString('`4Error, your login was incorrect`0', $content);
        self::assertStringContainsString('if (count($failedAccounts) > 0 || $authQueryFailed)', $content);
        self::assertStringContainsString('WHERE ip = :ip AND date > :cutoff', $content);
        self::assertStringContainsString('if ($c >= 10)', $content);
    }

    public function testFailedLoginInsertUsesExplicitFaillogColumnsAndCookieIdField(): void
    {
        $content = $this->readLoginScript();

        self::assertStringContainsString('(date, post, ip, acctid, id)', $content);
        self::assertStringNotContainsString('(date, post, ip, acctid, lgi)', $content);
        self::assertStringContainsString('INSERT INTO %s (date, post, ip, acctid, id) VALUES', $content);
    }

    public function testFailedLoginLoggingErrorsAreHandledWithoutLeakingDatabaseErrors(): void
    {
        $content = $this->readLoginScript();

        self::assertStringContainsString('Logging should never break login UX.', $content);
        self::assertStringContainsString('catch (DbalException | \\mysqli_sql_exception $exception)', $content);
        self::assertStringContainsString("Translator::translateInline(\"`4Error, your login was incorrect`0\")", $content);
        self::assertStringNotContainsString('$exception->getMessage()', $content);
    }
}
