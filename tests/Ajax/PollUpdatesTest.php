<?php

namespace Tests\Ajax;

use Jaxon\Response\Response;
use PHPUnit\Framework\TestCase;
use function Jaxon\jaxon;

require_once __DIR__ . '/../bootstrap.php';

if (!function_exists(__NAMESPACE__ . '\\mail_status')) {
    function mail_status($args = false): Response
    {
        $response = jaxon()->newResponse();
        $response->addCommand(['cmd' => 'mail'], 'mail');
        return $response;
    }
}

if (!function_exists(__NAMESPACE__ . '\\timeout_status')) {
    function timeout_status($args = false): Response
    {
        $response = jaxon()->newResponse();
        $response->addCommand(['cmd' => 'timeout'], 'timeout');
        return $response;
    }
}

if (!function_exists(__NAMESPACE__ . '\\commentary_refresh')) {
    function commentary_refresh(string $section, int $lastId): Response
    {
        $response = jaxon()->newResponse();
        $response->addCommand(['cmd' => 'commentary'], 'commentary');
        return $response;
    }
}

if (!function_exists(__NAMESPACE__ . '\\poll_updates')) {
    function poll_updates(string $section, int $lastId): Response
    {
        $response = jaxon()->newResponse();
        $response->appendResponse(mail_status(true));
        $response->appendResponse(timeout_status(true));
        $response->appendResponse(commentary_refresh($section, $lastId));
        return $response;
    }
}

class PollUpdatesTest extends TestCase
{
    public function testAggregatesResponsesFromCallbacks(): void
    {
        $response = poll_updates('test', 123);
        $commands = $response->getCommands();

        $this->assertCount(3, $commands);
        $this->assertSame('mail', $commands[0]['cmd']);
        $this->assertSame('timeout', $commands[1]['cmd']);
        $this->assertSame('commentary', $commands[2]['cmd']);
    }
}
