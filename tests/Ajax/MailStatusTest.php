<?php

declare(strict_types=1);

namespace Lotgd\Tests\Ajax {

    use Jaxon\Response\Response;
    use Lotgd\Async\Handler\Mail;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\MailDummySettings;
    use Lotgd\Settings;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     */
    final class MailStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            $session = ['user' => ['acctid' => 1]];
            require_once __DIR__ . '/../bootstrap.php';
            Settings::setInstance(new MailDummySettings(['LOGINTIMEOUT' => 360]));
            Database::$queryCacheResults = [];
        }

        public function testUnreadMailTriggersNotify(): void
        {
            Database::$queryCacheResults = [
                'mail-1' => [['seencount' => 0, 'notseen' => 1]],
            ];
            Database::$mockResults = [
                [['lastid' => 7, 'unread' => 1]],
            ];

            $response = (new Mail())->mailStatus(true);
            $commands = $response->getCommands();

            $assign = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'as' && ($c['id'] ?? '') === 'maillink'));
            $this->assertNotEmpty($assign);
            $this->assertStringContainsString('mail.php', $assign[0]['data']);

            $scripts = array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'js');
            $notify = array_values(array_filter($scripts, fn($c) => str_contains($c['data'] ?? '', 'lotgdMailNotify(7, 1)')));
            $this->assertNotEmpty($notify);
            $title = array_values(array_filter($scripts, fn($c) => str_contains($c['data'] ?? '', 'Legend of the Green Dragon - 1 new mail(s)')));
            $this->assertNotEmpty($title);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testNoUnreadMailNoNotify(): void
        {
            Database::$queryCacheResults = [
                'mail-1' => [['seencount' => 0, 'notseen' => 0]],
            ];
            Database::$mockResults = [
                [['lastid' => 5, 'unread' => 0]],
            ];

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
