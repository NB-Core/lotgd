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

        $connection->executeStatement('CREATE TABLE accounts (acctid INTEGER PRIMARY KEY, login TEXT, name TEXT, gold INTEGER, loggedin INTEGER)');
        $connection->executeStatement(
            'CREATE TABLE module_userprefs (userid INTEGER, modulename TEXT, setting TEXT, value TEXT, UNIQUE (userid, modulename, setting))'
        );
        $connection->executeStatement(
            'CREATE TABLE gamelog (logid INTEGER PRIMARY KEY, message TEXT, category TEXT, severity TEXT, filed INTEGER, date TEXT, who INTEGER)'
        );
        $connection->executeStatement("INSERT INTO accounts VALUES (5, 'hero', 'Hero Now', 1, 1)");
        $connection->executeStatement("INSERT INTO module_userprefs VALUES (5, 'inventory', 'slots', '3'), (5, 'inventory', 'newer', 'keep')");

        Database::$doctrineConnection = $connection;
        // The administrator running the restore.
        $GLOBALS['session'] = ['user' => ['acctid' => 9]];
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    /**
     * What reached PHP's error log during the test so far. PHPUnit points the
     * log at a capture file of its own for every test; SecurityLog writes its
     * line there as well as to the game log.
     */
    private function errorLog(): string
    {
        return (string) file_get_contents((string) ini_get('error_log'));
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
        return ['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => '250', 'loggedin' => '1', 'retiredcolumn' => 'gone'];
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
        $this->expectErrorLog();

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertTrue($restored);
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => 250, 'loggedin' => 0], $this->account());
        $this->assertSame(['newer' => 'keep', 'slots' => '7'], $this->prefs(), 'a preference the snapshot does not know stays');
        $this->assertSame(0, $this->account()['loggedin'] ?? null, 'logged out, though the snapshot was taken logged in');
        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertSame(1, $this->connection->commits, 'one transaction, committed once');
        $security = $this->connection->fetchAllAssociative(
            "SELECT message, severity, who FROM gamelog WHERE category = 'security'"
        );
        $this->assertCount(1, $security, 'replacing a live account is a security outcome');
        $this->assertStringContainsString('target_acctid=5', $security[0]['message']);
        $this->assertStringContainsString('transactional=yes', $security[0]['message']);
        $this->assertSame(['warning', 9], [$security[0]['severity'], (int) $security[0]['who']], 'filed under the admin who did it');
        $this->assertStringContainsString('[security] Character Restorer overwrote an existing account', $this->errorLog());
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
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Now', 'gold' => 1, 'loggedin' => 1], $this->account());
        $this->assertSame([], $this->connection->fetchAllAssociative("SELECT logid FROM gamelog WHERE category = 'security'"));
        $this->assertSame(['newer' => 'keep', 'slots' => '3'], $this->prefs());
        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertSame(0, $this->connection->commits);
        $logged = $this->connection->fetchAllAssociative('SELECT message, severity FROM gamelog');
        $this->assertCount(1, $logged, 'the failure is logged after the rollback, so the entry survives it');
        $this->assertSame('error', $logged[0]['severity']);
        $this->assertStringStartsWith('Full account overwrite failed for acctid 5; changes were rolled back', $logged[0]['message']);
        $this->assertStringContainsString(
            'The preference could not be written.',
            $logged[0]['message'],
            'the reason, which a row written inside the transaction would have lost, comes with it'
        );
    }

    /**
     * MySQL does not roll back a MyISAM table, and an installation upgraded
     * from an old release can still have one. The restore is not withheld
     * there; the security entry records that it could not have been undone.
     */
    #[RunInSeparateProcess]
    public function testTablesThatCannotRollBackStillOverwrite(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $this->connection->nonTransactionalTables = ['module_userprefs'];

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertTrue($restored);
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => 250, 'loggedin' => 0], $this->account());
        $this->assertSame(['newer' => 'keep', 'slots' => '7'], $this->prefs());
        $security = $this->connection->fetchAllAssociative("SELECT message FROM gamelog WHERE category = 'security'");
        $this->assertCount(1, $security);
        $this->assertStringStartsWith('Character Restorer overwrote an existing account', $security[0]['message']);
        $this->assertStringContainsString('transactional=no', $security[0]['message']);
    }

    /**
     * Where a failure cannot be rolled back, part of the snapshot, credentials
     * included, may already be written. The failure must not read as "nothing
     * changed", and it is a security outcome.
     *
     * SQLite does roll back, so what is checked is what gets reported.
     */
    #[RunInSeparateProcess]
    public function testAFailureOnTablesThatCannotRollBackSaysSo(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $this->connection->nonTransactionalTables = ['accounts'];
        $this->connection->failPreferenceWrites = true;

        $mayBePartial = null;
        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore'],
            $mayBePartial
        );

        $this->assertFalse($restored);
        $this->assertTrue($mayBePartial, 'the account row was written before the preference failed');
        $failure = $this->connection->fetchAllAssociative(
            "SELECT message, severity FROM gamelog WHERE message LIKE 'Full account overwrite failed%'"
        );
        $this->assertCount(1, $failure);
        $this->assertSame('error', $failure[0]['severity']);
        $this->assertStringContainsString('cannot roll back, so part of the snapshot may already be written', $failure[0]['message']);
        $this->assertStringNotContainsString('changes were rolled back', $failure[0]['message']);
        $this->assertStringContainsString('The preference could not be written.', $failure[0]['message']);
        $security = $this->connection->fetchAllAssociative(
            "SELECT message, severity, who FROM gamelog WHERE category = 'security'"
        );
        $this->assertCount(1, $security);
        $this->assertStringStartsWith('Character Restorer overwrite failed on tables that cannot roll back', $security[0]['message']);
        $this->assertStringContainsString('target_acctid=5', $security[0]['message']);
        $this->assertSame(['error', 9], [$security[0]['severity'], (int) $security[0]['who']]);
    }

    #[RunInSeparateProcess]
    public function testTheEngineCheckNeedsBothTablesToRollBack(): void
    {
        self::loadModule();

        $this->assertTrue(charrestore_restore_tables_transactional($this->connection));
        $this->connection->nonTransactionalTables = ['accounts'];
        $this->assertFalse(charrestore_restore_tables_transactional($this->connection));
        $this->connection->nonTransactionalTables = [];
        $this->connection->executeStatement('DROP TABLE module_userprefs');
        $this->assertFalse(charrestore_restore_tables_transactional($this->connection), 'a table it cannot see counts as no');
    }

    /**
     * A failure before anything was written is not a partial write, whatever
     * the engine: the account is unchanged and the page must say so.
     */
    #[RunInSeparateProcess]
    public function testAFailureBeforeAnyWriteIsNotReportedAsPartial(): void
    {
        self::loadModule();
        $this->connection->nonTransactionalTables = ['accounts'];
        $this->connection->executeStatement('DELETE FROM accounts');

        $mayBePartial = null;
        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore'],
            $mayBePartial
        );

        $this->assertFalse($restored);
        $this->assertFalse($mayBePartial);
        $this->assertSame([], $this->connection->fetchAllAssociative("SELECT logid FROM gamelog WHERE category = 'security'"));
        $failure = $this->connection->fetchAllAssociative(
            "SELECT message FROM gamelog WHERE message LIKE 'Full account overwrite failed%'"
        );
        $this->assertCount(1, $failure);
        $this->assertStringContainsString('nothing had been written yet', $failure[0]['message']);
    }

    /**
     * A rollback that fails too, on a lost connection, must not cut the
     * reporting short: the caller still gets false, the partial write is
     * still filed, and the failed rollback is part of the message.
     */
    #[RunInSeparateProcess]
    public function testAFailedRollbackDoesNotCutThePartialReportShort(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $this->connection->nonTransactionalTables = ['accounts'];
        $this->connection->failPreferenceWrites = true;
        $this->connection->failRollback = true;

        $mayBePartial = null;
        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore'],
            $mayBePartial
        );

        $this->assertFalse($restored);
        $this->assertTrue($mayBePartial);
        $this->assertStringContainsString(
            '[security] Character Restorer overwrite failed on tables that cannot roll back',
            $this->errorLog()
        );
        $failure = $this->connection->fetchAllAssociative(
            "SELECT message FROM gamelog WHERE message LIKE 'Full account overwrite failed%'"
        );
        $this->assertCount(1, $failure);
        $this->assertStringContainsString('the rollback itself failed: The connection was lost.', $failure[0]['message']);
    }

    /**
     * A failure that may have left the account partly written is reported
     * even when the game log is gone: the security event and the failure both
     * reach PHP's error log, and the caller still gets false.
     */
    #[RunInSeparateProcess]
    public function testAPartialFailureIsReportedWhenTheGameLogRefusesIt(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $this->connection->nonTransactionalTables = ['accounts'];
        $this->connection->failPreferenceWrites = true;
        $this->connection->executeStatement('DROP TABLE gamelog');

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertFalse($restored);
        $log = $this->errorLog();
        $this->assertStringContainsString('[security] Character Restorer overwrite failed on tables that cannot roll back', $log);
        $this->assertStringContainsString('[charrestore] Full account overwrite failed for acctid 5; the tables cannot roll back', $log);
    }

    /**
     * The security entry is written after the commit. A game log that cannot
     * take it does not undo the overwrite, so it must not be reported as one
     * that was rolled back.
     */
    #[RunInSeparateProcess]
    public function testAGameLogThatRefusesTheSecurityEntryDoesNotTurnTheOverwriteIntoAFailure(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $this->connection->executeStatement('DROP TABLE gamelog');

        $restored = charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        );

        $this->assertTrue($restored, 'the overwrite was committed');
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Then', 'gold' => 250, 'loggedin' => 0], $this->account());
        $this->assertSame(1, $this->connection->commits);
        $log = $this->errorLog();
        $this->assertStringContainsString('[security] Character Restorer overwrote an existing account', $log, 'the event is on record');
        $this->assertStringContainsString('Account 5 was overwritten, but the game log refused its security entry', $log);
    }

    /**
     * Replacing a live account is a destructive operation, and AGENTS.md has
     * those triggered by a post button with a confirmation, never by a box
     * ticked in a form that otherwise does something harmless.
     */
    public function testTheOverwriteIsAConfirmedButtonOfItsOwn(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/charrestore/lib/restore.php');

        $this->assertStringNotContainsString("name='overwriteaccount'", $source);
        $this->assertMatchesRegularExpression(
            "/Forms::postButton\\([^;]*translate_inline\\('Overwrite the current user[^;]*SCOPE_CHARRESTORE,\\s*array\\('overwriteaccount' => '1'\\)/s",
            $source
        );
    }

    /**
     * The save at the end of the administrator's own request would write their
     * account as it was loaded back over the snapshot.
     */
    #[RunInSeparateProcess]
    public function testTheAdministratorsOwnAccountIsRefused(): void
    {
        self::loadModule();
        $this->expectErrorLog();
        $GLOBALS['session']['user']['acctid'] = 5;

        $this->assertFalse(charrestore_restore_overwrite_account(
            self::snapshotAccount(),
            ['inventory' => ['slots' => '7']],
            ['owner' => 'charrestore']
        ));
        $this->assertSame(['acctid' => 5, 'login' => 'hero', 'name' => 'Hero Now', 'gold' => 1, 'loggedin' => 1], $this->account());
        $this->assertSame(0, $this->connection->commits);
        $this->assertRefusalFiled('the account running the restore', 5);
    }

    /**
     * A refused overwrite is a denied admin action: one security entry, under
     * the administrator who asked, naming the account and the reason.
     */
    private function assertRefusalFiled(string $reason, int $administrator = 9): void
    {
        $security = $this->connection->fetchAllAssociative(
            "SELECT message, severity, who FROM gamelog WHERE category = 'security'"
        );
        $this->assertCount(1, $security);
        $this->assertStringStartsWith('Character Restorer refused to overwrite an existing account', $security[0]['message']);
        $this->assertStringContainsString('target_acctid=5', $security[0]['message']);
        $this->assertStringContainsString($reason, $security[0]['message']);
        $this->assertSame(['warning', $administrator], [$security[0]['severity'], (int) $security[0]['who']]);
        $this->assertStringContainsString('[security] Character Restorer refused to overwrite', $this->errorLog());
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
