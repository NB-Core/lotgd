<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Mounts;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class MountsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$lastSql = '';
    }

    protected function tearDown(): void
    {
        \Lotgd\MySQL\Database::$lastSql = '';
    }

    public function testGetmountExecutesQuery(): void
    {
        $this->assertSame([], Mounts::getmount(1));
    }

    public function testGetmountReturnsEmptyArrayWhenNoRows(): void
    {
        $row = Mounts::getmount(2);
        $this->assertSame([], $row);
    }

    public function testPlayerMountAccessors(): void
    {
        $mounts = Mounts::getInstance();
        $mounts->setPlayerMount(['mountid' => 5]);
        $this->assertSame(['mountid' => 5], $mounts->getPlayerMount());
    }
}
