<?php

declare(strict_types=1);

namespace {
    if (!function_exists('module_addhook')) {
        function module_addhook(string $hookname, $functioncall = false, $whenactive = false): void
        {
        }
    }
}

namespace Lotgd\Tests\Modules {

use Lotgd\Tests\Stubs\Database as DatabaseStub;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DoctrineResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/specialtydarkarts.php';
require_once __DIR__ . '/../../modules/specialtythiefskills.php';
require_once __DIR__ . '/../../modules/specialtymysticpower.php';
require_once __DIR__ . '/../../modules/specialtychickenmage.php';

/**
 * @group modules
 * @group specialty
 */
final class SpecialtyMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseStub::$doctrineConnection = null;
        if (class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            \Lotgd\Doctrine\Bootstrap::$conn = null;
        }
    }

    public function testDarkArtsInstallMigratesLegacyColumns(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'darkarts' => 5, 'darkartuses' => 2, 'specialty' => '1'],
                ['acctid' => 2, 'darkarts' => 7, 'darkartuses' => 3, 'specialty' => '0'],
            ],
            [
                ['Field' => 'acctid'],
                ['Field' => 'darkarts'],
                ['Field' => 'darkartuses'],
            ]
        );

        $this->useConnection($connection);

        specialtydarkarts_install();

        $this->assertSame(
            [
                ['modulename' => 'specialtydarkarts', 'setting' => 'skill', 'userid' => 1, 'value' => 5],
                ['modulename' => 'specialtydarkarts', 'setting' => 'skill', 'userid' => 2, 'value' => 7],
                ['modulename' => 'specialtydarkarts', 'setting' => 'uses', 'userid' => 1, 'value' => 2],
                ['modulename' => 'specialtydarkarts', 'setting' => 'uses', 'userid' => 2, 'value' => 3],
            ],
            $connection->moduleUserPrefs
        );

        $this->assertSame('DA', $connection->accounts[0]['specialty']);
        $this->assertSame('0', $connection->accounts[1]['specialty']);
        $this->assertArrayNotHasKey('darkarts', $connection->accounts[0]);
        $this->assertArrayNotHasKey('darkartuses', $connection->accounts[0]);
    }

    public function testDarkArtsUninstallClearsSpecialty(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'specialty' => 'DA'],
                ['acctid' => 2, 'specialty' => 'TS'],
            ],
            []
        );

        $this->useConnection($connection);

        specialtydarkarts_uninstall();

        $this->assertSame('', $connection->accounts[0]['specialty']);
        $this->assertSame('TS', $connection->accounts[1]['specialty']);
    }

    public function testThiefSkillsInstallMigratesLegacyColumns(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'thievery' => 4, 'thieveryuses' => 1, 'specialty' => '3'],
                ['acctid' => 2, 'thievery' => 9, 'thieveryuses' => 5, 'specialty' => '0'],
            ],
            [
                ['Field' => 'acctid'],
                ['Field' => 'thievery'],
                ['Field' => 'thieveryuses'],
            ]
        );

        $this->useConnection($connection);

        specialtythiefskills_install();

        $this->assertSame(
            [
                ['modulename' => 'specialtythiefskills', 'setting' => 'skill', 'userid' => 1, 'value' => 4],
                ['modulename' => 'specialtythiefskills', 'setting' => 'skill', 'userid' => 2, 'value' => 9],
                ['modulename' => 'specialtythiefskills', 'setting' => 'uses', 'userid' => 1, 'value' => 1],
                ['modulename' => 'specialtythiefskills', 'setting' => 'uses', 'userid' => 2, 'value' => 5],
            ],
            $connection->moduleUserPrefs
        );

        $this->assertSame('TS', $connection->accounts[0]['specialty']);
        $this->assertSame('0', $connection->accounts[1]['specialty']);
        $this->assertArrayNotHasKey('thievery', $connection->accounts[0]);
        $this->assertArrayNotHasKey('thieveryuses', $connection->accounts[0]);
    }

    public function testThiefSkillsUninstallClearsSpecialty(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'specialty' => 'TS'],
                ['acctid' => 2, 'specialty' => 'CM'],
            ],
            []
        );

        $this->useConnection($connection);

        specialtythiefskills_uninstall();

        $this->assertSame('', $connection->accounts[0]['specialty']);
        $this->assertSame('CM', $connection->accounts[1]['specialty']);
    }

    public function testMysticPowerInstallMigratesLegacyColumns(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'magic' => 8, 'magicuses' => 6, 'specialty' => '2'],
                ['acctid' => 2, 'magic' => 3, 'magicuses' => 1, 'specialty' => '0'],
            ],
            [
                ['Field' => 'acctid'],
                ['Field' => 'magic'],
                ['Field' => 'magicuses'],
            ]
        );

        $this->useConnection($connection);

        specialtymysticpower_install();

        $this->assertSame(
            [
                ['modulename' => 'specialtymysticpower', 'setting' => 'skill', 'userid' => 1, 'value' => 8],
                ['modulename' => 'specialtymysticpower', 'setting' => 'skill', 'userid' => 2, 'value' => 3],
                ['modulename' => 'specialtymysticpower', 'setting' => 'uses', 'userid' => 1, 'value' => 6],
                ['modulename' => 'specialtymysticpower', 'setting' => 'uses', 'userid' => 2, 'value' => 1],
            ],
            $connection->moduleUserPrefs
        );

        $this->assertSame('MP', $connection->accounts[0]['specialty']);
        $this->assertSame('0', $connection->accounts[1]['specialty']);
        $this->assertArrayNotHasKey('magic', $connection->accounts[0]);
        $this->assertArrayNotHasKey('magicuses', $connection->accounts[0]);
    }

    public function testMysticPowerUninstallClearsSpecialty(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'specialty' => 'MP'],
                ['acctid' => 2, 'specialty' => 'DA'],
            ],
            []
        );

        $this->useConnection($connection);

        specialtymysticpower_uninstall();

        $this->assertSame('', $connection->accounts[0]['specialty']);
        $this->assertSame('DA', $connection->accounts[1]['specialty']);
    }

    public function testChickenMageUninstallClearsSpecialty(): void
    {
        $connection = $this->createConnection(
            [
                ['acctid' => 1, 'specialty' => 'CM'],
                ['acctid' => 2, 'specialty' => 'MP'],
            ],
            []
        );

        $this->useConnection($connection);

        specialtychickenmage_uninstall();

        $this->assertSame('', $connection->accounts[0]['specialty']);
        $this->assertSame('MP', $connection->accounts[1]['specialty']);
    }

    private function createConnection(array $accounts, array $describeRows): SpecialtyMigrationConnection
    {
        return new SpecialtyMigrationConnection($accounts, $describeRows);
    }

    private function useConnection(SpecialtyMigrationConnection $connection): void
    {
        DatabaseStub::$doctrineConnection = $connection;
        if (class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            \Lotgd\Doctrine\Bootstrap::$conn = $connection;
        }
    }
}

