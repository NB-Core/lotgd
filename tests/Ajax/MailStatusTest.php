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

            $session = ['user' => ['acctid' => 1, 'loggedin' => 1]];
            require_once __DIR__ . '/../bootstrap.php';
            Settings::setInstance(new DummySettings(['LOGINTIMEOUT' => 360]));
            Database::$queryCacheResults = [];
        }

        public function testUnreadMailIsReportedForTheNotices(): void
        {
            global $mail_table;

            Database::$queryCacheResults = [
                'mail-1' => [['seencount' => 0, 'notseen' => 1]],
            ];
            $mail_table = [
                ['messageid' => 7, 'msgto' => 1, 'seen' => 0],
            ];

            $response = (new Mail())->mailStatus(true);

            // The notices in the upper right take it from here: data, no commands.
            $this->assertSame([], $response->getCommands());
            $notices = $response->getData()['notices'] ?? [];
            $this->assertSame(['unread' => 1, 'lastId' => 7], $notices['mail'] ?? null);
            $this->assertStringContainsString('mail.php', (string) ($notices['mailLink'] ?? ''));
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
