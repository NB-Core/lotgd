<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Commentary;
use Lotgd\Forms;
use Lotgd\GameLog;
use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The two writes addCommentary() makes on a request: removing a line and
 * posting one.
 *
 * Removal used to run on `?removecomment=N` alone. The [Del] link was shown
 * only to comment moderators (and to game masters on their own lines), but the
 * handler checked neither, and a link is something another site can make the
 * browser follow. It now needs the page's form token in a POST and the same
 * rights the link is shown for. The talk form already carried that token; the
 * comment it posts is now refused without it.
 *
 * @group commentary
 */
final class CommentaryWriteGuardTest extends TestCase
{
    private const SCRIPT = '/village.php';

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();
        Settings::setInstance(new DummySettings(['usedatacache' => 0]));

        $GLOBALS['session'] = ['loggedin' => true, 'user' => ['acctid' => 1, 'superuser' => 0, 'specialinc' => '']];
        $_GET = [];
        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = self::SCRIPT;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::resetDoctrineConnection();
        Settings::setInstance(null);
        unset($GLOBALS['session']);
        $_GET = [];
        $_POST = [];
    }

    /**
     * Ask for line 5 to be removed, posted from village.php.
     */
    private static function requestRemoval(bool $withToken, string $method = 'POST'): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = ['removecomment' => '5', 'section' => 'village', 'returnpath' => '/village.php'];
        if ($withToken) {
            $_POST[Csrf::FORM_FIELD] = Csrf::token(Forms::csrfScope());
        }
    }

    /**
     * The comment row handleRemoval() reads before it deletes.
     */
    private static function queueCommentBy(int $author): void
    {
        Database::getDoctrineConnection()->fetchAssociativeResults[] = [
            'commentid' => 5,
            'section'   => 'village',
            'author'    => $author,
            'comment'   => 'hello',
            'name'      => 'Someone',
            'acctid'    => $author,
            'clanrank'  => 0,
            'clanshort' => '',
        ];
    }

    private static function deletedComment(): bool
    {
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            if (preg_match('/DELETE FROM\s+commentary\b/i', (string) ($statement['sql'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function securityMessages(): array
    {
        $messages = [];
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            if (!str_contains((string) ($statement['sql'] ?? ''), 'INSERT INTO gamelog')) {
                continue;
            }
            if (($statement['params']['category'] ?? '') !== GameLog::CATEGORY_SECURITY) {
                continue;
            }
            $messages[] = (string) $statement['params']['message'];
        }

        return $messages;
    }

    /**
     * Redirect::redirect() ends the request, which would end the test run.
     *
     * Only possible where the real class has not been loaded yet, which is
     * why the tests that reach it run in a process of their own.
     */
    private static function stubRedirect(): void
    {
        if (!class_exists('Lotgd\\Redirect', false)) {
            eval('namespace Lotgd; class Redirect { public static array $to = []; '
                . 'public static function redirect(string $location, string|bool $reason = false): void '
                . '{ self::$to[] = $location; } }');
        }
    }

    public function testALinkRemovesNothing(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        self::requestRemoval(true, 'GET');
        self::queueCommentBy(2);

        Commentary::addCommentary();

        self::assertFalse(self::deletedComment());
        self::assertCount(1, self::securityMessages(), 'the guard records the refusal');
    }

    public function testAPostWithoutTheTokenRemovesNothing(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        self::requestRemoval(false);
        self::queueCommentBy(2);

        Commentary::addCommentary();

        self::assertFalse(self::deletedComment());
    }

    public function testATokenWithoutModeratorRightsRemovesNothing(): void
    {
        self::requestRemoval(true);
        self::queueCommentBy(2);

        Commentary::addCommentary();

        self::assertFalse(self::deletedComment());
        $messages = self::securityMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('Refused to remove a comment', $messages[0]);
    }

    public function testAGameMasterMayNotRemoveSomeoneElsesLine(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_IS_GAMEMASTER;
        self::requestRemoval(true);
        self::queueCommentBy(2);

        Commentary::addCommentary();

        self::assertFalse(self::deletedComment());
    }

    #[RunInSeparateProcess]
    public function testAModeratorRemovesTheLineWithTheToken(): void
    {
        self::stubRedirect();
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        self::requestRemoval(true);
        self::queueCommentBy(2);

        Commentary::addCommentary();

        self::assertTrue(self::deletedComment());
        self::assertSame([], self::securityMessages());
    }

    #[RunInSeparateProcess]
    public function testAGameMasterRemovesTheirOwnLine(): void
    {
        self::stubRedirect();
        $GLOBALS['session']['user']['superuser'] = SU_IS_GAMEMASTER;
        self::requestRemoval(true);
        self::queueCommentBy(1);

        Commentary::addCommentary();

        self::assertTrue(self::deletedComment());
    }

    public function testACommentWithoutTheTokenIsRefused(): void
    {
        $_GET = ['section' => 'village'];
        $_POST = ['insertcommentary' => 'hello', 'section' => 'village', 'talkline' => 'says', 'counter' => '0'];
        $GLOBALS['session']['commentcounter'] = 0;

        Commentary::addCommentary();

        self::assertCount(1, self::securityMessages());
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            self::assertDoesNotMatchRegularExpression('/INSERT INTO\s+commentary\b/i', (string) $statement['sql']);
        }
    }

    public function testACommentWithTheTokenIsNotRefused(): void
    {
        $_GET = ['section' => 'village'];
        $_POST = ['insertcommentary' => '', 'section' => 'village', 'talkline' => 'says', 'counter' => '0'];
        $_POST[Csrf::FORM_FIELD] = Csrf::token(Forms::csrfScope());

        Commentary::addCommentary();

        self::assertSame([], self::securityMessages());
    }

    /**
     * motd.php serves anonymous visitors without forced navigation. A refusal
     * row per unauthenticated request would let anyone grow the game log.
     */
    public function testAnAnonymousCallerWritesNothing(): void
    {
        $GLOBALS['session'] = ['user' => []];
        self::requestRemoval(false, 'GET');
        self::queueCommentBy(2);

        Commentary::addCommentary();

        $_GET = ['section' => 'motd'];
        $_POST = ['insertcommentary' => 'hello', 'section' => 'motd', 'talkline' => 'says', 'counter' => '0'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        Commentary::addCommentary();

        self::assertFalse(self::deletedComment());
        self::assertSame([], Database::getDoctrineConnection()->executeStatements, 'no refusal row, no write at all');
    }

    public function testAPageViewIsNotAskedAbout(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        Commentary::addCommentary();

        self::assertSame([], self::securityMessages(), 'nothing was posted, so nothing was refused');
    }

    public function testDelIsAPostedButtonAndNotALink(): void
    {
        $button = Commentary::removeButton('village.php', 5, 'village');

        self::assertStringContainsString("method='POST'", $button);
        self::assertStringContainsString(Csrf::FORM_FIELD, $button);
        self::assertStringContainsString('removecomment=5', $button);
        self::assertStringNotContainsString('<a ', $button);

        foreach (['src/Lotgd/Commentary.php', 'src/Lotgd/Moderate.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertDoesNotMatchRegularExpression("/<a href='\" \\. \\\$return .*removecomment=/", $source, $file);
        }
    }
}
