<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapLegacyConfigTest extends TestCase
{
    private string $dbConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbConfig = dirname(__DIR__, 2) . '/dbconnect.php';
        $cachePath = sys_get_temp_dir();
        $legacyConfig = <<<PHP
<?php
\$DB_HOST = 'legacy-host';
\$DB_USER = 'legacy-user';
\$DB_PASS = 'legacy-pass';
\$DB_NAME = 'legacy-name';
\$DB_PREFIX = 'legacy_';
\$DB_USEDATACACHE = 1;
\$DB_DATACACHEPATH = %s;
PHP;
        file_put_contents($this->dbConfig, sprintf($legacyConfig, var_export($cachePath, true)));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbConfig)) {
            unlink($this->dbConfig);
        }

        unset(
            $GLOBALS['DB_HOST'],
            $GLOBALS['DB_USER'],
            $GLOBALS['DB_PASS'],
            $GLOBALS['DB_NAME'],
            $GLOBALS['DB_PREFIX'],
            $GLOBALS['DB_USEDATACACHE'],
            $GLOBALS['DB_DATACACHEPATH']
        );

        parent::tearDown();
    }

    public function testEntityManagerLoadsLegacyGlobals(): void
    {
        $bootstrapClass = $this->resolveBootstrapClass();
        $entityManager = $bootstrapClass::getEntityManager();
        $params = $entityManager->getConnection()->getParams();

        self::assertSame('legacy-host', $params['host'] ?? null);
        self::assertSame('legacy-name', $params['dbname'] ?? null);
        self::assertSame('legacy-user', $params['user'] ?? null);
        self::assertSame('legacy-pass', $params['password'] ?? null);
        self::assertSame('utf8mb4', $params['charset'] ?? null);
        self::assertSame('legacy_', $GLOBALS['DB_PREFIX'] ?? null);
    }

    /**
     * @return class-string
     */
    private function resolveBootstrapClass(): string
    {
        $originalClass = Bootstrap::class;
        $bootstrapFile = (new \ReflectionClass($originalClass))->getFileName();
        $realFile = realpath(__DIR__ . '/../../src/Lotgd/Doctrine/Bootstrap.php');

        if ($realFile === false) {
            throw new \RuntimeException('Unable to locate Doctrine bootstrap source file');
        }

        if ($bootstrapFile === $realFile) {
            return $originalClass;
        }

        $realClass = '\\Lotgd\\Tests\\Doctrine\\RealBootstrap\\Bootstrap';

        if (!class_exists($realClass)) {
            $contents = file_get_contents($realFile);
            $contents = str_replace('namespace Lotgd\\Doctrine;', 'namespace Lotgd\\Tests\\Doctrine\\RealBootstrap;', (string) $contents);
            $contents = str_replace('dirname(__DIR__, 3)', var_export(dirname(__DIR__, 2), true), (string) $contents);
            $contents = preg_replace('/^<\\?php\s*/', '', (string) $contents);

            if ($contents === null) {
                throw new \RuntimeException('Unable to load real Doctrine bootstrap for testing');
            }

            eval($contents);
        }

        return $realClass;
    }
}
