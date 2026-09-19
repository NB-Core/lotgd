<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\GameLog;
use Lotgd\SecurityLog;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class SecurityLogTest extends TestCase
{
    /**
     * A byte sequence that is not valid UTF-8, as a request can carry.
     *
     * `\xC3\x28` is a lead byte followed by something that cannot continue it;
     * `\xFF` cannot begin a sequence at all. Both are what a query string looks
     * like when it was typed in another encoding, or when someone is probing.
     */
    private const MALFORMED = "\xC3\x28probe\xFF";

    private ?string $errorLogFile = null;
    private string|false $previousErrorLog = false;

    /**
     * Restored between tests, because one of them sets it on purpose and the
     * logger's own handling of it is what several others are about.
     */
    private int|string $previousSubstitute = 0x3F;

    protected function setUp(): void
    {
        $this->previousSubstitute = mb_substitute_character();

        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();

        global $session;
        $session['user']['acctid'] = 42;
    }

    protected function tearDown(): void
    {
        mb_substitute_character($this->previousSubstitute);

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
     * The row the game log takes has to be valid UTF-8, or there is no row.
     *
     * This is the whole of why the encoding matters here. The game log is an
     * INSERT over a utf8mb4 connection, which refuses a malformed string
     * outright -- so before this, an event carrying one stray byte raised from
     * inside the logger, on the path of a refusal that was being recorded
     * because something had already gone wrong. The event an operator most
     * needs was the one that never arrived.
     *
     * Asserted on the bound parameter rather than on the return of a private
     * method, because the bound parameter is the thing the database is handed.
     */
    public function testAMalformedValueStillProducesARowTheDatabaseCanAccept(): void
    {
        SecurityLog::event('Refused a state change', ['login' => self::MALFORMED]);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertTrue(
            mb_check_encoding($record['params']['message'], 'UTF-8'),
            'the message bound into the INSERT is not valid UTF-8, so utf8mb4 would reject the write'
        );
    }

    /**
     * And the message itself, which is a separate call to the same sanitiser.
     *
     * Separate because it is: event() sanitises the message, renderContext()
     * sanitises each key and each value. A fix applied to one of those paths and
     * not the other would leave this green and the other red.
     */
    public function testAMalformedMessageIsAlsoMadeValid(): void
    {
        SecurityLog::event('Refused ' . self::MALFORMED, ['page' => 'user.php']);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertTrue(mb_check_encoding($record['params']['message'], 'UTF-8'));
        $this->assertStringContainsString('page=user.php', $record['params']['message']);
    }

    /**
     * The readable part survives, everywhere.
     *
     * This is the half that does not depend on how the installation is built,
     * so it is asserted without a guard: whatever happens to the unreadable
     * bytes, a log line that threw away `probe` as well would be no use to the
     * operator reading it.
     */
    public function testTheReadablePartOfAMangledValueIsKept(): void
    {
        $this->captureErrorLog();
        SecurityLog::event('Refused a state change', ['login' => self::MALFORMED]);

        $this->assertStringContainsString('probe', $this->errorLog());
    }

    /**
     * And with the mbstring extension, the damage is marked rather than hidden.
     *
     * A silent deletion makes `probe` out of a value that was not `probe`,
     * which is a worse answer than saying so: an operator reading this line is
     * trying to work out what somebody sent.
     *
     * Guarded, because it is not true everywhere and saying so is the point.
     * Without the extension, symfony/polyfill-mbstring provides these functions
     * and its mb_substitute_character() returns false for a codepoint instead
     * of setting one, so the bytes are dropped. Measured against the polyfill
     * directly. Asserting U+FFFD unconditionally would have made this test a
     * claim about the reviewer's machine rather than about the code.
     * Reported by Copilot.
     */
    public function testTheUnreadableBytesAreMarkedWhereMbstringCanMarkThem(): void
    {
        if (!extension_loaded('mbstring')) {
            $this->markTestSkipped('the polyfill cannot set a substitute character, so it drops the bytes instead');
        }

        $this->captureErrorLog();
        SecurityLog::event('Refused a state change', ['login' => self::MALFORMED]);

        $this->assertStringContainsString('login=' . "\u{FFFD}" . '(probe' . "\u{FFFD}", $this->errorLog());
    }

    /**
     * The substitute character is global state, and this class is called from
     * everywhere.
     *
     * Leaving it changed would alter what every later mb_convert_encoding() in
     * the request produces -- from a logger, which is the last place anyone
     * would look for it.
     *
     * A sentinel is set first, rather than reading whatever happens to be there,
     * which is how the first version of this test was written and why it passed
     * against a logger that never restored anything: an earlier test in the
     * same process had already left the value at U+FFFD, so "unchanged" was
     * true of the damage as well as of the fix. The sentinel is a character no
     * code here would choose on its own, and in particular is not U+FFFD.
     *
     * What is compared is the value that *took*, read back, not the one asked
     * for. That is what keeps this portable: without the mbstring extension the
     * polyfill refuses a codepoint and leaves the setting at 'none', so
     * asserting the sentinel came back would be asserting something about the
     * machine rather than about the logger. There the assertion is vacuous,
     * which is honest -- with no settable substitute character there is nothing
     * for the logger to leak.
     */
    public function testTheGlobalSubstituteCharacterIsLeftAsItWasFound(): void
    {
        mb_substitute_character(0x2620); // SKULL AND CROSSBONES
        $before = mb_substitute_character();

        SecurityLog::event('Refused a state change', ['login' => self::MALFORMED]);

        $this->assertSame(
            $before,
            mb_substitute_character(),
            'the logger left the process-wide substitute character where it put it'
        );
    }

    /**
     * A value that is already valid is not touched, including non-ASCII.
     *
     * Without this, "the output is valid UTF-8" would hold just as well for a
     * method that replaced every multi-byte character it did not recognise --
     * and most of this game's players do not have ASCII names.
     */
    public function testValidMultiByteValuesPassThroughUnchanged(): void
    {
        $name = 'Ünïcødé 勇者 — ok';

        SecurityLog::event('Refused a state change', ['login' => $name]);

        $record = Database::getDoctrineConnection()->executeStatements[0] ?? null;

        $this->assertNotNull($record);
        $this->assertStringContainsString('login=' . $name, $record['params']['message']);
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
