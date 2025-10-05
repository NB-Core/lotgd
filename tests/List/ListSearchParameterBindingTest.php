<?php

declare(strict_types=1);

namespace Lotgd\Tests\List;

use Lotgd\MySQL\Database;
use Lotgd\PlayerSearch;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ListSearchParameterBindingTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        DoctrineBootstrap::$conn = null;
        Database::resetDoctrineConnection();
        Database::$mockResults = [];
        Database::setPrefix('lotgd_');

        $this->connection = Database::getDoctrineConnection();
        $this->connection->queries = [];
        $this->connection->executeQueryParams = [];
        $this->connection->executeQueryTypes = [];
        $this->connection->fetchAllResults = [[]];
    }

    public function testSearchBindsTaintedName(): void
    {
        $tainted = "Evil%' OR 1=1 --";

        $playerSearch = new PlayerSearch($this->connection);
        $playerSearch->searchListByName($tainted);

        $this->assertNotEmpty($this->connection->queries, 'Expected a query to be executed.');

        $sql = $this->connection->queries[0];
        $this->assertStringNotContainsString($tainted, $sql, 'Tainted input leaked into the SQL string.');
        $this->assertStringNotContainsString("' OR 1=1 --", $sql, 'Dangerous substring should not appear in SQL.');
        $this->assertStringContainsString("ESCAPE '!'", $sql, 'Expected the SQL to declare the configured escape character.');

        $params = $this->connection->executeQueryParams[0] ?? [];
        $this->assertArrayHasKey('namePattern', $params, 'Missing bound parameter for name pattern.');

        $pattern = $params['namePattern'];
        $this->assertIsString($pattern);
        $this->assertStringContainsString('E', $pattern);
        $this->assertStringContainsString('%', $pattern);

        $normalised = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];
            if ($character === '!') {
                $i++;
                if ($i < $length) {
                    $normalised .= $pattern[$i];
                }
                continue;
            }

            if ($character === '%') {
                continue;
            }

            $normalised .= $character;
        }

        $this->assertSame($tainted, $normalised, 'Bound parameter should contain the tainted input when normalised.');
    }
}
