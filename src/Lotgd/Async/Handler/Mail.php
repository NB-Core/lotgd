<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\PageParts;
use Lotgd\Settings;
use Lotgd\Translator;
use Jaxon\Response\Response;

use function Jaxon\jaxon;

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
            return jaxon()->newResponse();
        }

        $timeoutSetting = (int) $settings->getSetting('LOGINTIMEOUT', 360); // seconds
        $new = PageParts::mailLink();
        $tabtext = PageParts::mailLinkTabText();

        // Get the highest message ID and unread mail count for the current user
        $sql = 'SELECT MAX(messageid) AS lastid, SUM(seen=0) AS unread FROM '
            . Database::prefix('mail')
            . ' WHERE msgto = :acctid';
        $conn = Database::getDoctrineConnection();
        $row = $conn->executeQuery(
            $sql,
            ['acctid' => (int) $session['user']['acctid']],
            ['acctid' => ParameterType::INTEGER]
        )->fetchAssociative();
        if ($row === false) {
            $row = ['lastid' => 0, 'unread' => 0];
        }
        $lastMailId = (int) ($row['lastid'] ?? 0);
        $unreadCount = (int) ($row['unread'] ?? 0);

        $objResponse = jaxon()->newResponse();
        $objResponse->assign('maillink', 'innerHTML', $new);

        if ($tabtext === '') {
            // there are no unseen mails
            return $objResponse;
        }

        $tabtext = Translator::translateInline('Legend of the Green Dragon', 'home')
            . ' - ' . $tabtext;
        $objResponse->script->jo('document')->title = $tabtext;
        $objResponse->call('lotgdMailNotify', $lastMailId, $unreadCount);

        return $objResponse;
    }
}
