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
        $sql = 'SELECT MAX(messageid) AS lastid FROM ' . db_prefix('mail')
            . ' WHERE msgto=\'' . $session['user']['acctid'] . '\'';
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        if ($row === false) {
            $row = ['lastid' => 0];
        }
        db_free_result($result);
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
