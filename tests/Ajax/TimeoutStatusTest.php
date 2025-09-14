<?php

declare(strict_types=1);

namespace {
}

namespace Lotgd {
    if (!class_exists(__NAMESPACE__ . '\\Translator')) {
        class Translator
        {
            public static function getInstance(): self
            {
                return new self();
            }

            public static function translateInline(string $text): string
            {
                return $text;
            }

            public static function enableTranslation(bool $enable): void
            {
            }

            public function sprintfTranslate(string $format, ...$args): string
            {
                return vsprintf($format, $args);
            }
        }
    }
}

namespace Lotgd\Tests\Ajax {

    use Lotgd\Async\Handler\Timeout;
    use PHPUnit\Framework\TestCase;

    final class TimeoutStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $settings, $output;

            $session = ['user' => ['acctid' => 1, 'laston' => date('Y-m-d H:i:s', strtotime('-700 seconds'))]];

            Timeout::getInstance()->setStartTimeoutShowSeconds(300);
            Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(false);

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

            require_once __DIR__ . '/../bootstrap.php';
        }

        public function testTimeoutWarningIsReturned(): void
        {
            $response = Timeout::getInstance()->timeoutStatus(true);
            $commands = $response->getCommands();

            $this->assertNotEmpty($commands);
            $this->assertSame('notify', $commands[0]['id'] ?? null);
            $this->assertStringContainsString('TIMEOUT', $commands[0]['data'] ?? '');
        }

        public function testNoWarningWhenSessionUserAbsent(): void
        {
            global $session;
            $session = [];

            $response = Timeout::getInstance()->timeoutStatus(true);
            $this->assertEmpty($response->getCommands());
        }
    }
}
