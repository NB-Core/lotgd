<?php

declare(strict_types=1);

namespace Lotgd\Realtime;

use Doctrine\DBAL\ParameterType;
use Lotgd\Buffs;
use Lotgd\MySQL\Database;
use Lotgd\PageParts;
use Lotgd\Realtime;

/**
 * What async/poll.php answers (docs/ReactiveCore.md, Phase 4).
 *
 * The channels a player may read are derived here, from the account row the
 * request has just read from the database -- never from the request. The
 * answer carries announcements only; a region's content is fetched with
 * ?region=, through the same checks.
 */
final class Poll
{
    public const REQUEST_HEADER = 'HTTP_X_LOTGD_POLL';
    public const REGIONS = ['mail', 'stats'];
    private const PAGE_SIZE = 100;

    private static ?int $startCursor = null;

    /**
     * @param array<string,mixed> $query     The request's query parameters.
     * @param array<string,mixed> $server    The request's server variables.
     * @param float               $rateLimit Seconds between two polls, and between two fetches of one region
     *                                       (ajax_rate_limit_seconds of the async settings).
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function handle(array $query, array $server, float $now, float $rateLimit = 1.0): array
    {
        global $session;

        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return self::answer(405, ['error' => 'method']);
        }
        // A custom header makes a cross-site request preflighted, and the
        // preflight is never answered: another site cannot read this.
        if (($server[self::REQUEST_HEADER] ?? '') !== '1') {
            return self::answer(400, ['error' => 'header']);
        }

        $user = $session['user'] ?? [];
        $acctid = (int) ($user['acctid'] ?? 0);
        if (empty($session['loggedin']) || $acctid <= 0 || empty($user['loggedin'])) {
            return self::answer(401, ['error' => 'login', 'reload' => 'index.php?op=timeout']);
        }
        if (!Realtime::enabledFor($user)) {
            return self::answer(404, ['error' => 'disabled']);
        }

        $region = $query['region'] ?? null;
        if ($region !== null) {
            if (!is_string($region) || !in_array($region, self::REGIONS, true)) {
                return self::answer(400, ['error' => 'region']);
            }
            // A fetch follows an announcement right after a poll, so each
            // region has an allowance of its own; rendering it is the cost
            // the limit protects.
            $key = 'lastregion_' . $region;
            if (isset($session[$key]) && ($now - (float) $session[$key]) < $rateLimit) {
                return self::answer(429, ['error' => 'rate']);
            }
            $session[$key] = $now;

            return self::answer(200, self::region($region, $acctid));
        }

        if (isset($session['lastpoll']) && ($now - (float) $session['lastpoll']) < $rateLimit) {
            return self::answer(429, ['error' => 'rate']);
        }
        $session['lastpoll'] = $now;

        $cursor = $query['cursor'] ?? '0';

        return self::answer(200, self::events(
            [Realtime::userChannel($acctid)],
            is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : 0
        ));
    }

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
            return $html;
        }

        return (string) preg_replace('/<meta name="lotgd-(?:realtime|event-cursor|region-versions)" content="[^"]*">/', '', $html);
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

        return (int) ($session['user']['version'] ?? 0);
    }

    /**
     * @param list<string> $channels
     * @return array<string,mixed>
     */
    private static function events(array $channels, int $cursor): array
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
    private static function region(string $region, int $acctid): array
    {
        global $session;

        // The version first: content rendered after it is at least that new,
        // so the version never claims a change the html does not show.
        $version = self::regionVersion($region, $acctid);
        if ($region === 'mail') {
            $html = !empty($session['user']['prefs']['ajax']) ? "<div id='maillink'>" . PageParts::mailLink() . '</div>' : PageParts::mailLink();
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

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    private static function answer(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
