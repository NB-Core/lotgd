<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\PageParts;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Async\Response;

/**
 * Handle asynchronous mail status requests.
 */
class Mail
{
    /**
     * Return mail and timeout status information for the active user.
     */
    public function mailStatus(bool $args = false): Response
    {
        global $session;
        $settings = Settings::getInstance();

        if ($args === false || empty($session['user']['acctid'])) {
            return new Response();
        }

        $timeoutSetting = (int) $settings->getSetting('LOGINTIMEOUT', 360); // seconds
        $new = PageParts::mailLink();
        $tabtext = PageParts::mailLinkTabText();

        ['lastid' => $lastMailId, 'unread' => $unreadCount] = self::counts((int) $session['user']['acctid']);

        $objResponse = new Response();
        $objResponse->assign('maillink', 'innerHTML', $new);

        if ($tabtext === '') {
            // there are no unseen mails
            return $objResponse;
        }

        $tabtext = Translator::translateInline('Legend of the Green Dragon', 'home')
            . ' - ' . $tabtext;
        $objResponse->title($tabtext);
        $objResponse->call('lotgdMailNotify', $lastMailId, $unreadCount);

        return $objResponse;
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
