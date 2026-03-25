<?php

declare(strict_types=1);

namespace Lotgd\Tests\User;

use PHPUnit\Framework\TestCase;

final class UserLegacyHttpMigrationTest extends TestCase
{
    public function testUserDelbanUsesRawHttpAndTypedBoundParameters(): void
    {
        $payload = $this->runIsolatedPageScript(<<<'PHP'
$_GET['ipfilter'] = "10.0.0.1' OR 1=1 --";
$_GET['uniqueid'] = 'abc"xyz';

require __DIR__ . '/tests/User/isolated_user_delban.php';
PHP);

        $statement = $payload['statement'] ?? null;
        $this->assertIsArray($statement);
        $this->assertSame("10.0.0.1' OR 1=1 --", $statement['params']['ip'] ?? null);
        $this->assertSame('abc"xyz', $statement['params']['id'] ?? null);
        $this->assertSame('STRING', $statement['types']['ip'] ?? null);
        $this->assertSame('STRING', $statement['types']['id'] ?? null);
    }

    public function testUserDelbanPreservesZeroStringParameters(): void
    {
        $payload = $this->runIsolatedPageScript(<<<'PHP'
$_GET['ipfilter'] = '0';
$_GET['uniqueid'] = '0';

require __DIR__ . '/tests/User/isolated_user_delban.php';
PHP);

        $statement = $payload['statement'] ?? null;
        $this->assertIsArray($statement);
        $this->assertSame('0', $statement['params']['ip'] ?? null);
        $this->assertSame('0', $statement['params']['id'] ?? null);
    }

    public function testUserSavemoduleUsesHttpClassAndParameterizedReplace(): void
    {
        $payload = $this->runIsolatedPageScript(<<<'PHP'
$_GET['userid'] = '42';
$_GET['module'] = 'samplemodule';
$_POST = ['display_name' => "O'Reilly"];

require __DIR__ . '/tests/User/isolated_user_savemodule.php';
PHP);

        $statement = $payload['statement'] ?? null;
        $this->assertIsArray($statement);
        $this->assertStringContainsString('VALUES (:module,:userid,:setting,:value)', $statement['sql'] ?? '');
        $this->assertSame("O'Reilly", $statement['params']['value'] ?? null);
        $this->assertSame('INTEGER', $statement['types']['userid'] ?? null);
    }

    /**
     * Execute page-inclusion tests in an isolated PHP process so test-only
     * function/class shims cannot leak into the main PHPUnit process.
     *
     * @return array<string,mixed>
     */
    private function runIsolatedPageScript(string $snippet): array
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = sprintf(
            "define('LOTGD_TEST_ROOT', %s);\nrequire %s;\n",
            var_export($root, true),
            var_export($root . '/tests/bootstrap.php', true)
        );

        $code = $bootstrap . $snippet;
        $command = sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($code));
        $output = shell_exec($command);

        $this->assertNotNull($output, 'Isolated page script produced no output.');
        $this->assertIsString($output);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
