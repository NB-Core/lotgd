<?php

declare(strict_types=1);

namespace Lotgd\Tests\Realtime;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A page for a realtime player says where to poll, from which number, and
 * which region versions it shows -- and the number is read before the
 * account the regions show.
 */
final class RealtimePageTest extends TestCase
{
    private const CURSOR = '/SELECT COALESCE\(MAX\(seq\), 0\) FROM event_deliveries/';
    private const ACCOUNT = '/SELECT \* FROM accounts WHERE acctid = :acctid/';

    public function testARealtimePageCarriesThePollSettingsTheCursorAndTheRegionVersions(): void
    {
        $run = $this->page(['realtime_enabled' => 1]);

        self::assertStringContainsString('<meta name="lotgd-realtime" content="10">', $run->html);
        self::assertStringContainsString('<meta name="lotgd-event-cursor" content="17">', $run->html);
        self::assertMatchesRegularExpression('/<meta name="lotgd-region-versions" content="\{&quot;mail&quot;:\d+,&quot;stats&quot;:\d+,&quot;grants&quot;:\d+\}">/', $run->html);

        $cursor = array_keys(preg_grep(self::CURSOR, $run->queries));
        $account = array_keys(preg_grep(self::ACCOUNT, $run->queries));
        self::assertNotSame([], $cursor);
        self::assertLessThan($account[0], $cursor[0], 'The cursor is read before the account the regions show.');
    }

    public function testWithServerSentEventsThePageNamesTheStream(): void
    {
        $polled = $this->page(['realtime_enabled' => 1]);
        $streamed = $this->page(['realtime_enabled' => 1, 'realtime_transport' => 'sse']);

        self::assertStringNotContainsString('lotgd-realtime-stream', $polled->html);
        self::assertStringContainsString('<meta name="lotgd-realtime-stream" content="/async/stream.php">', $streamed->html);
        // Polling stays the fallback: the page carries its settings too.
        self::assertStringContainsString('<meta name="lotgd-realtime" content="10">', $streamed->html);
    }

    public function testWithoutTheSettingThePageIsAsBefore(): void
    {
        $run = $this->page(['realtime_enabled' => 0]);

        self::assertStringNotContainsString('lotgd-realtime', $run->html);
        self::assertSame([], preg_grep('/event_deliveries/', $run->queries));
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function page(array $settings): PageOutcome
    {
        return PageRunner::withRequest(
            'forest.php',
            0,
            'GET',
            [],
            [],
            ['accounts_table' => [1 => [
                'alive' => 1, 'location' => 'Degolburg', 'level' => 1, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
                'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
            ]]],
            $settings + ['partial_render' => 1, 'defaultskin' => 'twig:aurora'],
            [],
            [],
            [self::CURSOR => [['m' => 17]]]
        );
    }
}
