<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\GameLog;
use Lotgd\SecurityLog;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class SecurityLogTest extends TestCase
{
    private ?string $errorLogFile = null;
    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();

        global $session;
        $session['user']['acctid'] = 42;
    }

    protected function tearDown(): void
    {
        if ($this->errorLogFile !== null) {
            ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
            if (is_file($this->errorLogFile)) {
                unlink($this->errorLogFile);
            }
            $this->errorLogFile = null;
        }

        Database::$queries = [];
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    /**
     * Redirect error_log() into a file the assertions can read.
     *
     * This has to happen inside the test method: PHPUnit restores its ini
     * snapshot between setUp() and the test body, so a redirection made in
     * setUp() is undone before the first line is written.
     */
    private function captureErrorLog(): void
    {
        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'lotgd_seclog_');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    private function errorLog(): string
    {
        return $this->errorLogFile === null ? '' : (string) file_get_contents($this->errorLogFile);
    }

    public function testEventIsWrittenToTheGameLogUnderTheSecurityCategory(): void
    {
        SecurityLog::event('Superuser page access denied', ['uri' => '/rawsql.php'], 7, GameLog::SEVERITY_WARNING);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertStringContainsString('INSERT INTO gamelog', $record['sql']);
        $this->assertSame('security', $record['params']['category']);
        $this->assertSame('warning', $record['params']['severity']);
        $this->assertSame(7, $record['params']['who']);
        $this->assertStringContainsString('Superuser page access denied', $record['params']['message']);
        $this->assertStringContainsString('uri=/rawsql.php', $record['params']['message']);
    }

    public function testTheSameEventAlsoReachesTheErrorLog(): void
    {
        $this->captureErrorLog();
        SecurityLog::event('Refused a state change with an invalid CSRF token', ['page' => 'user.php']);

        $log = $this->errorLog();
        $this->assertStringContainsString('[security] Refused a state change with an invalid CSRF token', $log);
        $this->assertStringContainsString('page=user.php', $log);
    }

    /**
     * The correlation id ties a line in the container log to the row an operator
     * finds in the game log, and lets an error payload reference an event without
     * disclosing what it says.
     */
    public function testTheCorrelationIdIsReturnedAndAppearsInBothChannels(): void
    {
        $this->captureErrorLog();
        $diagnosticId = SecurityLog::event('Async request denied by the authorization policy');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $diagnosticId);
        $this->assertStringContainsString('diag=' . $diagnosticId, $this->errorLog());

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;
        $this->assertNotNull($record);
        $this->assertStringContainsString('diag=' . $diagnosticId, $record['params']['message']);
    }

    /**
     * High-frequency paths an unauthenticated caller can drive must not turn one
     * request into one database write. The error log still takes every event.
     */
    public function testAnUnpersistedEventNeverReachesTheDatabase(): void
    {
        $this->captureErrorLog();
        SecurityLog::event('Failed login attempt', ['login' => 'someone'], null, GameLog::SEVERITY_WARNING, false);

        $this->assertSame([], Database::getDoctrineConnection()->executeStatements);
        $this->assertStringContainsString('Failed login attempt', $this->errorLog());
    }

    /**
     * Context values can come straight from a request parameter, so a caller must
     * not be able to inject newlines and forge a second log line.
     */
    public function testControlCharactersCannotForgeALogLine(): void
    {
        $this->captureErrorLog();
        SecurityLog::event(
            "Refused a state change\nFAKE: granted",
            ['login' => "victim\r\n[security] Refused nothing"]
        );

        $log = $this->errorLog();
        $this->assertStringNotContainsString("\nFAKE: granted", $log);
        $this->assertStringNotContainsString("\n[security] Refused nothing", $log);
        $this->assertStringContainsString('login=victim  [security] Refused nothing', $log);
    }

    /**
     * Empty context values are dropped so a denial from an anonymous caller does
     * not read as a row of empty keys.
     */
    public function testEmptyContextValuesAreOmitted(): void
    {
        SecurityLog::event('Superuser page access denied', ['uri' => '/user.php', 'referer' => '', 'name' => null]);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;
        $this->assertNotNull($record);
        $this->assertStringNotContainsString('referer=', $record['params']['message']);
        $this->assertStringNotContainsString('name=', $record['params']['message']);
        $this->assertStringContainsString('uri=/user.php', $record['params']['message']);
    }

    public function testBooleanContextValuesReadAsYesOrNo(): void
    {
        SecurityLog::event('Failed login attempt', ['privileged_target' => true, 'throttled' => false]);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;
        $this->assertNotNull($record);
        $this->assertStringContainsString('privileged_target=yes', $record['params']['message']);
        $this->assertStringContainsString('throttled=no', $record['params']['message']);
    }

    public function testTheSessionAccountIsUsedWhenNoAccountIsGiven(): void
    {
        SecurityLog::event('Refused a state change with an invalid CSRF token');

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;
        $this->assertNotNull($record);
        $this->assertSame(42, $record['params']['who']);
    }
}
