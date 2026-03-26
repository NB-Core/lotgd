<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for deathmessage parameter binding.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class DeathmessagesParameterBindingRegressionTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

        DoctrineBootstrap::$conn = null;
        Database::resetDoctrineConnection();
        // Avoid leaking prefix changes into unrelated suites.
        Database::setPrefix('');

        $this->connection = Database::getDoctrineConnection();
        $this->connection->executeStatements = [];
    }

    protected function tearDown(): void
    {
        DoctrineBootstrap::$conn = null;
        Database::resetDoctrineConnection();
        Database::setPrefix('');
        parent::tearDown();
    }

    public function testSourceUsesPreparedStatementsWithExplicitTypes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/deathmessages.php');

        self::assertStringContainsString('executeStatement(', $source);
        self::assertStringContainsString('deathmessageid = :deathmessageid', $source);
        self::assertStringContainsString('deathmessage = :deathmessage', $source);
        self::assertStringContainsString('ParameterType::INTEGER', $source);
        self::assertStringContainsString('ParameterType::STRING', $source);
        self::assertStringNotContainsString('addslashes($session[\'user\'][\'login\'])', $source);
    }

    public function testPayloadRoundtripIsPreservedInBoundParameters(): void
    {
        $payload = "Death '\" \\\\ Ω漢字";

        $this->connection->executeStatement(
            'UPDATE ' . Database::prefix('deathmessages') . ' SET deathmessage = :deathmessage, taunt = :taunt, forest = :forest, graveyard = :graveyard, editor = :editor WHERE deathmessageid = :deathmessageid',
            [
                'deathmessage' => $payload,
                'taunt' => 1,
                'forest' => 0,
                'graveyard' => 1,
                'editor' => "Admin '\" \\\\ Ω",
                'deathmessageid' => 17,
            ],
            [
                'deathmessage' => ParameterType::STRING,
                'taunt' => ParameterType::INTEGER,
                'forest' => ParameterType::INTEGER,
                'graveyard' => ParameterType::INTEGER,
                'editor' => ParameterType::STRING,
                'deathmessageid' => ParameterType::INTEGER,
            ]
        );

        $statement = $this->connection->executeStatements[0] ?? null;
        self::assertNotNull($statement);
        self::assertStringNotContainsString($payload, $statement['sql']);
        self::assertSame($payload, $statement['params']['deathmessage']);
        self::assertSame(ParameterType::STRING, $statement['types']['deathmessage']);
        self::assertSame(ParameterType::INTEGER, $statement['types']['deathmessageid']);
    }
}
