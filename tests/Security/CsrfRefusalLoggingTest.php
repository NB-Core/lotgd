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
        $_GET = [];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = '/bans.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
        $_GET = [];
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
     * The operation is read the way the pages that post it read it.
     *
     * The companion, armour and weapon editors submit `op` as a hidden field to
     * the bare page URL -- `companions.php:44` is
     * `Http::postIsset('op') ? Http::post('op') : Http::get('op')` -- so a
     * guard that consulted only the query string filed an entry naming no
     * operation at all, where the per-page line it replaced named one. A
     * smaller log would have been a fair trade; a less answerable one is not.
     * Reported by Codex.
     */
    public function testTheOperationIsTakenFromTheBodyWhenThatIsWhereItIs(): void
    {
        $_POST['op'] = 'del';

        self::assertTrue(Forms::isUnverifiedRequest(Csrf::SCOPE_COMPANION_EDITOR));

        self::assertStringContainsString('op=del', (string) (self::securityRows()[0]['message'] ?? ''));
    }

    /**
     * A caller that knows the operation outranks the guard's reading of the
     * request.
     *
     * The context used to be merged the other way round, and `+` keeps the
     * left-hand value for a key both sides carry -- so a caller passing `op`
     * was silently ignored in favour of whatever the request happened to say.
     * That is the wrong way round for every key the guard guesses at, and this
     * asserts the fix on the one where a caller and the request can actually
     * disagree.
     */
    public function testWhatTheCallerSaysWinsOverWhatTheRequestSays(): void
    {
        $_GET['op'] = 'from-the-request';

        self::assertTrue(Forms::isUnverifiedRequest(null, ['op' => 'from-the-caller']));

        $message = (string) (self::securityRows()[0]['message'] ?? '');
        self::assertStringContainsString('op=from-the-caller', $message);
        self::assertStringNotContainsString('from-the-request', $message);
    }

    /**
     * The bound is on characters, not bytes, because the game log is utf8mb4.
     *
     * substr() cutting a multi-byte character in half produces bytes MySQL
     * rejects, and SecurityLog::sanitize() does not repair them -- it detects
     * invalid UTF-8 and falls back to a byte-wise strip that leaves it invalid.
     * The insert then fails and the refusal is recorded nowhere at all, which
     * is a worse outcome than a long log line. Reported by Codex.
     */
    public function testAMultiByteOperationIsCutOnCharacterBoundaries(): void
    {
        // Three bytes each, so byte 64 falls inside the 22nd character -- and
        // eighty of them, so the bound really does cut.
        $operation = str_repeat('€', 80);
        $_GET['op'] = $operation;

        // The control: this is what the byte-wise cut produced, and it is the
        // thing MySQL refuses.
        self::assertFalse(
            mb_check_encoding(substr($operation, 0, 64), 'UTF-8'),
            'precondition: a byte-wise cut of this value is invalid UTF-8'
        );

        self::assertTrue(Forms::isUnverifiedRequest());

        $message = (string) (self::securityRows()[0]['message'] ?? '');
        self::assertTrue(mb_check_encoding($message, 'UTF-8'), 'the log line must be insertable');
        self::assertStringContainsString('op=' . str_repeat('€', 64) . ' ', $message);
        self::assertStringNotContainsString(str_repeat('€', 65), $message);
    }

    /**
     * And a value that was never valid UTF-8 is described rather than carried.
     */
    public function testAnOperationThatIsNotUtf8AtAllIsNotPassedThrough(): void
    {
        $_GET['op'] = "\xC3\x28";

        self::assertTrue(Forms::isUnverifiedRequest());

        $message = (string) (self::securityRows()[0]['message'] ?? '');
        self::assertTrue(mb_check_encoding($message, 'UTF-8'), 'the log line must be insertable');
        self::assertStringContainsString('op=(not valid UTF-8)', $message);
    }

    /**
     * On the isUnverifiedRequest() path the operation is read from the request,
     * which makes it request data.
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
    }
}
