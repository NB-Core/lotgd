<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapCharsetTest extends TestCase
{
    public function testEntityManagerUsesUtf8mb4Charset(): void
    {
        $entityManager = Bootstrap::getEntityManager();
        $params = $entityManager->getConnection()->getParams();
        self::assertSame('utf8mb4', $params['charset'] ?? null);
    }
}
