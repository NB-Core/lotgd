<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ModulesMultibyteFormalnameTest extends TestCase
{
    public function testInsertAndRetrieveMultibyteFormalname(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension not installed');
        }

        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $conn->executeStatement('CREATE TABLE modules (modulename VARCHAR(255), formalname VARCHAR(255))');

        $moduleName = 'example';
        $formalName = '多字節モジュール';

        $affected = $conn->insert('modules', [
            'modulename' => $moduleName,
            'formalname' => $formalName,
        ]);

        $this->assertSame(1, $affected);

        $retrieved = $conn->fetchOne('SELECT formalname FROM modules WHERE modulename = ?', [$moduleName]);

        $this->assertSame($formalName, $retrieved);
    }
}
