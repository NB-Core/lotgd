<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Async\Handler\Mail;
use Lotgd\MySQL\Database;
use Lotgd\Settings;

/**
 * What the notices in the upper right (assets/js/lotgd.notices.js) tell a
 * player: their mail and whether a Message of the Day is unread. The page
 * starts with it (SessionPanel::meta()), the async call Notices.status
 * brings it again, and the AJAX poll carries it along.
 */
final class Notices
{
    /**
     * @param array<string,mixed> $user The session user, read fresh.
     *
     * @return array{mail:array{unread:int,lastId:int},motd:string}
     *         motd: the date of the newest Message of the Day the player has
     *         not seen, '' when there is none.
     */
    public static function status(array $user): array
    {
        $acctid = (int) ($user['acctid'] ?? 0);
        if ($acctid <= 0) {
            return ['mail' => ['unread' => 0, 'lastId' => 0], 'motd' => ''];
        }
        ['lastid' => $lastId, 'unread' => $unread] = Mail::counts($acctid);

        return ['mail' => ['unread' => $unread, 'lastId' => $lastId], 'motd' => self::unreadMotd($user)];
    }

    /**
     * Seconds between two looks at the notices; 0 when the game does not
     * look (setting notices_poll_seconds).
     */
    public static function interval(): int
    {
        $seconds = (int) Settings::getInstance()->getSetting('notices_poll_seconds', 60);

        return $seconds <= 0 ? 0 : max(15, $seconds);
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function unreadMotd(array $user): string
    {
        $latest = Database::getDoctrineConnection()->executeQuery(
            'SELECT motddate FROM ' . Database::prefix('motd') . ' ORDER BY motditem DESC LIMIT 1',
            [],
            []
        )->fetchOne();
        if (!is_string($latest) || $latest === '') {
            return '';
        }
        $seen = $user['lastmotd'] ?? '';
        if ($seen instanceof \DateTimeInterface) {
            $seen = $seen->format('Y-m-d H:i:s');
        }

        return $latest > (string) $seen ? $latest : '';
    }
}
