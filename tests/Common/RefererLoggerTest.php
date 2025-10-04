<?php

declare(strict_types=1);

namespace Lotgd\Tests\Common;

use Lotgd\Doctrine\Bootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

final class RefererLoggerTest extends TestCase
{
    /**
     * @return array{statements:array<int,array{sql:string,params:array,types:array}>}
     */
    private function runLogger(string $referer, string $host = 'example.com'): array
    {
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        Bootstrap::$conn = new DoctrineConnection();
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $conn->executeStatements = [];

        $server = [
            'HTTP_REFERER' => $referer,
            'HTTP_HOST'    => $host,
        ];

        \Lotgd\RefererLogger::log($server, '/village.php', '203.0.113.7');

        return [
            'statements' => $conn->executeStatements,
        ];
    }

    public function testBinaryRefererIsIgnored(): void
    {
        $result = $this->runLogger("\0http://evil.test/path");

        $this->assertSame([], $result['statements']);
    }

    public function testMaliciousRefererUsesBoundParameters(): void
    {
        $payload = "http://evil.test/path?x='\"><script>alert(1)</script>";
        $result = $this->runLogger($payload);

        $this->assertCount(1, $result['statements']);
        $statement = $result['statements'][0];

        $this->assertStringNotContainsString($payload, $statement['sql']);
        $this->assertSame($payload, $statement['params']['uri']);
        $this->assertSame('evil.test', $statement['params']['site']);
        $this->assertSame('example.com/village.php', $statement['params']['dest']);
        $this->assertSame('203.0.113.7', $statement['params']['ip']);
    }
}
