<?php

declare(strict_types=1);

namespace Lotgd\Realtime;

use Doctrine\DBAL\ParameterType;
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
     * @param array<string,mixed> $query  The request's query parameters.
     * @param array<string,mixed> $server The request's server variables.
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function handle(array $query, array $server, float $now): array
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
            // Fetched right after an announcement, one per region it names;
            // the poll's rate limit would turn these away.
            return is_string($region) && in_array($region, self::REGIONS, true)
                ? self::answer(200, self::region($region, $acctid))
                : self::answer(400, ['error' => 'region']);
        }

        $threshold = (float) ($GLOBALS['ajax_rate_limit_seconds'] ?? 1.0);
        if (isset($session['lastpoll']) && ($now - (float) $session['lastpoll']) < $threshold) {
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

        $html = $region === 'mail'
            ? (!empty($session['user']['prefs']['ajax']) ? "<div id='maillink'>" . PageParts::mailLink() . '</div>' : PageParts::mailLink())
            : PageParts::charStats();

        return ['region' => $region, 'version' => self::regionVersion($region, $acctid), 'html' => $html];
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
