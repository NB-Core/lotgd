<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\DBAL\ArrayParameterType;
use Lotgd\MySQL\Database;
use Lotgd\PlayerFunctions;
use PHPUnit\Framework\TestCase;

final class PlayerFunctionsMassIsPlayerOnlineParameterizedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::resetDoctrineConnection();
        $connection = Database::getDoctrineConnection();
        $connection->executeQueryParams = [];
        $connection->executeQueryTypes = [];
        $connection->fetchAllResults = [];
    }

    public function testMassIsPlayerOnlineBindsIntegerArrayParameters(): void
    {
        $connection = Database::getDoctrineConnection();
        $connection->fetchAllResults[] = [
            ['acctid' => 2, 'laston' => '2099-01-01 00:00:00', 'loggedin' => 1],
            ['acctid' => 5, 'laston' => '2099-01-01 00:00:00', 'loggedin' => 1],
        ];

        $result = PlayerFunctions::massIsPlayerOnline([2, '5', '5', '0 OR 1=1']);

        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertSame(
            ['players' => [2, 5, 0]],
            $connection->executeQueryParams[0] ?? []
        );
        $this->assertSame(
            ['players' => ArrayParameterType::INTEGER],
            $connection->executeQueryTypes[0] ?? []
        );
    }
}

