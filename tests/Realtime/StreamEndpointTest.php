<?php

declare(strict_types=1);

namespace Lotgd\Tests\Realtime;

use Lotgd\Realtime;
use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Who may open async/stream.php (docs/ReactiveCore.md, Phase 7). An
 * EventSource sends no custom header, so the token in the query is the
 * request's only proof that the game's own page opened it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StreamEndpointTest extends TestCase
{
    private DoctrineConnection $connection;
    private string $cacheDir = '';
    private string $token = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings([
            'realtime_enabled' => 1, 'partial_render' => 1, 'realtime_transport' => 'sse', 'LOGINTIMEOUT' => 900,
            'realtime_stream_seconds' => 20,
        ]));
        Realtime::resetState();
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-stream-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        TwigTemplate::init('aurora', $this->cacheDir);

        $GLOBALS['session'] = [
            'loggedin' => true,
            'user' => ['acctid' => 7, 'loggedin' => 1, 'superuser' => 0, 'laston' => date('Y-m-d H:i:s'),
                'prefs' => ['partialrender' => 1]],
        ];
        $_SESSION = [];
        $this->token = Csrf::token(Csrf::SCOPE_ASYNC);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $_GET = ['csrf' => $this->token, 'cursor' => '3', 'section' => 'village'];

        if (!defined('LOTGD_STREAM_TEST_MODE')) {
            define('LOTGD_STREAM_TEST_MODE', true);
        }
        require_once dirname(__DIR__, 2) . '/async/stream.php';
    }

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        Database::resetDoctrineConnection();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->cacheDir);
    }

    public function testThePlayersOwnPageMayOpenTheStream(): void
    {
        self::assertNull(lotgd_stream_refusal());
    }

    public function testWithoutTheTokenTheStreamIsRefused(): void
    {
        $_GET['csrf'] = 'x';
        self::assertSame([403, 'csrf_invalid'], lotgd_stream_refusal());

        unset($_GET['csrf']);
        self::assertSame([403, 'csrf_invalid'], lotgd_stream_refusal());

        $_GET['csrf'] = [$this->token];
        self::assertSame([403, 'csrf_invalid'], lotgd_stream_refusal());
    }

    public function testARefusedTokenIsRecordedInTheErrorLogOnly(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'lotgd-stream-log');
        ini_set('error_log', (string) $log);
        $_GET['csrf'] = 'x';

        ob_start();
        lotgd_stream_entrypoint(ob_get_level());
        $answer = (string) ob_get_clean();

        self::assertSame('{"status":"error","error":"csrf_invalid"}', $answer);
        $line = (string) file_get_contents((string) $log);
        unlink((string) $log);
        self::assertStringContainsString('Async stream refused', $line);
        self::assertStringContainsString('csrf_invalid', $line);
        self::assertStringNotContainsString($this->token, $line, 'Never the token.');
        self::assertSame([], preg_grep('/^INSERT INTO gamelog/', array_map('strval', $this->connection->queries)), 'No row per refusal.');
    }

    public function testACrossSiteRequestIsRefusedEvenWithTheToken(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        self::assertSame([403, 'cross_site'], lotgd_stream_refusal());

        // A browser that sends no such header still needs the token.
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        self::assertNull(lotgd_stream_refusal());
    }

    public function testALoggedOutRequestIsRefused(): void
    {
        $GLOBALS['session']['user']['loggedin'] = 0;

        self::assertSame([401, 'unauthenticated'], lotgd_stream_refusal());
    }

    public function testWithPollingTheStreamIsNotThere(): void
    {
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'partial_render' => 1, 'realtime_transport' => 'poll']));

        self::assertSame([404, 'disabled'], lotgd_stream_refusal());
    }

    public function testOnlyGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        self::assertSame([405, 'method_not_allowed'], lotgd_stream_refusal());
    }

    public function testAStreamRightAfterAnotherIsRateLimited(): void
    {
        self::assertNull(lotgd_stream_refusal());
        self::assertSame([429, 'rate_limited'], lotgd_stream_refusal());
    }

    public function testTheBrowsersLastEventIdWinsOverThePagesCursor(): void
    {
        self::assertSame(3, lotgd_stream_cursor());

        $_SERVER['HTTP_LAST_EVENT_ID'] = '17';
        self::assertSame(17, lotgd_stream_cursor());

        $_SERVER['HTTP_LAST_EVENT_ID'] = '17; DROP';
        self::assertSame(0, lotgd_stream_cursor());
    }

    public function testTheStreamClaimsThePlayersSlotReleasesTheSessionAndStreams(): void
    {
        session_id('lotgdstreamtest');
        @session_start();
        $this->connection->queryResultsByPattern = [
            '/SELECT token FROM realtime_streams/' => [],
            '/SELECT next_seq FROM relay_sequence/' => [['next_seq' => 4]],
        ];

        ob_start();
        lotgd_stream_entrypoint(ob_get_level());
        $output = (string) ob_get_clean();

        self::assertSame(PHP_SESSION_NONE, session_status(), 'Released before the stream starts.');
        self::assertStringStartsWith('REPLACE INTO realtime_streams', (string) $this->connection->executeStatements[0]['sql']);
        // The claimed row is not there in the stub: the stream sees itself replaced at once.
        self::assertSame("retry: 2000\n\nevent: replaced\ndata: {}\n\n", $output);
    }
}
