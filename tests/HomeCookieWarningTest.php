<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class HomeCookieWarningTest extends TestCase
{
    /**
     * @return array{message:string,flags:array,session_id:string,output:string}
     */
    private function runHomeRequest(?string $sessionId = null): array
    {
        $root = dirname(__DIR__);
        $rootExport = var_export($root, true);
        $script = <<<'PHP'
<?php
declare(strict_types=1);
chdir(__ROOT__);
ini_set('session.save_path', sys_get_temp_dir());
define('AJAX_MODE', true);
define('IS_INSTALLER', true);
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
$cleanupDb = false;
if (!file_exists('dbconnect.php')) {
    file_put_contents('dbconnect.php', "<?php return ['DB_HOST'=>'','DB_USER'=>'','DB_PASS'=>'','DB_NAME'=>'','DB_PREFIX'=>''];");
    $cleanupDb = true;
}
$shutdown = function () use ($cleanupDb): void {
    $sessionData = $_SESSION['session'] ?? [];
    $message = $sessionData['message'] ?? '';
    $flags = $sessionData['flags'] ?? [];
    $id = session_id();
    session_write_close();
    $buffer = '';
    if (ob_get_level() > 0) {
        $buffer = ob_get_contents();
        ob_end_clean();
    }
    echo json_encode([
        'message'    => $message,
        'flags'      => $flags,
        'session_id' => $id,
        'output'     => $buffer,
    ], JSON_THROW_ON_ERROR);
    if ($cleanupDb && file_exists('dbconnect.php')) {
        unlink('dbconnect.php');
    }
};
register_shutdown_function($shutdown);
$sessionId = $argc > 1 ? (string) $argv[1] : '';
if ($sessionId !== '') {
    session_id($sessionId);
}
$_GET = [];
$_POST = [];
$_REQUEST = [];
$_COOKIE = [];
if ($sessionId !== '') {
    $_COOKIE[session_name()] = $sessionId;
}
$_SESSION = [];
$_SERVER = [
    'REQUEST_URI'    => '/home.php',
    'REMOTE_ADDR'    => '127.0.0.1',
    'REQUEST_TIME'   => time(),
    'SERVER_NAME'    => 'localhost',
    'SERVER_PORT'    => '80',
    'HTTP_HOST'      => 'localhost',
    'PHP_SELF'       => '/home.php',
    'REQUEST_METHOD' => 'GET',
];
ob_start();
require 'home.php';
ob_end_clean();
PHP;

        $script = str_replace('__ROOT__', $rootExport, $script);

        $tempFile = tempnam(sys_get_temp_dir(), 'lotgd_home_');
        if ($tempFile === false) {
            self::fail('Failed to create temporary script file.');
        }

        file_put_contents($tempFile, $script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tempFile);
        if ($sessionId !== null && $sessionId !== '') {
            $cmd .= ' ' . escapeshellarg($sessionId);
        }

        exec($cmd, $output, $status);
        unlink($tempFile);

        $this->assertSame(0, $status, 'home.php runner should exit successfully.');

        $json = implode("\n", $output);

        /** @var array{message:string,flags:array,session_id:string,output:string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testFirstVisitDoesNotWarnAboutCookies(): void
    {
        $result = $this->runHomeRequest();

        $this->assertSame('', $result['message']);
        $this->assertArrayHasKey('lgi_seen', $result['flags']);
        $this->assertFalse($result['flags']['lgi_seen']);
        $this->assertArrayHasKey('lgi_failed', $result['flags']);
        $this->assertFalse($result['flags']['lgi_failed']);
        $this->assertArrayHasKey('output', $result);
        $this->assertStringNotContainsString('blocking cookies from this site', $result['output']);
    }

    public function testPersistentCookieBlockTriggersWarning(): void
    {
        $first = $this->runHomeRequest();
        $second = $this->runHomeRequest($first['session_id']);

        $this->assertStringContainsString('blocking cookies from this site', $second['output']);
        $this->assertTrue($second['flags']['lgi_failed']);
    }
}
