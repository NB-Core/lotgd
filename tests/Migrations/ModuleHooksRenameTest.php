<?php

declare(strict_types=1);

namespace Lotgd\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Lotgd\Migrations\Version20250724000019;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20250724000019.php';

final class ModuleHooksRenameTest extends TestCase
{
    public function testUpRenamesColumnAndAdjustsPrimaryKey(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE module_hooks (modulename TEXT, location TEXT, `function` TEXT)');

        $migration = new Version20250724000019($connection, new NullLogger());
        $migration->up(new Schema());

        $sql = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        $expected = [
            'ALTER TABLE module_hooks DROP PRIMARY KEY',
            'ALTER TABLE module_hooks CHANGE COLUMN `function` hook_callback VARCHAR(50) NOT NULL',
            'ALTER TABLE module_hooks ADD PRIMARY KEY (modulename, location, hook_callback)',
        ];

        self::assertSame($expected, $sql);
    }

    public function testUpDoesNothingWhenAlreadyRenamed(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE module_hooks (modulename TEXT, location TEXT, hook_callback TEXT)');

        $migration = new Version20250724000019($connection, new NullLogger());
        $migration->up(new Schema());

        $sql = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        self::assertSame([], $sql);
    }

    public function testDownRenamesColumnBackWhenPresent(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE module_hooks (modulename TEXT, location TEXT, hook_callback TEXT)');

        $migration = new Version20250724000019($connection, new NullLogger());
        $migration->down(new Schema());

        $sql = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        $expected = [
            'ALTER TABLE module_hooks DROP PRIMARY KEY',
            'ALTER TABLE module_hooks CHANGE COLUMN hook_callback `function` VARCHAR(50) NOT NULL',
            'ALTER TABLE module_hooks ADD PRIMARY KEY (modulename, location, `function`)',
        ];

        self::assertSame($expected, $sql);
    }

    public function testDownDoesNothingWhenAlreadyOriginal(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement('CREATE TABLE module_hooks (modulename TEXT, location TEXT, `function` TEXT)');

        $migration = new Version20250724000019($connection, new NullLogger());
        $migration->down(new Schema());

        $sql = array_map(static fn ($query) => $query->getStatement(), $migration->getSql());

        self::assertSame([], $sql);
    }
}
