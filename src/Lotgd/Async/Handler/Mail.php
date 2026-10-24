<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Async\Response;

/**
 * Handle asynchronous mail status requests.
 */
class Mail
{
    /**
     * The player's mail for the notices in the upper right: the same answer
     * as Notices.status (`notices`, `session`). Kept for modules that ask
     * for it; the header's mail link is no longer assigned to #maillink.
     */
    public function mailStatus(bool $args = false): Response
    {
        if ($args === false) {
            return new Response();
        }

        return (new Notices())->status();
    }

    /**
     * The highest message id and the unread count of a player's mail: what
     * the page starts from (async/setup.php) and what a poll compares with.
     *
     * @return array{lastid:int,unread:int}
     */
    public static function counts(int $acctid): array
    {
        $row = Database::getDoctrineConnection()->executeQuery(
            'SELECT MAX(messageid) AS lastid, SUM(seen=0) AS unread FROM ' . Database::prefix('mail') . ' WHERE msgto = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        )->fetchAssociative();

        return [
            'lastid' => is_array($row) ? (int) ($row['lastid'] ?? 0) : 0,
            'unread' => is_array($row) ? (int) ($row['unread'] ?? 0) : 0,
        ];
    }
}
