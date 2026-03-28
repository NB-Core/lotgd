<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\DBAL\ParameterType;
use Lotgd\CheckBan;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class CheckBanParameterBindingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';

        Database::$doctrineConnection = null;
        Database::$instance = null;
        DoctrineBootstrap::$conn = null;
        Database::$mockResults = [];

        if (!defined('SU_DOESNT_GIVE_GROTTO')) {
            define('SU_DOESNT_GIVE_GROTTO', 0);
        }
        if (!defined('DATETIME_DATEMAX')) {
            define('DATETIME_DATEMAX', '2159-01-01 00:00:00');
        }

        global $session;
        $session = [];
    }

    public function testCheckUsesBoundParametersForLoginAndBans(): void
    {
        $conn = Database::getDoctrineConnection();
        $conn->fetchAssociativeResults = [[
            'lastip' => '1.2.3.4',
            'uniqueid' => 'device-1',
            'banoverride' => 0,
            'superuser' => 0,
        ]];
        $conn->fetchAllResults = [[]];

        CheckBan::check("bad'login");

        $this->assertSame("bad'login", $conn->lastFetchAssociativeParams['login'] ?? null);
        $this->assertSame(ParameterType::STRING, $conn->lastFetchAssociativeTypes['login'] ?? null);

        $deleteStatement = $conn->executeStatements[0] ?? null;
        $this->assertNotNull($deleteStatement);
        $this->assertStringContainsString('DELETE FROM', $deleteStatement['sql']);
        $this->assertArrayHasKey('now', $deleteStatement['params']);

        $queryParams = end($conn->executeQueryParams);
        $queryTypes = end($conn->executeQueryTypes);
        $this->assertSame('1.2.3.4', $queryParams['ip'] ?? null);
        $this->assertSame(ParameterType::STRING, $queryTypes['ip'] ?? null);
    }
}
