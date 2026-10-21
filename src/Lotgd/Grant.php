<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\Action\ActionAborted;
use Lotgd\MySQL\Database;
use Lotgd\MySQL\StorageEngine;

/**
 * Grants (docs/ReactiveCore.md, Phase 5): a one-time link the server issues
 * to a player for something another player started.
 *
 * A grant is shown to its player, opened by a GET that only displays, and
 * used by the POST that follows -- inside Action::run(), where Grant::claim()
 * closes it together with every other grant of its group. The group is what
 * several players were offered for the same thing (every officer of a clan
 * for one applicant): whoever comes first decides, the others' links close
 * in the same transaction, and a rolled-back action leaves them all open.
 *
 * A grant never replaces the check of the thing itself: the action still
 * verifies, on fresh rows, that what it does is allowed now.
 */
final class Grant
{
    private const TABLE = 'granted_navs';

    private static ?bool $transactional = null;

    /**
     * Grants run when the setting grants_enabled is on, guarded actions run
     * (they consume grants) and the table is transactional.
     */
    public static function enabled(): bool
    {
        if (!Settings::hasInstance() || (int) Settings::getInstance()->getSetting('grants_enabled', 0) !== 1 || !Action::enabled()) {
            return false;
        }

        return self::$transactional ??= StorageEngine::nonTransactional([self::TABLE]) === [];
    }

    /**
     * For tests.
     */
    public static function resetState(): void
    {
        self::$transactional = null;
    }

    /**
     * Issue a grant to $acctid. The view link gets the grant's id appended
     * (&grant=<id>) and is what the player opens.
     *
     * @param array<string,mixed> $target What the grant is about, for the page that uses it.
     * @return int The grant's id.
     */
    public static function issue(
        int $acctid,
        string $action,
        array $target,
        string $targetKey,
        string $text,
        string $viewLink,
        int $ttlSeconds,
        int $grantedBy = 0
    ): int {
        $conn = Database::getDoctrineConnection();
        $now = time();
        $changed = self::now();
        $conn->executeStatement(
            'INSERT INTO ' . Database::prefix(self::TABLE)
            . ' (acctid, granted_by, action, target, target_key, text, link, method, created, expires_at, changed_us)'
            . " VALUES (:acctid, :granted_by, :action, :target, :target_key, :text, '', 'POST', :created, :expires_at, :changed)",
            [
                'acctid' => $acctid,
                'granted_by' => $grantedBy,
                'action' => $action,
                'target' => (string) json_encode($target),
                'target_key' => $targetKey,
                'text' => $text,
                'created' => date('Y-m-d H:i:s', $now),
                'expires_at' => date('Y-m-d H:i:s', $now + max(60, $ttlSeconds)),
                'changed' => $changed,
            ],
            ['acctid' => ParameterType::INTEGER, 'granted_by' => ParameterType::INTEGER, 'changed' => ParameterType::INTEGER]
        );
        $id = (int) $conn->lastInsertId();
        $conn->executeStatement(
            'UPDATE ' . Database::prefix(self::TABLE) . ' SET link = :link WHERE id = :id',
            ['link' => $viewLink . (str_contains($viewLink, '?') ? '&' : '?') . 'grant=' . $id, 'id' => $id],
            ['id' => ParameterType::INTEGER]
        );
        Realtime::announce(Realtime::userChannel($acctid), 'grant', ['region' => 'grants', 'version' => $changed]);

        return $id;
    }

    /**
     * The open grants of a player, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function open(int $acctid): array
    {
        return Database::getDoctrineConnection()->fetchAllAssociative(
            'SELECT id, action, target, text, link, expires_at FROM ' . Database::prefix(self::TABLE)
            . ' WHERE acctid = :acctid AND used_at IS NULL AND expires_at > :now ORDER BY id',
            ['acctid' => $acctid, 'now' => date('Y-m-d H:i:s')],
            ['acctid' => ParameterType::INTEGER]
        );
    }

    /**
     * One open grant of a player, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id, int $acctid): ?array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT id, action, target, target_key, text, link, expires_at FROM ' . Database::prefix(self::TABLE)
            . ' WHERE id = :id AND acctid = :acctid AND used_at IS NULL AND expires_at > :now',
            ['id' => $id, 'acctid' => $acctid, 'now' => date('Y-m-d H:i:s')],
            ['id' => ParameterType::INTEGER, 'acctid' => ParameterType::INTEGER]
        );
        if (!is_array($row)) {
            return null;
        }
        $target = json_decode((string) $row['target'], true);
        $row['target'] = is_array($target) ? $target : [];

        return $row;
    }

    /**
     * Use a grant in the running guarded action: lock its whole group, check
     * it is the player's, open and in time, and close the group. Throws
     * ActionAborted (the action is refused and rolled back) otherwise.
     *
     * @return array<string,mixed> The grant, its target decoded.
     */
    public static function claim(int $id, int $acctid, string $actionId): array
    {
        if (!Action::isRunning()) {
            throw new \LogicException('A grant is used inside Action::run(), where its group closes with the action.');
        }

        $conn = Database::getDoctrineConnection();
        $table = Database::prefix(self::TABLE);
        $key = $conn->fetchOne("SELECT target_key FROM {$table} WHERE id = :id", ['id' => $id], ['id' => ParameterType::INTEGER]);
        if ($key === false || $key === null) {
            throw new ActionAborted('grant-missing');
        }

        // The whole group, in one order: two players using grants of the same
        // group queue here instead of deadlocking on each other's rows.
        $group = $conn->fetchAllAssociative(
            "SELECT id, acctid, action, target, used_at, expires_at FROM {$table} WHERE target_key = :key ORDER BY id FOR UPDATE",
            ['key' => (string) $key]
        );
        $own = null;
        foreach ($group as $row) {
            if ((int) $row['id'] === $id) {
                $own = $row;
            }
        }
        if ($own === null || (int) $own['acctid'] !== $acctid) {
            throw new ActionAborted('grant-missing');
        }
        if ($own['used_at'] !== null) {
            throw new ActionAborted('grant-used');
        }
        if ((string) $own['expires_at'] <= date('Y-m-d H:i:s')) {
            throw new ActionAborted('grant-expired');
        }

        self::close((string) $key, $actionId, $group);

        $target = json_decode((string) $own['target'], true);
        $own['target'] = is_array($target) ? $target : [];

        return $own;
    }

