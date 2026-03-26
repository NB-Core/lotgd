<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for untranslated page parameter binding.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class UntranslatedParameterBindingRegressionTest extends TestCase
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
        $this->connection->queries = [];
        $this->connection->executeQueryParams = [];
        $this->connection->executeQueryTypes = [];
    }

    protected function tearDown(): void
    {
        DoctrineBootstrap::$conn = null;
        Database::resetDoctrineConnection();
        Database::setPrefix('');
        parent::tearDown();
    }

    public function testSourceUsesBoundParamsForLanguageAndNamespaceFilters(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/untranslated.php');

        self::assertStringContainsString('WHERE language = :language', $source);
        self::assertStringContainsString('AND namespace = :namespace', $source);
        self::assertStringContainsString('INSERT INTO " . Database::prefix("translations") . "', $source);
        self::assertStringContainsString('DELETE FROM " . Database::prefix("untranslated") . "', $source);
        self::assertStringContainsString('ParameterType::STRING', $source);
    }

    public function testQuotedMultibytePayloadRemainsBoundAndSqlShapeUnchanged(): void
    {
        $language = "en'\"\\Ω";
        $namespace = "core/forest'\"\\漢字";
        $intext = "Source '\" \\\\ Ω漢字";
        $outtext = "Target '\" \\\\ Ω漢字";

        $this->connection->executeQuery(
            'SELECT * FROM ' . Database::prefix('untranslated') . ' WHERE language = :language AND namespace = :namespace',
            [
                'language' => $language,
                'namespace' => $namespace,
            ],
            [
                'language' => ParameterType::STRING,
                'namespace' => ParameterType::STRING,
            ]
        );

        $this->connection->executeStatement(
            'INSERT INTO ' . Database::prefix('translations') . ' (language, uri, intext, outtext, author, version) VALUES (:language, :namespace, :intext, :outtext, :author, :version)',
            [
                'language' => $language,
                'namespace' => $namespace,
                'intext' => $intext,
                'outtext' => $outtext,
                'author' => "Editor '\" \\\\ Ω",
                'version' => '2.0.0',
            ],
            [
                'language' => ParameterType::STRING,
                'namespace' => ParameterType::STRING,
                'intext' => ParameterType::STRING,
                'outtext' => ParameterType::STRING,
                'author' => ParameterType::STRING,
                'version' => ParameterType::STRING,
            ]
        );

        $selectSql = $this->connection->queries[0] ?? '';
        self::assertStringNotContainsString($language, $selectSql);
        self::assertStringNotContainsString($namespace, $selectSql);

        $insert = $this->connection->executeStatements[0] ?? null;
        self::assertNotNull($insert);
        self::assertStringNotContainsString($intext, $insert['sql']);
        self::assertSame($intext, $insert['params']['intext']);
        self::assertSame($outtext, $insert['params']['outtext']);
    }
}
