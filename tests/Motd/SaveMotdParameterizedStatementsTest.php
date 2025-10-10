<?php

declare(strict_types=1);

namespace Lotgd\Tests\Motd;

use Lotgd\Motd;
use Lotgd\MySQL\Database;
use PHPUnit\Framework\TestCase;

final class SaveMotdParameterizedStatementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $session;

        $_POST = [];
        $session = ['user' => ['acctid' => 42]];

        Database::resetDoctrineConnection();
        $connection = Database::getDoctrineConnection();
        $connection->executeStatements = [];
        $connection->fetchAssociativeResults = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session']);
        $_POST = [];
        parent::tearDown();
    }

    public function testUpdateBindsParametersForQuotedMotd(): void
    {
        global $session;
        $session = ['user' => ['acctid' => 99]];

        $_POST = [
            'motdtitle'    => 'Quote "Title"',
            'motdbody'     => 'Body with "double" and \'single\' quotes',
            'motdtype'     => '0',
            'changeauthor' => '',
            'changedate'   => '',
        ];

        $connection = Database::getDoctrineConnection();
        $connection->fetchAssociativeResults[] = [
            'motdauthor' => 7,
            'motddate'   => '2023-12-24 10:11:12',
        ];

        Motd::saveMotd(5);

        $statement = $this->findStatement($connection->executeStatements, 'UPDATE ' . Database::prefix('motd'));
        $this->assertNotNull($statement, 'Expected an UPDATE statement to be executed.');

        $this->assertSame([
            'title'  => 'Quote "Title"',
            'body'   => 'Body with "double" and \'single\' quotes',
            'type'   => 0,
            'date'   => '2023-12-24 10:11:12',
            'author' => 7,
            'id'     => 5,
        ], $statement['params']);
    }

    public function testInsertBindsParametersForPollWithQuotes(): void
    {
        global $session;
        $session = ['user' => ['acctid' => 55]];

        $_POST = [
            'motdtitle' => 'Poll "Title"',
            'motdbody'  => 'Option "A" vs \'B\'',
            'motdtype'  => '1',
        ];

        $connection = Database::getDoctrineConnection();
        $connection->executeStatements = [];

        Motd::saveMotd(0);

        $statement = $this->findStatement($connection->executeStatements, 'INSERT INTO ' . Database::prefix('motd'));
        $this->assertNotNull($statement, 'Expected an INSERT statement to be executed.');

        $this->assertSame('Poll "Title"', $statement['params']['title']);
        $this->assertSame('Option "A" vs \'B\'', $statement['params']['body']);
        $this->assertSame(1, $statement['params']['type']);
        $this->assertSame(55, $statement['params']['author']);
        $this->assertArrayHasKey('date', $statement['params']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $statement['params']['date']);
    }

    /**
     * @param array<int, array{sql:string, params:array, types:array}> $statements
     */
    private function findStatement(array $statements, string $prefix): ?array
    {
        foreach ($statements as $statement) {
            if (str_starts_with($statement['sql'], $prefix)) {
                return $statement;
            }
        }

        return null;
    }
}
