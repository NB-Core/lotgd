<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\MySQL\Database;

use Jaxon\Response\Response;
use function Jaxon\jaxon;

/**
 * Handle asynchronous timeout checks.
 */
class Timeout
{
    /**
     * Update last activity time and report remaining session timeout.
     */
    public function timeoutStatus(bool $args = false): Response
    {
        global $session, $start_timeout_show_seconds, $never_timeout_if_browser_open;

        if ($args === false) {
            return jaxon()->newResponse();
        }

        if (!isset($session['user'])) {
            return jaxon()->newResponse();
        }

        $warning = '';

        if ($never_timeout_if_browser_open == 1) {
            $session['user']['laston'] = date('Y-m-d H:i:s'); // set to now
            // manual db update
            $sql = 'UPDATE ' . Database::prefix('accounts') . " set laston='" . $session['user']['laston']
                . "' WHERE acctid=" . $session['user']['acctid'];
            Database::query($sql);
        }

        $timeout = strtotime($session['user']['laston']) - strtotime(date('Y-m-d H:i:s', strtotime('-' . getsetting('LOGINTIMEOUT', 900) . ' seconds')));

        if ($timeout <= 1) {
            $warning = '' . appoencode('`$`b') . 'Your session has timed out!' . appoencode('`b');
        } elseif ($timeout < $start_timeout_show_seconds) {
            if ($timeout > 60) {
                $min = floor($timeout / 60);
                $sec = $timeout % 60;
                $warning = '<br/>' . appoencode('`t')
                    . sprintf_translate('TIMEOUT in %d minute%s und %d second%s!', $min, $min > 1 ? translate_inline('s') : '', $sec, $sec != 1 ? translate_inline('s') : '');
            } else {
                $warning = '<br/>' . appoencode('`t')
                    . sprintf_translate('TIMEOUT in %d second%s!', $timeout, $timeout != 1 ? translate_inline('s') : '');
            }
        } else {
            $warning = '';
        }

        $objResponse = jaxon()->newResponse();
        $objResponse->assign('notify', 'innerHTML', $warning);

        return $objResponse;
    }
}
