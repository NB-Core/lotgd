<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Forms;
use Lotgd\GameLog;
use Lotgd\Security\Csrf;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * The guard records its own refusals.
 *
 * Thirty-six callers used to write that line themselves: twenty-three through
 * `debuglog()`, which is a character's audit trail of gold and experience
 * rather than a record of what the server refused, one through
 * `DebugLog::add()`, and twelve through `SecurityLog::event()` with a wording
 * of its own each time. Twelve of them said "Rejected a state change"
 * wortgleich, and what actually distinguishes one refusal from another -- the
 * page, the operation, the scope -- appeared in none of them.
 *
 * Driven in-process rather than through the page harness, for one reason: the
 * harness reports the SQL a page issued but not the values bound to it, and
 * every interesting thing here is a bound value. The stub connection keeps
 * both, so these assertions can be about what the log actually says.
 * tests/Security/CsrfRefusalPageNoiseTest.php covers the other half -- what a
 * real page does -- where the count alone is the answer.
 */
final class CsrfRefusalLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();

        $GLOBALS['session'] = ['user' => ['acctid' => 42]];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = '/bans.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
        $_POST = [];
    }

    /**
     * The security rows written so far, newest last.
     *
     * Filtered by category rather than by table: every request writes to
     * `gamelog` for reasons of its own, and a row watching the table would
     * answer yes whatever happened.
     *
     * @return list<array<string,mixed>>
     */
    private static function securityRows(): array
    {
        $rows = [];
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            if (!is_array($statement) || !str_contains((string) ($statement['sql'] ?? ''), 'INSERT INTO gamelog')) {
                continue;
            }
            if (($statement['params']['category'] ?? '') !== GameLog::CATEGORY_SECURITY) {
                continue;
            }

            $rows[] = $statement['params'];
        }

        return $rows;
    }

    /**
     * A token this page's own scope will accept.
     */
    private static function validToken(): string
    {
        return Csrf::token(Forms::csrfScope());
    }

    public function testARefusedCoreOperationIsRecordedOnceAndSaysWhatItWas(): void
    {
        self::assertTrue(Forms::isUnverifiedCoreOp('delban', ['delban', 'saveban']));

        $rows = self::securityRows();
        self::assertCount(1, $rows, 'one refusal, one row -- not none and not two');

        $message = (string) $rows[0]['message'];
        self::assertStringContainsString('Refused a state change with an invalid CSRF token', $message);
        self::assertStringContainsString('page=bans.php', $message);
        self::assertStringContainsString('op=delban', $message);
        self::assertStringContainsString('scope=form:bans.php', $message);
        self::assertStringContainsString('method=POST', $message);
        self::assertStringContainsString('diag=', $message, 'so the row and the error log line can be tied together');
        self::assertSame('warning', $rows[0]['severity']);
        self::assertSame(42, $rows[0]['who'], 'attributed to whoever was refused');
    }

    /**
     * The control the rest of this file rests on.
     *
     * Without it every assertion here would hold just as well for a guard that
     * logs on every request it is asked about, which is the defect that made
     * five call sites need rearranging before this change was safe.
     */
    public function testAVerifiedRequestIsNotRecordedAtAll(): void
    {
        $_POST[Csrf::FORM_FIELD] = self::validToken();

        self::assertFalse(Forms::isUnverifiedCoreOp('delban', ['delban', 'saveban']));
        self::assertSame([], self::securityRows());
    }

    /**
     * An operation the page does not own belongs to a module, so it is not
     * asked about -- and an unasked question leaves no trace.
     */
    public function testAnOperationThePageDoesNotOwnIsNotRecorded(): void
    {
        self::assertFalse(Forms::isUnverifiedCoreOp('mymoduleop', ['delban', 'saveban']));
        self::assertSame([], self::securityRows());
    }

    /**
     * A refusal on a page that runs arbitrary SQL is not the same event as a
     * refusal on a taunt editor, and gamelog.php filters on severity.
     */
    public function testTheCallerCanRaiseTheSeverity(): void
    {
        self::assertTrue(Forms::isUnverifiedRequest(Csrf::SCOPE_RAW_SQL, [], GameLog::SEVERITY_ERROR));

        $rows = self::securityRows();
        self::assertCount(1, $rows);
        self::assertSame('error', $rows[0]['severity']);
        self::assertStringContainsString('scope=raw_sql', (string) $rows[0]['message']);
    }

    /**
     * Which record was about to go is the one thing the guard cannot work out
     * for itself, so a caller that knows it can say so.
     */
    public function testTheCallerCanAddContextTheGuardCannotKnow(): void
    {
        self::assertTrue(Forms::isUnverifiedRequest(Csrf::SCOPE_USER_EDITOR, ['target' => 1234]));

        $message = (string) (self::securityRows()[0]['message'] ?? '');
        self::assertStringContainsString('target=1234', $message);
        self::assertStringContainsString('scope=user_editor', $message, 'and the guard still says the rest');
    }

    /**
     * On the isUnverifiedRequest() path the operation is read from the query
     * string, which makes it request data.
     *
     * SecurityLog::event() strips control characters out of what it is handed;
     * it does not bound length, and nothing else between a caller and the log
     * does either. An operation name is a handful of characters on every page
     * in this tree, so a long one is not a caller being unusual.
     */
    public function testAnAbsurdlyLongOperationDoesNotReachTheLogWhole(): void
    {
        $_GET['op'] = str_repeat('a', 5000);

        self::assertTrue(Forms::isUnverifiedRequest());

        $message = (string) (self::securityRows()[0]['message'] ?? '');
        self::assertStringContainsString('op=' . str_repeat('a', 64) . ' ', $message);
        self::assertStringNotContainsString(str_repeat('a', 65), $message);

        $_GET = [];
    }
}
