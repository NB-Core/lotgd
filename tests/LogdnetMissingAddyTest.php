<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class LogdnetMissingAddyTest extends TestCase
{
    public function testMissingAddyReturnsBadRequest(): void
    {
        $root = dirname(__DIR__);
        $rootExport = var_export($root, true);

        $script = <<<'PHP'
<?php
declare(strict_types=1);
chdir(__ROOT__);
if (!class_exists('Lotgd\\MySQL\\Database', false)) {
    eval(<<<'STUB'
namespace Lotgd\MySQL;
class Database
{
    public static function setPrefix(string $prefix): void {}
    public static function connect(string $host, string $user, string $pass): bool
    {
        return true;
    }
    public static function setCharset(string $charset): bool
    {
        return true;
    }
    public static function selectDb(string $dbname): bool
    {
        return true;
    }
    public static function prefix(string $name, string|false|null $force = null): string
    {
        if ($force !== null && $force !== false) {
            return $force . $name;
        }

        return $name;
    }
    public static function query(string $sql, bool $die = true): array
    {
        return [];
    }
    public static function queryCached(string $sql, string $name, int $duration = 900): array
    {
        return [];
    }
    public static function fetchAssoc(mixed $result): array
    {
        return [];
    }
    public static function freeResult(mixed $result): void {}
    public static function numRows(mixed $result): int
    {
        return 0;
    }
    public static function tableExists(string $table): bool
    {
        return false;
    }
    public static function getQueryCount(): int
    {
        return 0;
    }
    public static function getInfo(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
STUB
    );
}
ini_set('session.save_path', sys_get_temp_dir());
$cleanupDb = false;
if (!file_exists('dbconnect.php')) {
    file_put_contents(
        'dbconnect.php',
        "<?php return ['DB_HOST'=>'','DB_USER'=>'','DB_PASS'=>'','DB_NAME'=>'','DB_PREFIX'=>''];"
    );
    $cleanupDb = true;
}
register_shutdown_function(static function () use ($cleanupDb): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($cleanupDb && file_exists('dbconnect.php')) {
        unlink('dbconnect.php');
    }
});
$_GET = ['op' => ''];
$_POST = [];
$_REQUEST = $_GET;
$_COOKIE = [];
$_SESSION = [];
$_SERVER = [
    'REMOTE_ADDR' => '127.0.0.1',
    'REQUEST_TIME' => time(),
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'REQUEST_URI' => '/logdnet.php',
    'PHP_SELF' => '/logdnet.php',
    'REQUEST_METHOD' => 'GET',
];
ob_start();
require 'logdnet.php';
$buffers = [];
while (ob_get_level() > 0) {
    $buffers[] = ob_get_clean();
}
$output = implode('', array_reverse($buffers));
echo json_encode([
    'code'   => http_response_code(),
    'output' => $output,
], JSON_THROW_ON_ERROR);
PHP;

        $script = str_replace('__ROOT__', $rootExport, $script);

        $tempFile = tempnam(sys_get_temp_dir(), 'lotgd_logdnet_');
        if ($tempFile === false) {
            self::fail('Failed to create temporary script file.');
        }

        file_put_contents($tempFile, $script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tempFile);
        exec($cmd, $output, $status);
        unlink($tempFile);

        $this->assertSame(0, $status, 'logdnet.php runner should exit successfully.');

        $json = implode("\n", $output);
        /** @var array{code:int,output:string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(400, $data['code'], 'logdnet.php should respond with HTTP 400.');
        $this->assertStringContainsString('Missing required addy parameter.', $data['output']);
    }
}
