<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Async\Handler\Commentary as CommentaryHandler;
use Lotgd\Commentary;
use Lotgd\Realtime;
use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * The chat without leaving the page (docs/ReactiveCore.md, Live commentary):
 * a line is posted and a line removed over the async endpoint.
 *
 * What is pinned is that the async path asks everything the page's form
 * asks, and more: the section must be one a page showed, the talkline is the
 * page's and not the request's, and mute and the daily limit are asked when
 * the line arrives, not only when the form was drawn.
 */
#[PreserveGlobalState(false)]
final class AsyncCommentaryTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'postinglimit' => 1, 'maxchars' => 200, 'maxcolors' => 10]));
        Realtime::resetState();
        Commentary::resetState();

        $GLOBALS['session'] = [
            'loggedin' => true,
            'user' => ['acctid' => 7, 'loggedin' => 1, 'superuser' => 0, 'clanid' => 0, 'clanrank' => 0, 'name' => 'Seven', 'specialinc' => '',
                'laston' => date('Y-m-d H:i:s'), 'prefs' => []],
            'commentary_sections' => ['village'],
            'commentary_talk' => ['village' => ['talkline' => 'says', 'schema' => 'village', 'limit' => 10]],
        ];
        // The latest line of the section, which a post is compared with
        // (double posts), and the censor's word lists.
        $this->connection->queryResultsByPattern = [
            '/^SELECT comment, author FROM commentary/' => [['comment' => 'Earlier', 'author' => 3]],
            '/FROM nastywords/' => [['words' => '']],
        ];
        $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);
        $_SERVER['REQUEST_URI'] = '/village.php';
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session'], $_SERVER['HTTP_X_LOTGD_CSRF']);
    }

    /** @return list<string> */
    private function inserts(): array
    {
        return array_values(preg_grep('/^INSERT INTO commentary/', array_map('strval', $this->connection->queries)));
    }

    public function testALineIsPostedAndComesBackWithTheLinesAfterTheLastShown(): void
    {
        $this->connection->queryResultsByPattern += [
            '/commentid > :lastId/' => [['commentid' => 12, 'section' => 'village', 'author' => 7, 'comment' => 'Hello', 'postdate' => date('Y-m-d H:i:s'),
                'name' => 'Seven', 'acctid' => 7, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '']],
        ];

        $response = (new CommentaryHandler())->post('village', 'Hello', 11);

        self::assertCount(1, $this->inserts());
        self::assertSame('posted', $response->getData()['commentPost']['status']);
        self::assertSame(12, $response->getData()['commentary']['lastId']);
        self::assertGreaterThan(0, $response->getData()['session']['remaining'], 'Writing a line counts as activity.');
        self::assertNotSame([], preg_grep('/SET laston = :laston/', array_column($this->connection->executeStatements, 'sql')));
        $commands = $response->getCommands();
        self::assertSame('append', $commands[0]['cmd']);
        self::assertSame('village-comment', $commands[0]['id']);
        self::assertStringContainsString("data-cid='12'", $commands[0]['value']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function fetchedLine(int $author): array
    {
        return [['commentid' => 12, 'section' => 'village', 'author' => $author, 'comment' => 'Hello', 'postdate' => date('Y-m-d H:i:s'),
            'name' => 'Someone', 'acctid' => $author, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '']];
    }

    private function fetchedHtml(int $superuser, int $author): string
    {
        $GLOBALS['session']['user']['superuser'] = $superuser;
        $GLOBALS['session']['last_comment_request_uri'] = '/village.php';
        $GLOBALS['session']['last_comment_scriptname'] = 'village';
        $this->connection->queryResultsByPattern['/commentid > :lastId/'] = self::fetchedLine($author);

        return (string) ((new CommentaryHandler())->commentaryRefresh('village', 11)->getCommands()[0]['value'] ?? '');
    }

    public function testAFetchedLineCarriesDelForWhomThePageDrawsIt(): void
    {
        self::assertStringContainsString("data-lotgd-comment-remove='12'", $this->fetchedHtml(SU_EDIT_COMMENTS, 3), 'A moderator, any line.');
        self::assertStringNotContainsString('data-lotgd-comment-remove', $this->fetchedHtml(0, 7), 'A player, even their own line.');
        self::assertStringContainsString("data-lotgd-comment-remove='12'", $this->fetchedHtml(SU_IS_GAMEMASTER, 7), 'A game master, their own line.');
        self::assertStringNotContainsString('data-lotgd-comment-remove', $this->fetchedHtml(SU_IS_GAMEMASTER, 3), 'A game master, another\'s line.');
    }

    public function testAnAuthorsNameAndClanAreTextInTheLine(): void
    {
        $GLOBALS['session']['last_comment_request_uri'] = '/village.php';
        $GLOBALS['session']['last_comment_scriptname'] = 'village';
        $this->connection->queryResultsByPattern['/commentid > :lastId/'] = [['commentid' => 12, 'section' => 'village', 'author' => 3,
            'comment' => 'Hello', 'postdate' => date('Y-m-d H:i:s'), 'name' => "`&O'><img src=x onerror=alert(1)>", 'acctid' => 3,
            'superuser' => 0, 'clanrank' => CLAN_MEMBER, 'clanshort' => '<b>X</b>']];

        $html = (string) ((new CommentaryHandler())->commentaryRefresh('village', 11)->getCommands()[0]['value'] ?? '');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>X</b>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html);
        self::assertStringContainsString("<span class='colLtWhite'>O&#039;&gt;", $html, 'The colour codes still work.');
    }

    public function testAPetitionTakesNoLineOverTheEndpoint(): void
    {
        // viewpetition.php records who answered when a line is posted to it.
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_PETITIONS;
        $GLOBALS['session']['commentary_sections'] = ['pet-4'];
        $GLOBALS['session']['commentary_talk'] = ['pet-4' => ['talkline' => 'says', 'schema' => 'petition', 'limit' => 200]];

        $response = (new CommentaryHandler())->post('pet-4', 'Looked into it');

        self::assertSame([], $this->inserts());
        self::assertSame('refused', $response->getData()['commentPost']['status']);
    }

    public function testWithoutTheAsyncTokenNothingIsPosted(): void
    {
        $_SERVER['HTTP_X_LOTGD_CSRF'] = 'not-the-token';

        $response = (new CommentaryHandler())->post('village', 'Hello');

        self::assertSame([], $this->inserts());
        self::assertSame('refused', $response->getData()['commentPost']['status']);
    }

    public function testASectionNoPageShowedTakesNoLine(): void
    {
        $response = (new CommentaryHandler())->post('clan-5', 'Hello');

        self::assertSame([], $this->inserts());
        self::assertSame('refused', $response->getData()['commentPost']['status']);
        self::assertSame([], preg_grep('/FROM commentary/', array_map(static fn (array $q): string => (string) $q['sql'], $this->connection->fetchAllLog)));
    }

    public function testASectionShownWithoutItsTalkFormTakesNoLine(): void
    {
        // Shown view-only, or by another way than viewCommentary's form.
        unset($GLOBALS['session']['commentary_talk']);

        (new CommentaryHandler())->post('village', 'Hello');

        self::assertSame([], $this->inserts());
    }

    public function testAPlayerOutOfPostsForTodayIsRefusedWhenTheLineArrives(): void
    {
        $mine = ['author' => 7];
        $this->connection->queryResultsByPattern += ['/SELECT author FROM commentary/' => array_fill(0, 5, $mine)];

        $response = (new CommentaryHandler())->post('village', 'Hello');

        self::assertSame([], $this->inserts());
        self::assertSame('refused', $response->getData()['commentPost']['status']);
        self::assertStringContainsString('out of posts', $response->getData()['commentPost']['message']);
    }

    public function testAClansChatHasNoDailyLimit(): void
    {
        $GLOBALS['session']['user']['clanid'] = 5;
        $GLOBALS['session']['user']['clanrank'] = CLAN_MEMBER;
        $GLOBALS['session']['commentary_sections'] = ['clan-5'];
        $GLOBALS['session']['commentary_talk'] = ['clan-5' => ['talkline' => 'says', 'schema' => 'clan', 'limit' => 10]];
        $this->connection->queryResultsByPattern += ['/SELECT author FROM commentary/' => array_fill(0, 5, ['author' => 7])];

        (new CommentaryHandler())->post('clan-5', 'Hello');

        self::assertCount(1, $this->inserts());
    }

    public function testAnEmptyLineIsNotPosted(): void
    {
        $response = (new CommentaryHandler())->post('village', '  `4 ');

        self::assertSame([], $this->inserts());
        self::assertSame('empty', $response->getData()['commentPost']['status']);
    }

    public function testTheTalklineIsThePagesNotTheRequests(): void
    {
        $GLOBALS['session']['commentary_talk']['village']['talkline'] = 'whispers';

        (new CommentaryHandler())->post('village', 'Hello');

        $insert = array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $statement): bool => str_starts_with((string) $statement['sql'], 'INSERT INTO commentary')
        ));
        self::assertCount(1, $insert, implode("\n", array_map('strval', $this->connection->queries)));
        self::assertStringContainsString('whispers', (string) $insert[0]['params']['comment']);
    }

    public function testALineLongerThanTheGameAllowsIsCut(): void
    {
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'postinglimit' => 1, 'maxchars' => 20, 'maxcolors' => 10]));

        (new CommentaryHandler())->post('village', str_repeat('a', 50));

        $insert = array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $statement): bool => str_starts_with((string) $statement['sql'], 'INSERT INTO commentary')
        ));
        self::assertCount(1, $insert);
        self::assertLessThanOrEqual(20, strlen((string) $insert[0]['params']['comment']));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function lineToRemove(array $row = []): void
    {
        $this->connection->queryResultsByPattern = [
            '/^SELECT comment, author FROM commentary/' => [['comment' => 'Earlier', 'author' => 3]],
            '/SELECT section FROM commentary/' => [['section' => 'village']],
            '/WHERE commentid = :commentid/' => [$row + ['commentid' => 12, 'section' => 'village', 'author' => 9, 'comment' => 'Rude',
                'name' => 'Nine', 'acctid' => 9, 'clanrank' => 0, 'clanshort' => '']],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function statements(string $prefix): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $statement): bool => str_starts_with(ltrim((string) $statement['sql']), $prefix)
        ));
    }

    public function testAModeratorRemovesALineAndItsReadersAreTold(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        $this->lineToRemove();

        $response = (new CommentaryHandler())->remove(12);

        self::assertTrue($response->getData()['commentRemove']['removed']);
        self::assertCount(1, $this->statements('DELETE FROM commentary'));
        $events = $this->statements('INSERT INTO events');
        self::assertCount(1, $events);
        self::assertSame('section-village', $events[0]['params']['channel']);
        self::assertSame('comment-removed', $events[0]['params']['type']);
        self::assertSame(['region' => 'commentary', 'version' => 12], json_decode((string) $events[0]['params']['payload'], true));
    }

    public function testAPlayerWithoutTheRightsRemovesNothing(): void
    {
        $this->lineToRemove();

        $response = (new CommentaryHandler())->remove(12);

        self::assertFalse($response->getData()['commentRemove']['removed']);
        self::assertSame([], $this->statements('DELETE FROM commentary'));
        self::assertSame([], $this->statements('INSERT INTO events'));
    }

    public function testAGameMasterRemovesOnlyTheirOwnLines(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_IS_GAMEMASTER;
        $this->lineToRemove();
        self::assertFalse((new CommentaryHandler())->remove(12)->getData()['commentRemove']['removed']);

        $this->lineToRemove(['author' => 7, 'acctid' => 7]);
        self::assertTrue((new CommentaryHandler())->remove(12)->getData()['commentRemove']['removed']);
        self::assertCount(1, $this->statements('DELETE FROM commentary'));
    }

    public function testALineOfASectionThePlayerDoesNotReadIsNotRemoved(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        $this->lineToRemove();
        $this->connection->queryResultsByPattern['/SELECT section FROM commentary/'] = [['section' => 'clan-9']];

        self::assertFalse((new CommentaryHandler())->remove(12)->getData()['commentRemove']['removed']);
        self::assertSame([], $this->statements('DELETE FROM commentary'));
    }

    public function testWithoutTheAsyncTokenNothingIsRemoved(): void
    {
        $GLOBALS['session']['user']['superuser'] = SU_EDIT_COMMENTS;
        $this->lineToRemove();
        $_SERVER['HTTP_X_LOTGD_CSRF'] = 'not-the-token';

        self::assertFalse((new CommentaryHandler())->remove(12)->getData()['commentRemove']['removed']);
        self::assertSame([], $this->statements('DELETE FROM commentary'));
    }
}
