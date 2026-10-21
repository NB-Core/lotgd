<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\MySQL\StorageEngine;
use Lotgd\Page\PartialMode;
use Lotgd\Realtime\Relay;

/**
 * The realtime outbox (docs/ReactiveCore.md, Phase 4).
 *
 * An event announces that a region of a player's page has new content -- a
 * new mail, money arriving -- and carries the version of that region, never
 * the content: the client fetches the region through an authorised request
 * and keeps it only if the version is higher than the one it shows. That way
 * an old event relayed late cannot overwrite a newer page.
 *
 * Events are written in the same transaction as the change they announce:
 * publish() inside Action::run(), notify() for a change that is a single
 * autocommit statement of its own. The relay hands them their delivery
 * number after the commit.
 */
final class Realtime
{
    /** Tables the outbox writes. */
    public const TABLES = ['events', 'event_deliveries', 'relay_sequence', 'mail'];

    private static ?bool $transactional = null;

    /**
     * Whether the game runs the realtime outbox at all (setting realtime_enabled).
     */
    public static function enabled(): bool
    {
        if (!Settings::hasInstance() || (int) Settings::getInstance()->getSetting('realtime_enabled', 0) !== 1) {
            return false;
        }

        // The relay's numbering rests on a row lock held until commit, and an
        // event must roll back with its change: MyISAM provides neither.
        return self::$transactional ??= StorageEngine::nonTransactional(self::TABLES) === [];
    }

    /**
     * For tests.
     */
    public static function resetState(): void
    {
        self::$transactional = null;
    }

    /**
     * Whether this player's pages poll: the outbox is on and the partial
     * client runs on their pages, which is what swaps the announced regions.
     *
     * @param array<string,mixed> $user The session user, with prefs unserialized.
     */
    public static function enabledFor(array $user): bool
    {
        return self::enabled() && PartialMode::clientActive($user);
    }

    public static function userChannel(int $acctid): string
    {
        return 'user-' . $acctid;
    }

    /**
     * The channel of a commentary section. Who may read it is decided by
     * Commentary::mayRead(), never by the request.
     */
    public static function sectionChannel(string $section): string
    {
        return 'section-' . $section;
    }

    /**
     * Announce a change made by the running guarded action, in its transaction.
     *
     * @param array{region:string,version:int} $payload
     */
    public static function publish(string $channel, string $type, array $payload): void
    {
        if (!Action::isRunning()) {
            throw new \LogicException('Realtime::publish() belongs inside Action::run(); use Realtime::notify() for a change of its own.');
        }

        self::insert($channel, $type, $payload);
        // Dropped with the action if it rolls back. A second event of the
        // same action queues a second run, which finds nothing left.
        Action::afterCommit(static fn (): int => Relay::run());
    }

    /**
     * Announce a change that is not part of a guarded action.
     *
     * Inside an action it is published with it. Outside one it is written on
     * its own and relayed at once -- unless somebody else's transaction is
     * open, which this insert would silently become part of.
     *
     * @param array{region:string,version:int} $payload
     */
    public static function notify(string $channel, string $type, array $payload): void
    {
        if (!self::enabled()) {
            return;
        }
        if (Action::isRunning()) {
            self::publish($channel, $type, $payload);

            return;
        }
        if (Database::getDoctrineConnection()->isTransactionActive()) {
            throw new \LogicException('Realtime::notify() would join a transaction it does not own.');
        }

        self::insert($channel, $type, $payload);
        Relay::run();
    }

    /**
     * Announce a change together with whatever transaction holds it: the
     * running action's, another open transaction's (the event commits or
     * rolls back with the change; the next relay or cron.php hands it out),
     * or none, then on its own like notify().
     *
     * @param array{region:string,version:int} $payload
     */
    public static function announce(string $channel, string $type, array $payload): void
    {
        if (!self::enabled()) {
            return;
        }
        if (Action::isRunning()) {
            self::publish($channel, $type, $payload);

            return;
        }
        if (Database::getDoctrineConnection()->isTransactionActive()) {
            self::insert($channel, $type, $payload);

            return;
        }

        self::insert($channel, $type, $payload);
        Relay::run();
    }

    /**
     * @param array{region:string,version:int} $payload
     */
    private static function insert(string $channel, string $type, array $payload): void
    {
        Database::getDoctrineConnection()->executeStatement(
            'INSERT INTO ' . Database::prefix('events')
            . ' (event_id, channel, type, payload, created) VALUES (:event_id, :channel, :type, :payload, :created)',
            [
                'event_id' => bin2hex(random_bytes(16)),
                'channel' => $channel,
                'type' => $type,
                'payload' => (string) json_encode($payload),
                'created' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