final class SpecialtyMigrationConnection extends DoctrineConnection
{
    /** @var array<int,array<string,mixed>> */
    public array $accounts;

    /** @var array<int,array<string,mixed>> */
    public array $moduleUserPrefs = [];

    /** @var array<int,array<string,mixed>> */
    private array $describeRows;

    public function __construct(array $accounts, array $describeRows)
    {
        $this->accounts = $accounts;
        $this->describeRows = $describeRows;
    }

    public function executeQuery(string $sql): DoctrineResult
    {
        $this->queries[] = $sql;

        if (stripos($sql, 'DESCRIBE ') === 0) {
            return new DoctrineResult($this->describeRows);
        }

        return new DoctrineResult();
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        $this->queries[] = $sql;

        if (preg_match('/INSERT INTO\s+module_userprefs.*SELECT\s+\?,\s+\?,\s+acctid,\s+([a-z0-9_]+)\s+FROM\s+accounts/i', $sql, $matches)) {
            $column = $matches[1];
            $modulename = $params[0] ?? '';
            $setting = $params[1] ?? '';
            foreach ($this->accounts as $row) {
                $this->moduleUserPrefs[] = [
                    'modulename' => $modulename,
                    'setting'    => $setting,
                    'userid'     => $row['acctid'],
                    'value'      => $row[$column] ?? null,
                ];
            }

            return count($this->accounts);
        }

        if (preg_match('/ALTER TABLE\s+accounts\s+DROP\s+([a-z0-9_]+)/i', $sql, $matches)) {
            $column = $matches[1];
            foreach ($this->accounts as &$row) {
                unset($row[$column]);
            }

            return 0;
        }

        if (preg_match('/UPDATE\s+accounts\s+SET\s+specialty\s*=\s*\?\s+WHERE\s+specialty\s*=\s*\?/i', $sql)) {
            $new = $params[0] ?? '';
            $old = $params[1] ?? '';
            $affected = 0;
            foreach ($this->accounts as &$row) {
                if (($row['specialty'] ?? null) === $old) {
                    $row['specialty'] = $new;
                    $affected++;
                }
            }

            return $affected;
        }

        return 0;
    }
}
}
