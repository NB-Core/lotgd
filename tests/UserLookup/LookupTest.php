<?php

declare(strict_types=1);

namespace Lotgd\Tests\UserLookup;

use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\Database as DatabaseStub;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\UserLookup;
use PHPUnit\Framework\TestCase;

final class LookupTest extends TestCase
{
    /**
     * @var \Lotgd\Tests\Stubs\DoctrineConnection
     */
    private $connection;

    protected function setUp(): void
    {
        class_exists(DatabaseStub::class);

        Database::resetDoctrineConnection();
        if (class_exists(DoctrineBootstrap::class, false)) {
            DoctrineBootstrap::$conn = null;
        }

        \Lotgd\MySQL\Database::$mockResults = [];

        // Prime the fake Doctrine connection that PlayerSearch will use.
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

    public function testLookupDelegatesToPlayerSearch(): void
    {
        \Lotgd\MySQL\Database::$mockResults = [[
            [
                'acctid' => 1,
                'login' => 'alpha',
                'name' => 'Alpha',
                'level' => 1,
                'laston' => '2024-01-01 00:00:00',
                'loggedin' => 0,
                'gentimecount' => 10,
                'gentime' => 1.23,
                'lastip' => '127.0.0.1',
                'uniqueid' => 'abc',
                'emailaddress' => 'alpha@example.com',
            ],
        ]];

        $deprecations = [];
        set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecations[] = $message;
                return true;
            }

            return false;
        });

        [$rows, $error] = UserLookup::lookup('alpha');

        restore_error_handler();

        $this->assertSame('', $error);
        $this->assertCount(1, $rows);
        $this->assertSame('alpha', $rows[0]['login']);
        $this->assertSame('alpha@example.com', $rows[0]['emailaddress']);

        $this->assertNotEmpty($deprecations);
        $this->assertStringContainsString('Lotgd\\UserLookup::lookup()', $deprecations[0]);

        $this->assertNotEmpty($this->connection->queries);
        $this->assertStringContainsString('FROM accounts a', $this->connection->queries[0]);
        $this->assertArrayHasKey('legacyExactPattern', $this->connection->executeQueryParams[0]);
    }

    public function testLookupFallsBackToFuzzySearch(): void
    {
        $firstPass = [
            [
                'acctid' => 1,
                'login' => 'alpha',
                'name' => 'Alpha',
                'level' => 10,
                'laston' => '2024-01-01 00:00:00',
                'loggedin' => 0,
                'gentimecount' => 5,
                'gentime' => 0.5,
                'lastip' => '192.168.0.1',
                'uniqueid' => 'first',
                'emailaddress' => 'alpha@example.com',
            ],
            [
                'acctid' => 2,
                'login' => 'alphonse',
                'name' => 'Alphonse',
                'level' => 4,
                'laston' => '2024-01-02 00:00:00',
                'loggedin' => 0,
                'gentimecount' => 6,
                'gentime' => 0.4,
                'lastip' => '192.168.0.2',
                'uniqueid' => 'second',
                'emailaddress' => 'alphonse@example.com',
            ],
        ];

        $fuzzyRows = [];
        for ($i = 0; $i < 301; $i++) {
            $fuzzyRows[] = [
                'acctid' => 100 + $i,
                'login' => 'alpha' . $i,
                'name' => 'Alpha ' . $i,
                'level' => 1,
                'laston' => '2024-01-03 00:00:00',
                'loggedin' => 0,
                'gentimecount' => 1,
                'gentime' => 0.1,
                'lastip' => '10.0.0.' . $i,
                'uniqueid' => 'u' . $i,
                'emailaddress' => 'alpha' . $i . '@example.com',
            ];
        }

        \Lotgd\MySQL\Database::$mockResults = [$firstPass, $fuzzyRows];

        $deprecations = [];
        set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecations[] = $message;
                return true;
            }

            return false;
        });

        [$rows, $error] = UserLookup::lookup('alpha');

        restore_error_handler();

        $this->assertSame("`\$Too many results found, narrow your search please.`0", $error);
        $this->assertCount(301, $rows);
        $this->assertNotEmpty($deprecations);
        $this->assertGreaterThanOrEqual(2, count($this->connection->queries));
        $this->assertStringContainsString('LIKE :legacyFuzzyPattern', end($this->connection->queries));
    }
}
