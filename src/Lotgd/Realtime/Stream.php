<?php

declare(strict_types=1);

namespace Lotgd\Realtime;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Realtime;
use Lotgd\Settings;

/**
 * The realtime outbox as server-sent events (async/stream.php,
 * docs/ReactiveCore.md Phase 7): the same announcements the poll answers,
 * pushed as soon as the relay numbers them.
 *
 * Each message is one poll answer (`{cursor, events, resync?}`) with the
 * cursor as its id, so a browser that reconnects sends it back as
 * Last-Event-ID and misses nothing. Every few seconds the stream reads the
 * account again -- a logout or ban ends it, a clan it left drops out of its
 * channels. Every second it checks that it is still the player's one
 * stream: a newer one (another tab, a reload) takes over, and this one ends
 * with `replaced`, after which that page polls -- so no player holds more
 * than one PHP worker for longer than a second.
 */
final class Stream
{
    /** Seconds between two looks at the stream token and the relay counter. */
    public const TICK_SECONDS = 1.0;
    /** Seconds between two checks of the account and its channels. */
    public const CHECK_SECONDS = 15.0;
    /** Milliseconds the browser waits before it reconnects. */
    public const RETRY_MS = 2000;

    private int $cursor;
    private string $token;

    /** @var callable(string):void */
    private $emit;
    /** @var callable(float):void */
    private $sleep;
    /** @var callable():float */
    private $clock;
    /** @var callable():bool */
    private $aborted;

    /**
     * @param callable(string):void $emit    Writes and flushes a chunk.
     * @param callable(float):void  $sleep   Waits that many seconds.
     * @param callable():float      $clock   Seconds, monotonic enough.
     * @param callable():bool       $aborted Whether the browser went away.
     */
    public function __construct(
        private readonly int $acctid,
        private readonly string $section,
        int $cursor,
        string $token,
        private readonly float $lifetime,
        callable $emit,
        callable $sleep,
        callable $clock,
        callable $aborted
    ) {
        $this->cursor = max(0, $cursor);
        $this->token = $token;
        $this->emit = $emit;
        $this->sleep = $sleep;
        $this->clock = $clock;
        $this->aborted = $aborted;
    }

    /**
     * Whether pages get their events as a stream (setting realtime_transport).
     */
    public static function enabled(): bool
    {
        return Realtime::enabled()
            && Settings::hasInstance()
            && (string) Settings::getInstance()->getSetting('realtime_transport', 'poll') === 'sse';
    }

    /**
     * Seconds a stream stays open (setting realtime_stream_seconds).
     */
    public static function lifetime(): int
    {
        $seconds = Settings::hasInstance() ? (int) Settings::getInstance()->getSetting('realtime_stream_seconds', 50) : 50;

        return max(20, min(80, $seconds));
    }

    /**
     * Make a new stream the player's one stream; the one before ends on its
     * next check. Returns the new stream's token.
     */
    public static function claim(int $acctid): string
    {
        $token = bin2hex(random_bytes(16));
        Database::getDoctrineConnection()->executeStatement(
            'REPLACE INTO ' . Database::prefix('realtime_streams') . ' (acctid, token, started) VALUES (:acctid, :token, :started)',
            ['acctid' => $acctid, 'token' => $token, 'started' => date('Y-m-d H:i:s')],
            ['acctid' => ParameterType::INTEGER, 'token' => ParameterType::STRING, 'started' => ParameterType::STRING]
        );

        return $token;
    }

    /**
     * Run until the lifetime is over, the browser leaves, or the account or
     * a newer stream ends it. Returns why it ended: lifetime, aborted, end,
     * replaced.
     */
    public function run(): string
    {
        $start = ($this->clock)();
        $nextCheck = $start;
        $channels = [];
        $relayed = -1;

        ($this->emit)('retry: ' . self::RETRY_MS . "\n\n");

        while (true) {
            $now = ($this->clock)();
            if (($this->aborted)()) {
                return 'aborted';
            }

            $ending = $this->stillOurs() ? null : 'replaced';
            if ($ending === null && $now >= $nextCheck) {
                $ending = $this->accountEnds() ? 'end' : null;
            }
            if ($ending !== null) {
                ($this->emit)("event: {$ending}\ndata: {}\n\n");

                return $ending;
            }

            if ($now >= $nextCheck) {
                $channels = $this->channels();
                if ($now > $start) {
                    // A comment line: keeps proxies from timing the stream out,
                    // and finds a browser that left.
                    ($this->emit)(": ping\n\n");
                }
                $nextCheck = $now + self::CHECK_SECONDS;
                // Channels may have changed: read again from the cursor.
                $relayed = -1;
            }

            // One primary-key row tells whether anything was relayed since.
            $latest = $this->relayed();
            if ($latest !== $relayed) {
                $answer = Poll::events($channels, $this->cursor);
                if (($answer['events'] ?? []) !== [] || !empty($answer['resync'])) {
                    ($this->emit)($this->message($answer));
                }
                $this->cursor = (int) $answer['cursor'];
                // A full page leaves more to read: look again at once.
                if (count($answer['events'] ?? []) < Poll::PAGE_SIZE) {
                    $relayed = $latest;
                }
                continue;
            }

            if ($now - $start >= $this->lifetime) {
                return 'lifetime';
            }

            ($this->sleep)(self::TICK_SECONDS);
        }
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Whether no newer stream of the player took over.
     */
    private function stillOurs(): bool
    {
        $token = Database::getDoctrineConnection()->fetchOne(
            'SELECT token FROM ' . Database::prefix('realtime_streams') . ' WHERE acctid = :acctid',
            ['acctid' => $this->acctid],
            ['acctid' => ParameterType::INTEGER]
        );

        return is_string($token) && hash_equals($this->token, $token);
    }

    /**
     * Whether the account ends the stream: logged out, banned (both clear
     * loggedin), timed out, or the stream was turned off.
     */
    private function accountEnds(): bool
    {
        $account = $this->account();
        $timeout = Settings::hasInstance() ? (int) Settings::getInstance()->getSetting('LOGINTIMEOUT', 900) : 900;

        return $account === null
            || empty($account['loggedin'])
            || (int) strtotime((string) $account['laston']) < time() - $timeout
            || !self::enabled();
    }

    /**
     * The channels the player may read now, from the account read now
     * (Poll::channels(), as the poll derives them).
     *
     * @return list<string>
     */
    private function channels(): array
    {
        $account = $this->account();

        return $account === null ? [Realtime::userChannel($this->acctid)] : Poll::channels($this->acctid, $account, $this->section);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function account(): ?array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT loggedin, laston, superuser, clanid, clanrank FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
            ['acctid' => $this->acctid],
            ['acctid' => ParameterType::INTEGER]
        );

        return is_array($row) ? $row : null;
    }

    private function relayed(): int
    {
        return (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT next_seq FROM ' . Database::prefix('relay_sequence') . ' WHERE id = 1'
        );
    }

    /**
     * @param array<string,mixed> $answer
     */
    private function message(array $answer): string
    {
        $data = json_encode($answer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        return 'id: ' . (int) $answer['cursor'] . "\ndata: {$data}\n\n";
    }
}
