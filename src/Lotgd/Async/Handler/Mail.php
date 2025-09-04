<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

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

        if ($args === false || empty($session['user']['acctid'])) {
            return jaxon()->newResponse();
        }

        $timeoutSetting = getsetting('LOGINTIMEOUT', 360); // seconds
        $new = maillink();
        $tabtext = maillinktabtext();

        // Get the highest message ID for the current user there is
        $sql = 'SELECT MAX(messageid) AS lastid FROM ' . \Lotgd\MySQL\Database::prefix('mail')
            . ' WHERE msgto=\'' . $session['user']['acctid'] . '\'';
        $result = \Lotgd\MySQL\Database::query($sql);
        $row = \Lotgd\MySQL\Database::fetchAssoc($result);
        if ($row === false) {
            $row = ['lastid' => 0];
        }
        \Lotgd\MySQL\Database::freeResult($result);
        $lastMailId = (int) ($row['lastid'] ?? 0);

        $objResponse = jaxon()->newResponse();
        $objResponse->assign('maillink', 'innerHTML', $new);

        if ($tabtext === '') {
            // there are no unseen mails
            return $objResponse;
        }

        $tabtext = translate_inline('Legend of the Green Dragon', 'home')
            . ' - ' . $tabtext;
        $objResponse->script('document.title="' . $tabtext . '";');
        $objResponse->script('lotgdMailNotify(' . $lastMailId . ');');

        return $objResponse;
    }
}
