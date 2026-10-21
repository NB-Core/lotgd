<?php

declare(strict_types=1);

namespace Lotgd\Tests\Ajax {

    use Lotgd\Async\Response;
    use Lotgd\Async\Handler\Mail;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Settings;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    final class MailStatusTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            $session = ['user' => ['acctid' => 1]];
            require_once __DIR__ . '/../bootstrap.php';
            Settings::setInstance(new DummySettings(['LOGINTIMEOUT' => 360]));
            Database::$queryCacheResults = [];
        }

        public function testUnreadMailTriggersNotify(): void
        {
            global $mail_table;

            Database::$queryCacheResults = [
                'mail-1' => [['seencount' => 0, 'notseen' => 1]],
            ];
            $mail_table = [
                ['messageid' => 7, 'msgto' => 1, 'seen' => 0],
            ];

            $response = (new Mail())->mailStatus(true);
            $commands = $response->getCommands();

            $assign = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'assign' && ($c['id'] ?? '') === 'maillink'));
            $this->assertNotEmpty($assign);
            $this->assertStringContainsString('mail.php', $assign[0]['value']);

            $notify = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'call'));
            $this->assertNotEmpty($notify);
            $this->assertSame('lotgdMailNotify', $notify[0]['fn']);
            $this->assertSame([7, 1], $notify[0]['args']);
            $title = array_values(array_filter($commands, fn($c) => ($c['cmd'] ?? '') === 'title'));
            $this->assertNotEmpty($title);
            $this->assertStringContainsString('Legend of the Green Dragon', $title[0]['value']);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testNoUnreadMailNoNotify(): void
        {
            global $mail_table;

            Database::$queryCacheResults = [
                'mail-1' => [['seencount' => 0, 'notseen' => 0]],
            ];
            $mail_table = [
                ['messageid' => 5, 'msgto' => 1, 'seen' => 1],
            ];

            $response = (new Mail())->mailStatus(true);
            $commands = $response->getCommands();

            $scripts = array_values(array_filter($commands, fn($c) => in_array($c['cmd'] ?? '', ['call', 'title'], true)));
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
