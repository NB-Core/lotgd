<?php

declare(strict_types=1);

namespace Lotgd\Tests\Legacy;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression checks for legacy request-driven SQL hardening.
 */
final class HighRiskSqlMigrationTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        \Lotgd\Tests\Stubs\Database::setPrefix('');
        Database::resetDoctrineConnection();
        $this->connection = Database::getDoctrineConnection();
        $this->connection->executeStatements = [];
        $this->connection->lastFetchAllParams = [];
        $this->connection->lastFetchAllTypes = [];
    }

    public function testSuperuserNewsDeleteSqlIsParameterizedInSource(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/superuser.php');

        self::assertStringContainsString('WHERE newsid = :newsid', $content);
        self::assertStringContainsString("['newsid' => ParameterType::STRING]", $content);
        self::assertStringNotContainsString("WHERE newsid='" . "' . Http::get('newsid')", $content);
    }

    public function testTranslatortoolListQueriesUseTypedBoundLanguageAndUriInSource(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/translatortool.php');

        self::assertStringContainsString('WHERE language = :language GROUP BY uri ORDER BY uri ASC', $content);
        self::assertStringContainsString('WHERE language = :language AND uri = :uri', $content);
        self::assertStringContainsString("'language' => ParameterType::STRING", $content);
        self::assertStringContainsString("'uri'      => ParameterType::STRING", $content);
        self::assertStringNotContainsString("WHERE language='" . LANGUAGE . "'", $content);
    }

    public function testQuoteContainingPayloadsRemainBoundParameters(): void
    {
        $newsPayload = "7' OR '1'='1";
        $uriPayload = "quest's/intro\"line";

        $this->connection->executeStatement(
            'DELETE FROM news WHERE newsid = :newsid',
            ['newsid' => $newsPayload],
            ['newsid' => ParameterType::STRING]
        );

        $delete = $this->connection->executeStatements[0] ?? null;
        self::assertNotNull($delete);
        self::assertSame(['newsid' => $newsPayload], $delete['params']);
        self::assertStringNotContainsString($newsPayload, $delete['sql']);

        $this->connection->fetchAllAssociative(
            'SELECT * FROM translations WHERE language = :language AND uri = :uri',
            [
                'language' => 'en',
                'uri'      => $uriPayload,
            ],
            [
                'language' => ParameterType::STRING,
                'uri'      => ParameterType::STRING,
            ]
        );

        self::assertSame(['language' => 'en', 'uri' => $uriPayload], $this->connection->lastFetchAllParams);
        self::assertSame(
            ['language' => ParameterType::STRING, 'uri' => ParameterType::STRING],
            $this->connection->lastFetchAllTypes
        );
    }
}
