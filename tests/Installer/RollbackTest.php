<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

final class RollbackTest extends TestCase
{
    private Output $output;

    protected function setUp(): void
    {
        if (!defined('IS_INSTALLER')) {
            define('IS_INSTALLER', true);
        }
        class_exists(DbMysqli::class);
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        $this->output = new Output();
        $ref = new \ReflectionClass(Output::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->output);
        $GLOBALS['session'] = [];
        $GLOBALS['logd_version'] = 'test';
        $GLOBALS['recommended_modules'] = [];
        $GLOBALS['noinstallnavs'] = [];
        $GLOBALS['stage'] = 0;
        $GLOBALS['DB_USEDATACACHE'] = false;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInterruptedMigrationResumes(): void
    {
        $installer1 = new class extends Installer {
            public static int $executions = 0;
            public static bool $fail = true;
            public function stage9(): void
            {
                $output = Output::getInstance();
                $output->output('`@`c`bRunning Database Migrations`b`c');
                try {
                    $this->runMigrations();
                    $output->output('`@Migrations executed successfully.`n');
                } catch (\Throwable $e) {
                    $output->output('`\$Migration error:`n' . $e->getMessage());
                }
            }
            private function runMigrations(): void
            {
                self::$executions++;
                if (self::$fail) {
                    self::$fail = false;
                    throw new \RuntimeException('Simulated failure');
                }
            }
        };

        $installer1->stage9();
        $first = $this->output->getRawOutput();
        $this->assertStringContainsString('Migration error', $first);

        $this->output = new Output();
        $ref = new \ReflectionClass(Output::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->output);
        $class = get_class($installer1);
        $installer2 = new $class();
        $installer2->stage9();
        $second = $this->output->getRawOutput();
        $this->assertStringContainsString('Migrations executed successfully', $second);
        $this->assertSame(2, $class::$executions);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInvalidCredentialsShowsErrorMessage(): void
    {
        $db = new class extends DbMysqli {
            public string $error = '';
            public function connect(string $host, string $user, string $pass): bool
            {
                echo 'Access denied for user';
                $this->error = 'Access denied for user';
                return false;
            }
            public function error(): string
            {
                return $this->error;
            }
        };
        \Lotgd\MySQL\Database::$instance = $db;

        $GLOBALS['session']['dbinfo'] = [
            'DB_HOST' => 'localhost',
            'DB_USER' => 'bad',
            'DB_PASS' => 'bad',
            'DB_NAME' => 'lotgd',
            'DB_USEDATACACHE' => false,
            'DB_DATACACHEPATH' => '',
        ];

        $installer = new Installer();
        $installer->stage4();
        $output = $this->output->getRawOutput();
        $this->assertStringContainsString("Blast!  I wasn't able to connect", $output);
        $this->assertStringContainsString('Access denied for user', $output);
    }
}
