<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Mounts;

    use Lotgd\Tests\Stubs\Database;
    require_once __DIR__ . '/../config/constants.php';

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
    }
}
