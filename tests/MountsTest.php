<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Mounts;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
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
        MountBuffSerializationFixture::$wasUnserialized = false;
    }

    protected function tearDown(): void
    {
        \Lotgd\MySQL\Database::$lastSql = '';
        \Lotgd\MySQL\Database::$lastCacheName = '';
        unset(\Lotgd\MySQL\Database::$queryCacheResults['mountdata-7']);
        Mounts::getInstance()->setPlayerMount([]);
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
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

    public function testGrantRejectsMissingMountWithoutChangingCurrentMountOrBuff(): void
    {
        $connection = new DoctrineConnection();
        $connection->fetchAllResults = [[]];
        Database::setDoctrineConnection($connection);
        $GLOBALS['session'] = $this->existingMountSession();

        $result = Mounts::grantToCurrentUser(999);

        $this->assertSame(Mounts::GRANT_NOT_FOUND, $result);
        $this->assertSame(7, $GLOBALS['session']['user']['hashorse']);
        $this->assertSame(['rounds' => 3], $GLOBALS['session']['bufflist']['mount']);
    }

    public function testGrantRejectsMalformedBuffWithoutChangingCurrentMountOrBuff(): void
    {
        $this->prepareGrantRow(['mountid' => 8, 'mountbuff' => 'not serialized data']);
        $GLOBALS['session'] = $this->existingMountSession();

        $result = Mounts::grantToCurrentUser(8);

        $this->assertSame(Mounts::GRANT_INVALID_BUFF, $result);
        $this->assertSame(7, $GLOBALS['session']['user']['hashorse']);
        $this->assertSame(['rounds' => 3], $GLOBALS['session']['bufflist']['mount']);
    }

    public function testGrantAppliesValidMountAndBuff(): void
    {
        $buff = ['rounds' => 5, 'atkmod' => 1.2];
        $this->prepareGrantRow(['mountid' => 8, 'mountbuff' => serialize($buff)]);
        $GLOBALS['session'] = $this->existingMountSession();

        $result = Mounts::grantToCurrentUser(8);

        $this->assertSame(Mounts::GRANT_SUCCESS, $result);
        $this->assertSame(8, $GLOBALS['session']['user']['hashorse']);
        $this->assertSame(5, $GLOBALS['session']['bufflist']['mount']['rounds']);
        $this->assertSame('mounts', $GLOBALS['session']['bufflist']['mount']['schema']);
    }

    public function testGrantDoesNotInstantiateSerializedBuffObjects(): void
    {
        $serializedBuff = serialize(new MountBuffSerializationFixture());
        $this->prepareGrantRow(['mountid' => 8, 'mountbuff' => $serializedBuff]);
        $GLOBALS['session'] = $this->existingMountSession();

        $result = Mounts::grantToCurrentUser(8);

        $this->assertSame(Mounts::GRANT_INVALID_BUFF, $result);
        $this->assertFalse(MountBuffSerializationFixture::$wasUnserialized);
        $this->assertSame(7, $GLOBALS['session']['user']['hashorse']);
        $this->assertSame(['rounds' => 3], $GLOBALS['session']['bufflist']['mount']);
    }

    /** @param array<string, mixed> $row */
    private function prepareGrantRow(array $row): void
    {
        $connection = new DoctrineConnection();
        $connection->fetchAllResults = [[$row]];
        Database::setDoctrineConnection($connection);
    }

    /** @return array<string, mixed> */
    private function existingMountSession(): array
    {
        return [
            'user' => ['hashorse' => 7, 'superuser' => 0],
            'bufflist' => ['mount' => ['rounds' => 3]],
        ];
    }
}

/**
 * Test fixture that records whether PHP instantiated a serialized buff object.
 */
final class MountBuffSerializationFixture
{
    public static bool $wasUnserialized = false;

    public function __wakeup(): void
    {
        self::$wasUnserialized = true;
    }
}
