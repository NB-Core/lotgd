<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\PasswordHelper;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the installer-only account password migration.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PasswordMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';
        class_exists(Database::class);

        Database::$tablePrefix = 'lotgd_';
        Database::$doctrineConnection = new DoctrineConnection();
        Output::getInstance();
    }

    protected function tearDown(): void
    {
        Database::$tablePrefix = '';
        Database::$doctrineConnection = null;
        parent::tearDown();
    }

    public function testLegacySqlBundleDoesNotHashPasswordsInDatabase(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/install/data/installer_sqlstatements.php');

        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('/\bMD5\s*\(/i', $source);
    }

    public function testMigrationPreservesHashesAndUpgradesAuthenticatedAncientAdmin(): void
    {
        $bcrypt = PasswordHelper::hash('existing-player-password');
        $doubleMd5 = md5(md5('legacy-player-password'));
        $plaintext = 'ancient-player-password';
        $connection = Database::getDoctrineConnection();
        $connection->fetchAllResults = [[
            ['acctid' => 1, 'password' => $bcrypt, 'password_algo' => PasswordHelper::ALGO_LEGACY],
            ['acctid' => 2, 'password' => $doubleMd5, 'password_algo' => PasswordHelper::ALGO_LEGACY],
            ['acctid' => 3, 'password' => $plaintext, 'password_algo' => PasswordHelper::ALGO_LEGACY],
        ]];

        global $session;
        $session = [
            'installer_admin_credential' => [
                'acctid' => 9,
                'login' => 'AncientAdmin',
                'password' => 'known-admin-password',
                'ancient' => true,
            ],
        ];

        $method = new \ReflectionMethod(Installer::class, 'migrateInstallerAccountPasswords');
        $method->invoke(new Installer());

        $accountUpdates = array_values(array_filter(
            $connection->executeStatements,
            static fn (array $statement): bool => str_starts_with(
                $statement['sql'],
                'UPDATE lotgd_accounts SET'
            )
        ));

        self::assertCount(2, $accountUpdates);
        self::assertSame(
            ['passwordAlgo' => PasswordHelper::ALGO_MODERN, 'acctid' => 1],
            $accountUpdates[0]['params']
        );

        $adminUpdate = $accountUpdates[1];
        self::assertSame(9, $adminUpdate['params']['acctid']);
        self::assertSame('AncientAdmin', $adminUpdate['params']['login']);
        self::assertSame(PasswordHelper::ALGO_MODERN, $adminUpdate['params']['passwordAlgo']);
        self::assertTrue(password_verify('known-admin-password', $adminUpdate['params']['password']));
        self::assertArrayNotHasKey('installer_admin_credential', $session);

        // Neither the double-MD5 player nor the unsupported plaintext player
        // receives an UPDATE; both retain a recovery path instead of corruption.
        self::assertStringContainsString('Forgotten Password flow', Output::getInstance()->getRawOutput());
    }
}
