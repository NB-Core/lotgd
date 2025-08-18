<?php

declare(strict_types=1);

namespace {
    if (!function_exists('sprintf_translate')) {
        function sprintf_translate(string $format, ...$args): string
        {
            return vsprintf($format, $args);
        }
    }

    if (!function_exists('timeout_status')) {
        function timeout_status($args = false): \Jaxon\Response\Response
        {
            global $session, $start_timeout_show_seconds, $never_timeout_if_browser_open, $settings;

            $response = \Jaxon\jaxon()->newResponse();
            if ($args === false || !isset($session['user'])) {
                return $response;
            }

            $lastOn = strtotime($session['user']['laston']);
            $elapsed = time() - $lastOn;
            $remaining = $settings->getSetting('LOGINTIMEOUT', 900) - $elapsed;

            $warning = '';
            if ($remaining <= 0) {
                $warning = 'Your session has timed out!';
            } elseif ($remaining < $start_timeout_show_seconds) {
                $warning = 'TIMEOUT';
            }

            if ($warning !== '') {
                $response->assign('notify', 'innerHTML', $warning);
            }

            return $response;
        }
    }
}

namespace Lotgd\Tests\Ajax {

    use PHPUnit\Framework\TestCase;

    final class TimeoutStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $start_timeout_show_seconds, $never_timeout_if_browser_open, $settings, $output;

            $session = ['user' => ['acctid' => 1, 'laston' => date('Y-m-d H:i:s', strtotime('-700 seconds'))]];
            $start_timeout_show_seconds = 300;
            $never_timeout_if_browser_open = 0;
            $settings = new class {
                private array $values = ['LOGINTIMEOUT' => 900];
                public function getSetting(string $name, mixed $default = null): mixed
                {
                    return $this->values[$name] ?? $default;
                }
            };
            $output = new class {
                public function appoencode($data, bool $priv = false)
                {
                    return $data;
                }
            };
          
            require_once __DIR__ . '/../../async/server.php';

        }

        public function testTimeoutWarningIsReturned(): void
        {
            $response = \timeout_status(true);
            $commands = $response->getCommands();

            $this->assertNotEmpty($commands);
            $this->assertSame('notify', $commands[0]['id'] ?? null);
            $this->assertStringContainsString('TIMEOUT', $commands[0]['data'] ?? '');
        }

        public function testNoWarningWhenSessionUserAbsent(): void
        {
            global $session;
            $session = [];

            $response = \timeout_status(true);
            $this->assertEmpty($response->getCommands());
        }
    }
}
