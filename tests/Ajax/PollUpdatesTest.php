<?php

namespace Tests\Ajax;

use Jaxon\Response\Response;
use Lotgd\Async\Handler\Commentary;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

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
    function db_free_result(array $result): void
    {
    }
}
if (!function_exists('maillink')) {
    function maillink(): string
    {
        return '';
    }
}
if (!function_exists('maillinktabtext')) {
    function maillinktabtext(): string
    {
        return '';
    }
}

class PollUpdatesTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $start_timeout_show_seconds, $never_timeout_if_browser_open, $settings, $output;

        $session = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';
        $start_timeout_show_seconds = 300;
        $never_timeout_if_browser_open = 0;
        $settings = new class {
            public function getSetting(string $name, mixed $default = null): mixed
            {
                return $default;
            }
        };
        $output = new class {
            public function appoencode($data, bool $priv = false)
            {
                return $data;
            }
        };
    }

    public function testAggregatesResponsesFromCallbacks(): void
    {
        $handler = new Commentary();
        $response = $handler->pollUpdates('test', 0);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([], $response->getCommands());
    }
}
