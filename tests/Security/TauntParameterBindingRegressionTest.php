<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for taunt parameter binding.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TauntParameterBindingRegressionTest extends TestCase
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

    public function testSourceUsesPreparedStatementsWithTypedParams(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/taunt.php');

        self::assertStringContainsString('executeStatement(', $source);
        self::assertStringContainsString('executeQuery(', $source);
        self::assertStringContainsString('WHERE tauntid = :tauntid', $source);
        self::assertStringContainsString('taunt_normalize_text(Http::post(\'taunt\'))', $source);
        self::assertStringContainsString('rawurlencode($tauntidParam)', $source);
        self::assertStringContainsString('taunt_normalize_optional_int(Http::get(\'c\'))', $source);
        self::assertStringContainsString('Nav::add("", "taunt.php?c=$commentaryPageParam");', $source);
        self::assertStringNotContainsString('Nav::add("", "taunt.php?c=" . Http::get(\'c\'));', $source);
        self::assertStringContainsString('tauntid = :tauntid', $source);
        self::assertStringContainsString('taunt = :taunt', $source);
        self::assertStringContainsString('editor = :editor', $source);
        self::assertStringContainsString('ParameterType::INTEGER', $source);
        self::assertStringContainsString('ParameterType::STRING', $source);
        self::assertStringNotContainsString('addslashes($session[\'user\'][\'login\'])', $source);
        self::assertStringContainsString('! ctype_digit($value)', $source);
        self::assertStringContainsString('$intValue > 0 ? $intValue : null;', $source);
    }

    public function testPayloadRoundtripIsPreservedInInsertBoundParameters(): void
    {
        $payload = "Taunt '\" \\\\ Ω漢字";
        $editor = "Admin '\" \\\\ Ω";

        $this->connection->executeStatement(
            'INSERT INTO ' . Database::prefix('taunts') . ' (taunt, editor) VALUES (:taunt, :editor)',
            [
                'taunt' => $payload,
                'editor' => $editor,
            ],
            [
                'taunt' => ParameterType::STRING,
                'editor' => ParameterType::STRING,
            ]
        );

        $statement = $this->connection->executeStatements[0] ?? null;
        self::assertNotNull($statement);
        self::assertStringNotContainsString($payload, $statement['sql']);
        self::assertSame($payload, $statement['params']['taunt']);
        self::assertSame($editor, $statement['params']['editor']);
        self::assertSame(ParameterType::STRING, $statement['types']['taunt']);
    }
}
