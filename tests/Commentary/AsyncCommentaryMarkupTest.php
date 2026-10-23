<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A page's chat as the async client finds it: the talk form says its
 * section, every line its comment id, every [Del] the line it removes. A
 * player without the client gets the chat as it always was.
 */
final class AsyncCommentaryMarkupTest extends TestCase
{
    private static function inn(array $prefs, int $superuser = 0): PageOutcome
    {
        $row = ['commentid' => 41, 'section' => 'inn', 'author' => 2, 'comment' => 'Ale!', 'postdate' => date('Y-m-d H:i:s'),
            'name' => 'Two', 'acctid' => 2, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => ''];

        return PageRunner::withRequest(
            'inn.php',
            $superuser,
            'GET',
            ['op' => 'converse'],
            [],
            ['accounts_table' => [1 => ['prefs' => serialize($prefs), 'alive' => 1, 'location' => 'Degolburg', 'lasthit' => date('Y-m-d H:i:s')]]],
            ['ajax' => 1, 'show_error_details' => 1],
            [],
            [],
            ['/FROM commentary .*WHERE section = :section/s' => [$row]]
        );
    }

    public function testAnAjaxPlayersChatCarriesTheMarkersOfTheAsyncClient(): void
    {
        $run = self::inn(['ajax' => 1], SU_EDIT_COMMENTS);

        self::assertStringContainsString("data-lotgd-commentary-form='inn'", $run->html, substr($run->html, 0, 2000));
        self::assertStringContainsString('data-lotgd-commentary-status', $run->html);
        self::assertMatchesRegularExpression("/<div data-cid='41'>.*Ale!/s", $run->html);
        self::assertStringContainsString("<form data-lotgd-comment-remove='41' ", $run->html);
    }

    public function testAClassicPlayersChatIsAsItWas(): void
    {
        $run = self::inn([], SU_EDIT_COMMENTS);

        self::assertStringContainsString("method='POST' autocomplete='false'>", $run->html, substr($run->html, 0, 2000));
        self::assertStringContainsString('Ale!', $run->html);
        self::assertStringNotContainsString('data-lotgd-commentary-form', $run->html);
        self::assertStringNotContainsString('data-cid', $run->html);
        self::assertStringNotContainsString('data-lotgd-comment-remove', $run->html);
    }
}