    /**
     * Close every open grant of a group: the thing it offered is gone (the
     * applicant withdrew, or was accepted another way).
     */
    public static function voidTarget(string $key): void
    {
        if (!self::enabled()) {
            return;
        }
        $group = Database::getDoctrineConnection()->fetchAllAssociative(
            'SELECT id, acctid, used_at FROM ' . Database::prefix(self::TABLE) . ' WHERE target_key = :key AND used_at IS NULL',
            ['key' => $key]
        );
        if ($group !== []) {
            self::close($key, 'void', $group);
        }
    }

    /**
     * Whether a GET is the view link of an open grant of this player. Such a
     * link may have reached the page outside allowednavs (a realtime update
     * of the grants slot); it only displays, the POST that follows is
     * registered by that page as usual.
     */
    public static function isOfferedTo(int $acctid, string $requestUri): bool
    {
        if (!self::enabled() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }
        // Links in the navigation carry the session counter (&c=...) after
        // the grant; the slot's links do not.
        $link = (string) preg_replace('/&c=[^&]*$/', '', $requestUri);
        if (preg_match('/[?&]grant=(\d+)$/', $link, $match) !== 1) {
            return false;
        }

        $found = Database::getDoctrineConnection()->fetchOne(
            'SELECT 1 FROM ' . Database::prefix(self::TABLE)
            . ' WHERE id = :id AND acctid = :acctid AND link = :link AND used_at IS NULL AND expires_at > :now',
            ['id' => (int) $match[1], 'acctid' => $acctid, 'link' => $link, 'now' => date('Y-m-d H:i:s')],
            ['id' => ParameterType::INTEGER, 'acctid' => ParameterType::INTEGER]
        );

        return $found !== false && $found !== null;
    }

    /**
     * The version of a player's grants region: the last change to any of them.
     */
    public static function regionVersion(int $acctid): int
    {
        return (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT COALESCE(MAX(changed_us), 0) FROM ' . Database::prefix(self::TABLE) . ' WHERE acctid = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );
    }

    /**
     * The grants region: the open grants as links.
     */
    public static function regionHtml(int $acctid): string
    {
        $output = Output::getInstance();
        $html = '';
        foreach (self::open($acctid) as $grant) {
            $html .= "<a href='" . htmlspecialchars((string) $grant['link'], ENT_QUOTES, 'UTF-8') . "' class='nav'>"
                . $output->appoencode((string) $grant['text']) . '</a> ';
        }

        return $html;
    }

    /**
     * The open grants as a navigation section, for pages without the grants
     * slot. Called by the footer before the navigation is built.
     */
    public static function addNavs(int $acctid): void
    {
        $open = self::open($acctid);
        if ($open === []) {
            return;
        }
        Nav::add('Waiting for you');
        foreach ($open as $grant) {
            Nav::addNotl((string) $grant['text'], (string) $grant['link']);
        }
    }

    /**
     * @param list<array<string,mixed>> $group
     */
    private static function close(string $key, string $actionId, array $group): void
    {
        $changed = self::now();
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix(self::TABLE)
            . ' SET used_at = :now, used_by_action_id = :action_id, changed_us = :changed WHERE target_key = :key AND used_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'action_id' => substr($actionId, 0, 32), 'changed' => $changed, 'key' => $key],
            ['changed' => ParameterType::INTEGER]
        );

        $players = [];
        foreach ($group as $row) {
            if ($row['used_at'] === null) {
                $players[(int) $row['acctid']] = true;
            }
        }
        foreach (array_keys($players) as $player) {
            Realtime::announce(Realtime::userChannel($player), 'grant', ['region' => 'grants', 'version' => $changed]);
        }
    }

    private static function now(): int
    {
        return (int) round(microtime(true) * 1000000);
    }
}
