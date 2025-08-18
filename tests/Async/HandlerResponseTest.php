<?php

declare(strict_types=1);

namespace {
    if (!function_exists('db_prefix')) {
        function db_prefix(string $name): string
        {
            return $name;
        }
    }
    if (!function_exists('db_query')) {
        function db_query(string $sql): array
        {
            return [];
        }
    }
    if (!function_exists('db_fetch_assoc')) {
        function db_fetch_assoc(array &$result): ?array
        {
            return array_shift($result);
        }
    }
    if (!function_exists('db_free_result')) {
        function db_free_result(array &$result): void
        {
            $result = [];
        }
    }
}

namespace Lotgd\Tests\Async {

use Jaxon\Response\Response;
use Lotgd\Async\Handler\Mail;
use Lotgd\Async\Handler\Commentary;
use Lotgd\Async\Handler\Timeout;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class HandlerResponseTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $output;
        $session = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';
        $output = new class {
            public function appoencode($data, $priv = false)
            {
                return $data;
            }
        };
        require_once __DIR__ . '/../bootstrap.php';
    }

    public function testMailStatusReturnsResponse(): void
    {
        $response = (new Mail())->mailStatus(false);
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testCommentaryRefreshReturnsResponse(): void
    {
        $response = (new Commentary())->commentaryRefresh('section', 0);
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testTimeoutStatusReturnsResponse(): void
    {
        $response = (new Timeout())->timeoutStatus(false);
        $this->assertInstanceOf(Response::class, $response);
    }
}
}
