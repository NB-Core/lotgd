<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\Modules\HookHandler;
use Lotgd\MySQL\Database;

/**
 * Who is online, and where (docs/ReactiveCore.md, Phase 7).
 *
 * The page footer notes the place of every page a player sees: a coarse
 * one (village, forest, inn), never the page itself. The row is written
 * when the place changes or was last written a minute ago, and a change of
 * place is announced on the channel `presence`, which every realtime page
 * reads; a page that shows the list (the presence region) fetches it again.
 * A player who times out without a request is simply left out of the next
 * list.
 */
final class Presence
{
    public const CHANNEL = 'presence';
    /** Seconds between two writes of the same place. */
    public const REFRESH_SECONDS = 60;

    /** Places a player can be found in, by the script they are on (without .php). */
    public const PLACES = [
        'village' => 'village',
        'inn' => 'inn',
        'gardens' => 'gardens',
        'rock' => 'village',
        'bank' => 'village',
        'weapons' => 'village',
        'armor' => 'village',
        'stables' => 'village',
        'gypsy' => 'village',
        'lodge' => 'village',
        'train' => 'village',
        'mercenarycamp' => 'village',
        'healer' => 'forest',
        'forest' => 'forest',
        'pvp' => 'forest',
        'clan' => 'clan',
        'shades' => 'shades',
        'graveyard' => 'shades',
        'encounter' => 'arena',
    ];

    /**
     * Where a player may be challenged to a duel: places without a fight or
     * a page of their own in progress.
     */
    public const PEACEFUL = ['village', 'inn', 'gardens', 'arena'];

    public static function enabled(): bool
    {
        return Realtime::enabled();
    }

    /**
     * The place of a script, as modules may change it (hook presence-place).
     */
    public static function placeOf(string $script): string
    {
        $args = HookHandler::hook('presence-place', ['script' => $script, 'place' => self::PLACES[$script] ?? 'elsewhere']);
        $place = (string) ($args['place'] ?? 'elsewhere');

        return substr($place !== '' ? $place : 'elsewhere', 0, 32);
    }

    /**
     * Note where the player is: called by the page footer.
     *
     * @param array<string,mixed> $user The session user.
     */
    public static function note(array $user, string $script): void
    {
        $acctid = (int) ($user['acctid'] ?? 0);
        if ($acctid <= 0 || empty($user['loggedin']) || !self::enabled()) {
            return;
        }

        $place = self::placeOf($script);
        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('presence');
        $row = $conn->fetchAssociative(
            "SELECT place, seen FROM {$table} WHERE acctid = :acctid",
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );
        $now = time();
        $seen = is_array($row) ? (int) strtotime((string) $row['seen']) : 0;
        $moved = !is_array($row) || (string) $row['place'] !== $place || $seen < $now - self::timeout();
        if (!$moved && $seen > $now - self::REFRESH_SECONDS) {
            return;
        }

        $changed = $moved ? self::now() : null;
        $conn->executeStatement(
            "INSERT INTO {$table} (acctid, place, seen, changed_us) VALUES (:acctid, :place, :seen, :changed)"
            . ' ON DUPLICATE KEY UPDATE place = VALUES(place), seen = VALUES(seen)'
            . ($changed !== null ? ', changed_us = VALUES(changed_us)' : ''),
            ['acctid' => $acctid, 'place' => $place, 'seen' => date('Y-m-d H:i:s', $now), 'changed' => $changed ?? 0],
            ['acctid' => ParameterType::INTEGER, 'changed' => ParameterType::INTEGER]
        );
        if ($changed !== null) {
            Realtime::announce(self::CHANNEL, 'presence', ['region' => 'presence', 'version' => $changed]);
        }
    }

    /**
     * The player left: logged out. The row stays, marked gone, so the
     * region's version moves on (a deleted row would take it back).
     */
    public static function leave(int $acctid): void
    {
        if ($acctid <= 0 || !self::enabled()) {
            return;
        }
        $changed = self::now();
        $left = Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('presence') . " SET seen = '1970-01-01 00:00:00', changed_us = :changed WHERE acctid = :acctid",
            ['acctid' => $acctid, 'changed' => $changed],
            ['acctid' => ParameterType::INTEGER, 'changed' => ParameterType::INTEGER]
        );
        if ($left > 0) {
            Realtime::announce(self::CHANNEL, 'presence', ['region' => 'presence', 'version' => $changed]);
        }
    }

    /**
     * The players online now, by name: acctid, name, level, place.
     *
     * @return list<array<string,mixed>>
     */
    public static function online(): array
    {
        $since = date('Y-m-d H:i:s', time() - self::timeout());

        return Database::getDoctrineConnection()->fetchAllAssociative(
            'SELECT p.acctid, a.name, a.level, p.place FROM ' . Database::prefix('presence') . ' p'
            . ' INNER JOIN ' . Database::prefix('accounts') . ' a ON a.acctid = p.acctid'
            . ' WHERE p.seen > :since AND a.loggedin = 1 AND a.laston > :since AND a.locked = 0'
            . ' ORDER BY a.level DESC, a.name',
            ['since' => $since]
        );
    }

    /**
     * Where a player online is now, or null when they are not.
     */
    public static function placeOfPlayer(int $acctid): ?string
    {
        $since = date('Y-m-d H:i:s', time() - self::timeout());
        $place = Database::getDoctrineConnection()->fetchOne(
            'SELECT p.place FROM ' . Database::prefix('presence') . ' p'
            . ' INNER JOIN ' . Database::prefix('accounts') . ' a ON a.acctid = p.acctid'
            . ' WHERE p.acctid = :acctid AND p.seen > :since AND a.loggedin = 1 AND a.laston > :since',
            ['acctid' => $acctid, 'since' => $since],
            ['acctid' => ParameterType::INTEGER]
        );

        return is_string($place) ? $place : null;
    }

    /**
     * The version of the presence region: the last change of anyone's place.
     */
    public static function regionVersion(): int
    {
        return (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT COALESCE(MAX(changed_us), 0) FROM ' . Database::prefix('presence')
        );
    }

    /**
     * A place as players read it.
     */
    public static function placeName(string $place): string
    {
        return Translator::translateInline(match ($place) {
            'village' => 'in the village',
            'inn' => 'at the inn',
            'gardens' => 'in the gardens',
            'forest' => 'in the forest',
            'clan' => 'in the clan halls',
            'shades' => 'among the shades',
            'arena' => 'at the duelling ground',
            default => 'somewhere else',
        }, 'presence');
    }

    /**
     * Microseconds since the epoch: a region version that only grows.
     */
    public static function now(): int
    {
        return (int) floor(microtime(true) * 1000000);
    }

    private static function timeout(): int
    {
        return Settings::hasInstance() ? (int) Settings::getInstance()->getSetting('LOGINTIMEOUT', 900) : 900;
    }
}
