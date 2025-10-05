<?php

declare(strict_types=1);

namespace Lotgd\Tests\PlayerSearch;

use InvalidArgumentException;
use Lotgd\MySQL\Database;
use Lotgd\PlayerSearch;
use Lotgd\Tests\Stubs\Database as DatabaseStub;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use PHPUnit\Framework\TestCase;

final class PlayerSearchTest extends TestCase
{
    private PlayerSearch $search;

    /**
     * @var \Lotgd\Tests\Stubs\DoctrineConnection
     */
    private $connection;

    protected function setUp(): void
    {
        // Ensure the stub classes are loaded so Doctrine resolves to the in-memory fake connection.
        class_exists(DatabaseStub::class);

        Database::resetDoctrineConnection();
        if (class_exists(DoctrineBootstrap::class, false)) {
            DoctrineBootstrap::$conn = null;
        }

        \Lotgd\MySQL\Database::$mockResults = [];

        $this->search = new PlayerSearch();
        $this->connection = Database::getDoctrineConnection();
        $this->connection->queries = [];
        $this->connection->executeQueryParams = [];
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        if (class_exists(DoctrineBootstrap::class, false)) {
            DoctrineBootstrap::$conn = null;
        }

        \Lotgd\MySQL\Database::$mockResults = [];
    }

    public function testFindExactLoginReturnsSingleRow(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['acctid' => 1, 'login' => 'alpha', 'name' => 'Alpha'],
        ]];

        $result = $this->search->findExactLogin('alpha');

        $this->assertSame('alpha', $result[0]['login']);
        $this->assertSame('Alpha', $result[0]['name']);

        $sql = end($this->connection->queries);
        $this->assertStringContainsString('a.login = :loginExact', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);

        $params = end($this->connection->executeQueryParams);
        $this->assertSame(['loginExact' => 'alpha'], $params);
    }

    public function testFindByDisplayNamePatternHonoursOrdering(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'bravo', 'name' => 'Bravo'],
            ['login' => 'bravo_team', 'name' => 'Bravocado'],
        ]];

        $result = $this->search->findByDisplayNamePattern('Brav%', ['login', 'name'], null, 'Bravo');

        $this->assertCount(2, $result);
        $this->assertSame('bravo', $result[0]['login']);
        $this->assertSame('Bravo', $result[0]['name']);

        $sql = end($this->connection->queries);
        $this->assertStringContainsString('a.name LIKE :namePattern', $sql);
        $this->assertStringContainsString('CASE WHEN a.name = :nameExact THEN 0 ELSE 1 END', $sql);

        $params = end($this->connection->executeQueryParams);
        $this->assertSame([
            'namePattern' => 'Brav%',
            'nameExact'   => 'Bravo',
        ], $params);
    }

    public function testFindByDisplayNameFuzzyBuildsCharacterPattern(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'charlie', 'name' => 'Char L.'],
        ]];

        $result = $this->search->findByDisplayNameFuzzy('Ch', ['login', 'name'], 10, 'Char L.');

        $this->assertSame('charlie', $result[0]['login']);

        $sql = end($this->connection->queries);
        $this->assertStringContainsString('a.name LIKE :nameCharacterPattern', $sql);

        $params = end($this->connection->executeQueryParams);
        $this->assertSame([
            'namePattern' => '%Ch%',
            'nameCharacterPattern' => '%C%h%',
            'nameExact'   => 'Char L.',
        ], $params);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testFindForTransferCombinesExactAndFuzzyPatterns(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'bravo', 'name' => 'Bravo'],
            ['login' => 'bravo_team', 'name' => 'Bravocado'],
        ]];

        $result = $this->search->findForTransfer('bravo', ['login', 'name'], 15);

        $this->assertSame('bravo', $result[0]['login']);

        $sql = end($this->connection->queries);
        $this->assertStringContainsString('a.login = :loginExact', $sql);
        $this->assertStringContainsString('a.name LIKE :namePattern', $sql);
        $this->assertStringContainsString('a.name LIKE :nameCharacterPattern', $sql);

        $params = end($this->connection->executeQueryParams);
        $this->assertSame([
            'loginExact'           => 'bravo',
            'namePattern'          => '%bravo%',
            'nameCharacterPattern' => '%b%r%a%v%o%',
            'nameExact'            => 'bravo',
        ], $params);
        $this->assertStringContainsString('LIMIT 15', $sql);
    }

    public function testWildcardCharactersAreEscaped(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'symbols', 'name' => '%Weird_'],
        ]];

        $this->search->findForTransfer('%_');

        $params = end($this->connection->executeQueryParams);
        $this->assertSame('%_', $params['loginExact']);
        $this->assertSame('%!%!_%', $params['namePattern']);
        $this->assertSame('%!%%!_%', $params['nameCharacterPattern']);
        $this->assertSame('%_', $params['nameExact']);
    }

    public function testPatternsPreserveQuotesAndMultibyteCharacters(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'quote', 'name' => "O'Reilly"],
        ]];

        $this->search->findByDisplayNamePattern("%O'Reilly%", null, null, "O'Reilly");

        $params = end($this->connection->executeQueryParams);
        $this->assertSame("%O'Reilly%", $params['namePattern']);
        $this->assertSame("O'Reilly", $params['nameExact']);

        \Lotgd\MySQL\Database::$mockResults = [[
            ['login' => 'unicode', 'name' => '漢字'],
        ]];

        $this->search->findByDisplayNameFuzzy('漢字');

        $params = end($this->connection->executeQueryParams);
        $this->assertSame('%漢字%', $params['namePattern']);
        $this->assertSame('%漢%字%', $params['nameCharacterPattern']);
    }

    public function testLimitDefaultsAndCaps(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[['login' => 'alpha', 'name' => 'Alpha']]];
        $this->search->findByDisplayNamePattern('%');
        $sql = end($this->connection->queries);
        $this->assertStringContainsString('LIMIT 100', $sql);

        \Lotgd\MySQL\Database::$mockResults = [[['login' => 'alpha', 'name' => 'Alpha']]];
        $this->search->findByDisplayNamePattern('%', null, 999);
        $sql = end($this->connection->queries);
        $this->assertStringContainsString('LIMIT 250', $sql);

        \Lotgd\MySQL\Database::$mockResults = [[['login' => 'alpha', 'name' => 'Alpha']]];
        $this->search->findByDisplayNamePattern('%', null, 0);
        $sql = end($this->connection->queries);
        $this->assertStringContainsString('LIMIT 100', $sql);
    }

    public function testCustomColumnsAreRespected(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            ['acctid' => 1, 'player_login' => 'alpha'],
        ]];

        $result = $this->search->findForTransfer('alpha', ['acctid', 'login' => 'player_login'], 1);

        $this->assertSame(['acctid' => 1, 'player_login' => 'alpha'], $result[0]);

        $sql = end($this->connection->queries);
        $this->assertStringContainsString('SELECT a.acctid, a.login AS player_login', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    public function testInvalidColumnNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->search->findExactLogin('alpha', ['invalid column']);
    }
}
