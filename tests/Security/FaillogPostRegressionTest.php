<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Failed logins must not leave their passwords behind.
 *
 * login.php stored a serialize() of the entire POST body of each failed login
 * in faillog.post, so the table held every password that was tried. It now
 * stores only the name, and a migration clears what older releases wrote.
 * Checked at the source, as the Diagnostics guard is, because the write sits
 * in the middle of a page that is not run in unit tests.
 */
final class FaillogPostRegressionTest extends TestCase
{
    private static function login(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/login.php');
        self::assertIsString($source);

        return $source;
    }

    public function testTheFailedLoginRecordIsNotBuiltFromTheWholePostBody(): void
    {
        $source = self::login();

        self::assertStringNotContainsString('allPost()', $source);
        self::assertStringContainsString("serialize(['name' =>", $source);
    }

    public function testTheLoginPageDoesNotReadTheColumnBack(): void
    {
        $source = self::login();

        self::assertStringNotContainsString('Database::prefix("faillog") . ".*', $source);
        self::assertDoesNotMatchRegularExpression('/SELECT %s\.\*/', $source);
    }

    public function testAMigrationClearsWhatEarlierReleasesStored(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/Version20250724000025.php');
        self::assertIsString($migration);

        self::assertStringContainsString("Database::prefix('faillog')", $migration);
        self::assertStringContainsString("SET post = ''", $migration);
        self::assertStringContainsString('if (! Database::tableExists($table)) {', $migration);
    }
}
