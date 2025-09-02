<?php

declare(strict_types=1);

namespace Lotgd\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Lotgd\Migrations\Version20250724000019;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Doctrine\DBAL\Schema\Schema;

final class ModuleHooksRenameTest extends TestCase
{
    public function testUpRenamesColumnAndAdjustsPrimaryKey(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20250724000019.php';

        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $migration = new Version20250724000019($connection, new NullLogger());
        $migration->up(new Schema());

        $sql = array_map(static fn($query) => $query->getStatement(), $migration->getSql());

        $expected = [
            'ALTER TABLE module_hooks DROP PRIMARY KEY',
            'ALTER TABLE module_hooks CHANGE COLUMN `function` hook_callback VARCHAR(50) NOT NULL',
            'ALTER TABLE module_hooks ADD PRIMARY KEY (modulename, location, hook_callback)',
        ];

        self::assertSame($expected, $sql);
    }
}
