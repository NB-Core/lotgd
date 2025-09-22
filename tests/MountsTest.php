<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Mounts;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class MountsTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $cachedMountRow = [
        'mountid'   => 7,
        'mountname' => 'Thunder',
    ];

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$lastSql = '';
        \Lotgd\MySQL\Database::$lastCacheName = '';
        \Lotgd\MySQL\Database::$queryCacheResults['mountdata-7'] = [
            $this->cachedMountRow,
        ];
    }

    protected function tearDown(): void
    {
        \Lotgd\MySQL\Database::$lastSql = '';
        \Lotgd\MySQL\Database::$lastCacheName = '';
        unset(\Lotgd\MySQL\Database::$queryCacheResults['mountdata-7']);
        Mounts::getInstance()->setPlayerMount([]);
    }

    public function testGetmountExecutesQuery(): void
    {
        $this->assertSame([
            $this->cachedMountRow,
        ], \Lotgd\MySQL\Database::$queryCacheResults['mountdata-7']);

        $row = Mounts::getmount(7);

        $this->assertSame($this->cachedMountRow, $row);
        $this->assertSame('mountdata-7', \Lotgd\MySQL\Database::$lastCacheName);
        $this->assertSame([
            $this->cachedMountRow,
        ], \Lotgd\MySQL\Database::$queryCacheResults['mountdata-7']);

        $this->assertSame($this->cachedMountRow, Mounts::getmount(7));
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

        $mounts->loadPlayerMount(7);
        $this->assertSame($this->cachedMountRow, $mounts->getPlayerMount());
    }
}
