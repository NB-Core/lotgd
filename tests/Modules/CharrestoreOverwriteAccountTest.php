<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Doctrine\DBAL\DriverManager;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The Character Restorer's destructive "restore anyway over the current user".
 *
 * It writes a snapshot's account fields and module preferences over an account
 * that still exists, keeping its id. Both writes happen in one transaction: the
 * preference upsert, which otherwise opens and commits a transaction of its own,
 * joins it instead, so a preference that cannot be written takes the account
 * update back with it.
 *
 * SQLite stands in for MySQL, with the three statements the module issues that
 * SQLite does not speak translated by {@see MysqlishConnection}.
 *
 * @group modules
 */
final class CharrestoreOverwriteAccountTest extends TestCase
{
    private MysqlishConnection $connection;

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$tablePrefix = '';

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => MysqlishConnection::class,
        ]);
        self::assertInstanceOf(MysqlishConnection::class, $connection);
        $this->connection = $connection;

        $connection->executeStatement('CREATE TABLE accounts (acctid INTEGER PRIMARY KEY, login TEXT, name TEXT, gold INTEGER)');
        $connection->executeStatement(
            'CREATE TABLE module_userprefs (userid INTEGER, modulename TEXT, setting TEXT, value TEXT, UNIQUE (userid, modulename, setting))'
        );
        $connection->executeStatement(
            'CREATE TABLE gamelog (logid INTEGER PRIMARY KEY, message TEXT, category TEXT, severity TEXT, filed INTEGER, date TEXT, who INTEGER)'
        );
        $connection->executeStatement("INSERT INTO accounts VALUES (5, 'hero', 'Hero Now', 1)");
        $connection->executeStatement("INSERT INTO module_userprefs VALUES (5, 'inventory', 'slots', '3'), (5, 'inventory', 'newer', 'keep')");

        Database::$doctrineConnection = $connection;
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
    }

    private static function loadModule(): void
    {
        if (!function_exists('db_prefix')) {
            eval('function db_prefix($name, $force = null) { return \\Lotgd\\MySQL\\Database::prefix((string) $name); }');
        }
        if (!function_exists('is_module_installed')) {
            eval('function is_module_installed(string $modulename, string|false $version = false): bool { return true; }');
        }
        $root = dirname(__DIR__, 2) . '/modules';
        require_once $root . '/charrestore.php';
        require_once $root . '/charrestore/lib/snapshot.php';
        require_once $root . '/charrestore/lib/restore.php';
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotAccount(): array
    {
        return ['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => '250', 'retiredcolumn' => 'gone'];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function account(): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM accounts WHERE acctid = 5');
    }

    /**
     * @return array<string, string>
     */
    private function prefs(): array
    {
        $prefs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT setting, value FROM module_userprefs WHERE userid = 5') as $row) {
            $prefs[$row['setting']] = $row['value'];
        }
        ksort($prefs);

        return $prefs;
    }

    #[RunInSeparateProcess]
    public function testTheSnapshotIsWrittenOverTheAccountItCameFrom(): void
    {
        self::loadModule();

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertTrue($restored);
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => 250], $this->account());
        $this->assertSame(['newer' => 'keep', 'slots' => '7'], $this->prefs(), 'a preference the snapshot does not know stays');
        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertSame(1, $this->connection->commits, 'one transaction, committed once');
    }

    #[RunInSeparateProcess]
    public function testAPreferenceThatCannotBeWrittenTakesTheAccountUpdateBackWithIt(): void
    {
        self::loadModule();
        $this->connection->failPreferenceWrites = true;

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertFalse($restored);
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Now', 'gold' => 1], $this->account());
        $this->assertSame(['newer' => 'keep', 'slots' => '3'], $this->prefs());
        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertSame(0, $this->connection->commits);
        $this->assertSame(
            ['error'],
            array_column(
                $this->connection->fetchAllAssociative("SELECT severity FROM gamelog WHERE message LIKE 'Full account overwrite failed%'"),
                'severity'
            ),
            'the failure is logged after the rollback, so the entry survives it'
        );
    }

    #[RunInSeparateProcess]
    public function testAnAccountThatNoLongerExistsIsNotCreated(): void
    {
        self::loadModule();
        $this->connection->executeStatement('DELETE FROM accounts');

        $this->assertFalse(charrestore_restore_overwrite_account(self::snapshotAccount(), [], ['owner' => 'charrestore']));
        $this->assertFalse($this->account());
    }

    #[RunInSeparateProcess]
    public function testOnItsOwnThePreferenceUpsertStillCommitsItsOwnTransaction(): void
    {
        self::loadModule();

        $result = charrestore_restore_prefs_upsert($this->connection, 5, ['inventory' => ['slots' => '9']]);

        $this->assertTrue($result['applied']);
        $this->assertSame(['newer' => 'keep', 'slots' => '9'], $this->prefs());
        $this->assertSame(1, $this->connection->commits);
    }
}
