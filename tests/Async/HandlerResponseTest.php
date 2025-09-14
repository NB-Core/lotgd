<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Jaxon\Response\Response;
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

        public function testTimeoutStatusReturnsResponse(): void
        {
            Database::$mockResults = [];
            $response = Timeout::getInstance()->timeoutStatus(false);
            $this->assertInstanceOf(Response::class, $response);
        }
    }
}
