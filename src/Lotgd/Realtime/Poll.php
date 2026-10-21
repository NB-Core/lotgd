<?php

declare(strict_types=1);

namespace Lotgd\Realtime;

use Doctrine\DBAL\ParameterType;
use Lotgd\Buffs;
use Lotgd\Grant;
use Lotgd\MySQL\Database;
use Lotgd\PageParts;
use Lotgd\Realtime;

/**
 * What the realtime poll answers (docs/ReactiveCore.md, Phases 4 and 6),
 * through Lotgd\Async\Handler\Realtime and async/process.php.
 *
 * The channels a player may read are derived here, from the account row the
 * request has just read from the database -- never from the request. The
 * answer carries announcements only; a region's content is fetched with
 * region(), through the same checks.
 */
final class Poll
{
    public const REGIONS = ['mail', 'stats', 'grants'];
    public const PAGE_SIZE = 100;

    private static ?int $startCursor = null;

    /**
     * The delivery number a page starts polling from. Read before the page
     * builds the regions it shows, so an event that lands in between comes
     * again rather than not at all.
     */
    public static function cursor(): int
    {
        return (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT COALESCE(MAX(seq), 0) FROM ' . Database::prefix('event_deliveries')
        );
    }

    /**
     * Remember the delivery number before the account is read: the page's
     * regions show that account, so every event after this number may be
     * news to the page.
     */
    public static function noteStartCursor(): void
    {
        self::$startCursor = self::cursor();
    }

    /**
     * The number the page starts polling from: the one noted before the
     * account was read, else the current one.
     */
    public static function startCursor(): int
    {
        return self::$startCursor ?? self::cursor();
    }

    /**
     * For tests.
     */
    public static function resetState(): void
    {
        self::$startCursor = null;
    }

    /**
     * A page stored earlier and shown again (badnav.php): without realtime
     * for the player now, it must not start polling. Its cursor and region
     * versions stay as stored -- they belong to the regions it shows.
     *
     * @param array<string,mixed> $user
     */
    public static function storedPage(string $html, array $user): string
    {
        if (Realtime::enabledFor($user)) {
            // Stored while the stream was on: the page polls now.
            return Stream::enabled() ? $html : (string) preg_replace('/<meta name="lotgd-realtime-stream" content="[^"]*">/', '', $html);
        }

        return (string) preg_replace('/<meta name="lotgd-(?:realtime|realtime-stream|event-cursor|region-versions)" content="[^"]*">/', '', $html);
    }

    /**
     * The version of a region as the page shows it now; announcements below
     * it are already on the page.
     */
    public static function regionVersion(string $region, int $acctid): int
    {
        global $session;

        if ($region === 'mail') {
            return (int) Database::getDoctrineConnection()->fetchOne(
                'SELECT COALESCE(MAX(messageid), 0) FROM ' . Database::prefix('mail') . ' WHERE msgto = :acctid',
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );
        }

        if ($region === 'grants') {
            return Grant::enabled() ? Grant::regionVersion($acctid) : 0;
        }

        return (int) ($session['user']['version'] ?? 0);
    }

    /**
     * @param list<string> $channels
     * @return array<string,mixed>
     */
    public static function events(array $channels, int $cursor): array
    {
        $conn = Database::getDoctrineConnection();
        $deliveries = Database::prefix('event_deliveries');
        $latest = self::cursor();

        if ($cursor > $latest) {
            // A cursor from before the numbers were reset.
            return ['cursor' => $latest, 'events' => [], 'resync' => true];
        }

        $oldest = (int) $conn->fetchOne("SELECT COALESCE(MIN(seq), 0) FROM {$deliveries}");
        if ($oldest > $cursor + 1) {
            // Events after this cursor may have expired: the page reloads its
            // regions instead of trusting announcements it can no longer get.
            return ['cursor' => $latest, 'events' => [], 'resync' => true];
        }

        $rows = $conn->fetchAllAssociative(
            "SELECT seq, event_id, type, payload FROM {$deliveries}"
            . ' WHERE channel IN (:channels) AND seq > :cursor ORDER BY seq LIMIT ' . self::PAGE_SIZE,
            ['channels' => $channels, 'cursor' => $cursor],
            ['channels' => \Doctrine\DBAL\ArrayParameterType::STRING, 'cursor' => ParameterType::INTEGER]
        );

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $events[] = [
                'seq' => (int) $row['seq'],
                'id' => (string) $row['event_id'],
                'type' => (string) $row['type'],
                'region' => is_array($payload) ? (string) ($payload['region'] ?? '') : '',
                'version' => is_array($payload) ? (int) ($payload['version'] ?? 0) : 0,
            ];
        }

        // A full page leaves the cursor at its last event, so the next poll
        // fetches the rest; otherwise everything up to the latest number was
        // read, the other players' events included.
        $next = count($rows) === self::PAGE_SIZE ? $events[count($events) - 1]['seq'] : $latest;

        return ['cursor' => $next, 'events' => $events];
    }

    /**
     * @return array<string,mixed>
     */
    public static function region(string $region, int $acctid): array
    {
        global $session;

        // The version first: content rendered after it is at least that new,
        // so the version never claims a change the html does not show.
        $version = self::regionVersion($region, $acctid);
        if ($region === 'mail') {
            $html = !empty($session['user']['prefs']['ajax']) ? "<div id='maillink'>" . PageParts::mailLink() . '</div>' : PageParts::mailLink();
        } elseif ($region === 'grants') {
            $html = Grant::enabled() ? Grant::regionHtml($acctid) : '';
        } else {
            // As the footer does: buffs whose values are expressions are
            // evaluated for the stats and put back afterwards.
            Buffs::restoreBuffFields();
            Buffs::calculateBuffFields();
            $html = PageParts::charStats();
            Buffs::restoreBuffFields();
        }

        return ['region' => $region, 'version' => $version, 'html' => $html];
    }
}
