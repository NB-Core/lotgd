<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules\Injection;

use Lotgd\Modules;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * A module file whose modification time changed while its content did not
 * -- every file after a new image or checkout -- is not upgraded on its first
 * page: no lock, no _install(), only its stored time is brought up to date.
 * A changed file, a reinstall and a row from before the column still upgrade.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class InjectModuleContentHashTest extends TestCase
{
    private string $moduleDir;
    private string $moduleFile;
    private string $origCwd;
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
        $this->connection = new DoctrineConnection();
        \Lotgd\MySQL\Database::setDoctrineConnection($this->connection);

        $this->origCwd = (string) getcwd();
        $this->moduleDir = sys_get_temp_dir() . '/lotgd_module_' . uniqid();
        mkdir($this->moduleDir . '/modules', 0777, true);
        $this->moduleFile = $this->moduleDir . '/modules/hashModule.php';
        file_put_contents($this->moduleFile, <<<'PHP'
<?php
function hashModule_getmoduleinfo(): array { return ['name' => 'Hash Module', 'version' => '1.0']; }
function hashModule_install(): bool { $GLOBALS['hashModuleInstalls'] = ($GLOBALS['hashModuleInstalls'] ?? 0) + 1; return true; }
function hashModule_uninstall(): bool { return true; }
PHP);
        $GLOBALS['hashModuleInstalls'] = 0;
        Modules::resetInjectedModules();
    }

    protected function tearDown(): void
    {
        @unlink($this->moduleFile);
        @rmdir($this->moduleDir . '/modules');
        @rmdir($this->moduleDir);
        chdir($this->origCwd);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function inject(array $row): void
    {
        $row += ['active' => 1, 'infokeys' => '|name|version|', 'version' => '1.0'];
        // Twice: the stub's fetchAssoc() takes the row out of the result,
        // and inject() asks numRows() after it, as a real result allows.
        \Lotgd\MySQL\Database::$queryCacheResults['inject-hashModule'] = [$row, $row];
        // The row the upgrade reads again under its lock.
        $this->connection->queryResultsByPattern['/SELECT \* FROM modules WHERE modulename = :modulename/'] = [$row];
        chdir($this->moduleDir);
        self::assertTrue(Modules::inject('hashModule'));
        chdir($this->origCwd);
    }

    /**
     * @return list<string>
     */
    private function statements(): array
    {
        // The stub records every statement in $queries, writes in
        // $executeStatements as well.
        return $this->connection->queries;
    }

    public function testAFileOnlyTouchedIsNotUpgraded(): void
    {
        $this->inject(['filemoddate' => '2020-01-01 00:00:00', 'filehash' => sha1_file($this->moduleFile)]);

        self::assertSame(0, $GLOBALS['hashModuleInstalls'], 'No _install().');
        self::assertSame([], preg_grep('/LOCK TABLES/', $this->statements()));
        $update = preg_grep('/UPDATE modules SET filemoddate = :filemoddate WHERE modulename = :modulename/', $this->statements());
        self::assertCount(1, $update);
    }

    public function testAChangedFileIsUpgradedAndItsContentRemembered(): void
    {
        $this->inject(['filemoddate' => '2020-01-01 00:00:00', 'filehash' => str_repeat('0', 40)]);

        self::assertSame(1, $GLOBALS['hashModuleInstalls']);
        self::assertNotSame([], preg_grep('/LOCK TABLES modules WRITE/', $this->statements()));
        $stored = array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $s): bool => str_contains((string) $s['sql'], 'SET filehash = :filehash')
        ));
        self::assertCount(1, $stored);
        self::assertSame(sha1_file($this->moduleFile), $stored[0]['params']['filehash']);
    }

    public function testAReinstallUpgradesEvenWithTheSameContent(): void
    {
        $this->inject(['filemoddate' => DATETIME_DATEMIN, 'filehash' => sha1_file($this->moduleFile)]);

        self::assertSame(1, $GLOBALS['hashModuleInstalls']);
    }

    public function testARowFromBeforeTheColumnUpgradesAsBefore(): void
    {
        $this->inject(['filemoddate' => '2020-01-01 00:00:00']);

        self::assertSame(1, $GLOBALS['hashModuleInstalls']);
        self::assertSame([], preg_grep('/filehash/', $this->statements()), 'No column to write to.');
    }

    public function testAnUpToDateModuleWithoutAHashRemembersItOnce(): void
    {
        $this->inject(['filemoddate' => date('Y-m-d H:i:s', (int) filemtime($this->moduleFile)), 'filehash' => null]);

        self::assertSame(0, $GLOBALS['hashModuleInstalls']);
        self::assertCount(1, preg_grep('/SET filehash = :filehash/', $this->statements()));
        self::assertSame([], preg_grep('/LOCK TABLES/', $this->statements()));
    }

    public function testAnUpToDateModuleWithAHashWritesNothing(): void
    {
        $this->inject(['filemoddate' => date('Y-m-d H:i:s', (int) filemtime($this->moduleFile)), 'filehash' => sha1_file($this->moduleFile)]);

        self::assertSame(0, $GLOBALS['hashModuleInstalls']);
        self::assertSame([], $this->connection->executeStatements);
    }
}
