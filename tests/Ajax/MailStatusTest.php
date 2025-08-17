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
            global $test_accounts_query_result;
            if (strpos($sql, "login = '") !== false) {
                return [];
            }
            if (strpos($sql, 'name LIKE') !== false) {
                return $test_accounts_query_result ?? [];
            }
            if (strpos($sql, 'SELECT MAX(messageid)') !== false) {
                return $GLOBALS['db_result'] ?? [];
            }
            return $GLOBALS['db_result'] ?? [];
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
            return $GLOBALS['maillink_result'] ?? '';
        }
    }

    if (!function_exists('maillinktabtext')) {
        function maillinktabtext(): string
        {
            return $GLOBALS['maillink_tabtext'] ?? '';
        }
    }
}

namespace Lotgd\Tests\Ajax {

    use Jaxon\Response\Response;
    use PHPUnit\Framework\TestCase;

    final class MailStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $maillink_result, $maillink_tabtext, $db_result;

            $session = ['user' => ['acctid' => 1]];
            $maillink_result = '<a>mail</a>';
            $maillink_tabtext = '';
            $db_result = [['lastid' => 0]];

            require_once __DIR__ . '/../../ext/ajax_server.php';
        }

        public function testUnreadMailTriggersNotify(): void
        {
            global $maillink_tabtext, $db_result;

            $maillink_tabtext = '1 new mail';
            $db_result = [['lastid' => 7]];

            $response = \mail_status(true);
            $commands = $response->getCommands();

            $assign = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'as' && ($c['id'] ?? '') === 'maillink'));
            $this->assertNotEmpty($assign);
            $this->assertSame('<a>mail</a>', $assign[0]['data']);

            $scripts = array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'js');
            $notify = array_values(array_filter($scripts, fn($c) => str_contains($c['data'] ?? '', 'lotgdMailNotify(7)')));
            $this->assertNotEmpty($notify);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testNoUnreadMailNoNotify(): void
        {
            global $maillink_tabtext, $db_result;

            $maillink_tabtext = '';
            $db_result = [['lastid' => 5]];

            $response = \mail_status(true);
            $commands = $response->getCommands();

            $scripts = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'js'));
            $this->assertCount(0, $scripts);
        }

        public function testMissingAcctidReturnsEmptyResponse(): void
        {
            global $session;

            $session = [];

            $response = \mail_status(true);
            $this->assertSame([], $response->getCommands());
        }
    }
}
