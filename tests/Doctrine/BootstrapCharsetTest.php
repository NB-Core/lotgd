<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\Bootstrap;
use Lotgd\Tests\Support\RootDbConnect;
use PHPUnit\Framework\TestCase;

final class BootstrapCharsetTest extends TestCase
{
    private ?RootDbConnect $dbConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbConfig = RootDbConnect::takeOver();
        $this->dbConfig->write(
            "<?php return ['DB_HOST'=>'localhost','DB_USER'=>'user','DB_PASS'=>'pass','DB_NAME'=>'lotgd','DB_PREFIX'=>''];"
        );
    }

    protected function tearDown(): void
    {
        $this->dbConfig?->restore();

        unset($GLOBALS['DB_PREFIX']);

        parent::tearDown();
    }

    public function testEntityManagerUsesUtf8mb4Charset(): void
    {
        $entityManager = Bootstrap::getEntityManager();
        $params = $entityManager->getConnection()->getParams();
        self::assertSame('utf8mb4', $params['charset'] ?? null);
    }
}
