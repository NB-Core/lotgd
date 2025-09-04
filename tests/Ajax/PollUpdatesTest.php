<?php

namespace Tests\Ajax;

use Jaxon\Response\Response;
use Lotgd\Async\Handler\Commentary;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\MailDummySettings;
use Lotgd\Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
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
        global $session, $start_timeout_show_seconds, $never_timeout_if_browser_open, $output;

        $session = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';
        $start_timeout_show_seconds = 300;
        $never_timeout_if_browser_open = 0;
        Settings::setInstance(new MailDummySettings(['LOGINTIMEOUT' => 360]));
        $output = new class {
            public function appoencode($data, bool $priv = false)
            {
                return $data;
            }
        };
        Database::$mockResults = [
            [], // mailStatus
            [], // timeoutStatus
            [], // commentaryRefresh
        ];
    }

    public function testAggregatesResponsesFromCallbacks(): void
    {
        $handler = new Commentary();
        $response = $handler->pollUpdates('test', 0);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([], $response->getCommands());
    }
}
