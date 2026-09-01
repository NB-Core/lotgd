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
        $connection = Database::getDoctrineConnection();
        $connection->fetchAllResults = [[
            ['acctid' => 1, 'password' => $bcrypt, 'password_algo' => PasswordHelper::ALGO_LEGACY],
        ]];

        global $session;
        $session = [
            'installer_admin_credential' => [
                'acctid' => 9,
                'login' => 'AncientAdmin',
                'password' => 'known-admin-password',
                'verifiedPassword' => md5('known-admin-password'),
                'ancient' => true,
            ],
        ];

        $method = new \ReflectionMethod(Installer::class, 'migrateInstallerAccountPasswords');
        $method->invoke(new Installer());

        $candidateQuery = $connection->fetchAllLog[0];
        self::assertStringContainsString('password LIKE :bcryptPrefix', $candidateQuery['sql']);
        self::assertStringContainsString('LIMIT 500', $candidateQuery['sql']);
        self::assertSame('$2%', $candidateQuery['params']['bcryptPrefix']);

        $accountUpdates = array_values(array_filter(
            $connection->executeStatements,
            static fn (array $statement): bool => str_starts_with(
                $statement['sql'],
                'UPDATE lotgd_accounts SET'
            )
        ));

        self::assertCount(2, $accountUpdates);
        self::assertSame(
            [
                'passwordAlgo' => PasswordHelper::ALGO_MODERN,
                'acctid' => 1,
                'password' => $bcrypt,
            ],
            $accountUpdates[0]['params']
        );

        $adminUpdate = $accountUpdates[1];
        self::assertSame(9, $adminUpdate['params']['acctid']);
        self::assertSame('AncientAdmin', $adminUpdate['params']['login']);
        self::assertSame(md5('known-admin-password'), $adminUpdate['params']['verifiedPassword']);
        self::assertSame(PasswordHelper::ALGO_MODERN, $adminUpdate['params']['passwordAlgo']);
        self::assertTrue(password_verify('known-admin-password', $adminUpdate['params']['password']));
        self::assertArrayNotHasKey('installer_admin_credential', $session);

        // The SQL candidate filter excludes double-MD5 and plaintext players;
        // both retain a recovery path instead of risking hash corruption.
        self::assertStringContainsString('Forgotten Password flow', Output::getInstance()->getRawOutput());
    }

    /**
     * Ensure a password changed after stage-0 authentication is never restored.
     */
    public function testMigrationUsesVerifiedHashAsOptimisticLockForAdminUpdate(): void
    {
        $connection = Database::getDoctrineConnection();
        $connection->fetchAllResults = [[]];
        $connection->executeStatementResults = [0];

        global $session;
        $session = [
            'installer_admin_credential' => [
                'acctid' => 9,
                'login' => 'AncientAdmin',
                'password' => 'obsolete-password',
                'verifiedPassword' => md5('obsolete-password'),
                'ancient' => false,
            ],
        ];

        $method = new \ReflectionMethod(Installer::class, 'migrateInstallerAccountPasswords');
        $method->invoke(new Installer());

        $adminUpdate = $connection->executeStatements[0];
        self::assertStringContainsString('password = :verifiedPassword', $adminUpdate['sql']);
        self::assertSame(md5('obsolete-password'), $adminUpdate['params']['verifiedPassword']);
        self::assertArrayNotHasKey('installer_admin_credential', $session);
    }

    /**
     * Ensure metadata candidates are processed in bounded keyset pages.
     */
    public function testMigrationPaginatesModernHashCandidates(): void
    {
        $connection = Database::getDoctrineConnection();
        $firstPage = [];
        for ($acctid = 1; $acctid <= 500; ++$acctid) {
            $firstPage[] = ['acctid' => $acctid, 'password' => '$2malformed'];
        }
        $connection->fetchAllResults = [$firstPage, []];

        global $session;
        $session = [];

        $method = new \ReflectionMethod(Installer::class, 'migrateInstallerAccountPasswords');
        $method->invoke(new Installer());

        self::assertCount(2, $connection->fetchAllLog);
        self::assertSame(0, $connection->fetchAllLog[0]['params']['lastAcctid']);
        self::assertSame(500, $connection->fetchAllLog[1]['params']['lastAcctid']);
        self::assertSame([], $connection->executeStatements);
    }
}
