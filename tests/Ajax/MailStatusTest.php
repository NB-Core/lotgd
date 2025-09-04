<?php

declare(strict_types=1);

namespace {
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
    use Lotgd\Async\Handler\Mail;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    final class MailStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $maillink_result, $maillink_tabtext;

            $session = ['user' => ['acctid' => 1]];
            $maillink_result = '<a>mail</a>';
            $maillink_tabtext = '';
            require_once __DIR__ . '/../bootstrap.php';
            Database::$mockResults = [[['lastid' => 0]]];
        }

        public function testUnreadMailTriggersNotify(): void
        {
            global $maillink_tabtext;

            $maillink_tabtext = '1 new mail';
            Database::$mockResults = [[['lastid' => 7]]];

            $response = (new Mail())->mailStatus(true);
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
            global $maillink_tabtext;

            $maillink_tabtext = '';
            Database::$mockResults = [[['lastid' => 5]]];

            $response = (new Mail())->mailStatus(true);
            $commands = $response->getCommands();

            $scripts = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'js'));
            $this->assertCount(0, $scripts);
        }

        public function testMissingAcctidReturnsEmptyResponse(): void
        {
            global $session;

            $session = [];
            Database::$mockResults = [];

            $response = (new Mail())->mailStatus(true);
            $this->assertSame([], $response->getCommands());
        }
    }
}
