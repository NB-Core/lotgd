<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Doctrine\DBAL\ParameterType;
    use Jaxon\Response\Response;
    use Lotgd\Async\Handler\Bans;
    use Lotgd\Async\Handler\Commentary;
    use Lotgd\Async\Handler\Mail;
    use Lotgd\Async\Handler\Timeout;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\MailDummySettings;
    use Lotgd\Settings;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
    final class HandlerResponseTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $output;
            $session = [];
            $_SERVER['SCRIPT_NAME'] = 'test.php';
            $_SERVER['REQUEST_URI'] = '/async/process.php';
            $output = new class {
                public function appoencode($data, $priv = false)
                {
                    return $data;
                }
            };
            require_once __DIR__ . '/../bootstrap.php';
            Settings::setInstance(new MailDummySettings(['LOGINTIMEOUT' => 360]));
            Database::$mockResults = [];

            Timeout::getInstance()->setStartTimeoutShowSeconds(300);
            Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(false);
        }

        public function testMailStatusReturnsResponse(): void
        {
            Database::$mockResults = [[['lastid' => 0]]];
            $response = (new Mail())->mailStatus(false);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testCommentaryRefreshReturnsResponse(): void
        {
            Database::$mockResults = [[]];
            $response = (new Commentary())->commentaryRefresh('section', 0);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testCommentaryRefreshUsesBoundParameters(): void
        {
            $section = "Trader's \\ Den";
            $row = [
                'commentid' => 10,
                'section' => $section,
                'comment' => 'Hello world',
                'author' => 1,
                'acctid' => 1,
                'name' => 'Tester',
                'superuser' => 0,
                'clanrank' => 0,
                'clanshort' => '',
                'postdate' => '2023-01-01 00:00:00',
            ];

            Database::$mockResults = [[ $row ]];

            $handler = new Commentary();
            $response = $handler->commentaryRefresh($section, 0);

            $this->assertInstanceOf(Response::class, $response);

            $commands = $response->getCommands();
            $this->assertNotEmpty($commands);
            $this->assertSame('ap', $commands[0]['cmd']);
            $this->assertSame($section . '-comment', $commands[0]['id']);

            $conn = Database::getDoctrineConnection();
            $this->assertSame($section, $conn->lastFetchAllParams['section'] ?? null);
            $this->assertSame(0, $conn->lastFetchAllParams['lastId'] ?? null);
            $this->assertSame(ParameterType::STRING, $conn->lastFetchAllTypes['section'] ?? null);
            $this->assertSame(ParameterType::INTEGER, $conn->lastFetchAllTypes['lastId'] ?? null);
        }

        public function testTimeoutStatusReturnsResponse(): void
        {
            Database::$mockResults = [];
            $response = Timeout::getInstance()->timeoutStatus(false);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testBansAffectedUsersAssignsHtml(): void
        {
            Database::$mockResults = [[
                ['name' => 'Alpha'],
                ['name' => 'Beta'],
            ]];

            $response = (new Bans())->affectedUsers('127.0.0.1', 'device-id', 'user3');

            $this->assertInstanceOf(Response::class, $response);

            $commands = $response->getCommands();
            $assign = array_values(array_filter(
                $commands,
                fn($command) => ($command['cmd'] ?? '') === 'as' && ($command['id'] ?? '') === 'user3'
            ));

            $this->assertNotEmpty($assign);
            $this->assertStringContainsString('Alpha', $assign[0]['data']);
            $this->assertStringContainsString('Beta', $assign[0]['data']);

            $conn = Database::getDoctrineConnection();
            $this->assertSame(
                ['ipFilter' => '127.0.0.1', 'uniqueId' => 'device-id'],
                $conn->lastFetchAllParams
            );
            $this->assertSame(
                ['ipFilter' => ParameterType::STRING, 'uniqueId' => ParameterType::STRING],
                $conn->lastFetchAllTypes
            );
        }

        public function testBansAffectedUsersUsesBindingsForQuotedInput(): void
        {
            Database::$mockResults = [[
                ['name' => "Shaun \"Quote\" O'Connor"],
            ]];

            $response = (new Bans())->affectedUsers("127.0.0.1' OR '1'='1", "abc\" OR \"1\"='1", 'user1');

            $this->assertInstanceOf(Response::class, $response);

            $conn = Database::getDoctrineConnection();
            $this->assertSame(
                [
                    'ipFilter' => "127.0.0.1' OR '1'='1",
                    'uniqueId' => "abc\" OR \"1\"='1",
                ],
                $conn->lastFetchAllParams
            );
            $this->assertSame(
                ['ipFilter' => ParameterType::STRING, 'uniqueId' => ParameterType::STRING],
                $conn->lastFetchAllTypes
            );

            $lastQuery = end($conn->queries) ?: '';
            $this->assertStringContainsString(':ipFilter', $lastQuery);
            $this->assertStringContainsString(':uniqueId', $lastQuery);
            $this->assertStringNotContainsString("127.0.0.1' OR '1'='1", $lastQuery);
            $this->assertStringNotContainsString("abc\" OR \"1\"='1", $lastQuery);
        }

        public function testBansAffectedUsersHandlesMultibyteInput(): void
        {
            Database::$mockResults = [[
                ['name' => 'さくら"🌸"'],
            ]];

            $response = (new Bans())->affectedUsers('さくさく', 'ユニーク🌸', 'user2');

            $this->assertInstanceOf(Response::class, $response);

            $conn = Database::getDoctrineConnection();
            $this->assertSame(
                ['ipFilter' => 'さくさく', 'uniqueId' => 'ユニーク🌸'],
                $conn->lastFetchAllParams
            );
            $this->assertSame(
                ['ipFilter' => ParameterType::STRING, 'uniqueId' => ParameterType::STRING],
                $conn->lastFetchAllTypes
            );
        }
    }
}
