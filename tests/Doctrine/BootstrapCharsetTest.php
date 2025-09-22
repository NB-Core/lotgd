<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapCharsetTest extends TestCase
{
    private string $dbConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbConfig = dirname(__DIR__, 2) . '/dbconnect.php';
        file_put_contents(
            $this->dbConfig,
            "<?php return ['DB_HOST'=>'localhost','DB_USER'=>'user','DB_PASS'=>'pass','DB_NAME'=>'lotgd','DB_PREFIX'=>''];"
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbConfig)) {
            unlink($this->dbConfig);
        }

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
