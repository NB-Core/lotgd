<?php

declare(strict_types=1);

/**
 * Helper to resolve the partner name for a player.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

class Partner
{
    /**
     * Determine the players partner depending on settings and marital status.
     *
     * @param bool $player If false use current session, otherwise use defaults
     *
     * @return string Partner name
     */
    public static function getPartner($player = false): string
    {
        global $session;
        if (!isset($session['user']['prefs']['sexuality']) || $session['user']['prefs']['sexuality'] == '') {
            $session['user']['prefs']['sexuality'] = !$session['user']['sex'];
        }
        if ($player === false) {
            $partner = getsetting('barmaid', '`%Violet');
            if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                $partner = getsetting('bard', '`^Seth');
            }
        } else {
            if ($session['user']['marriedto'] == INT_MAX) {
                $partner = getsetting('barmaid', '`%Violet');
                if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                    $partner = getsetting('bard', '`^Seth');
                }
            } else {
                $sql = 'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = ' . $session['user']['marriedto'];
                $result = Database::query($sql);
                if ($row = Database::fetchAssoc($result)) {
                    $partner = $row['name'];
                } else {
                    $session['user']['marriedto'] = 0;
                    $partner = getsetting('barmaid', '`%Violet');
                    if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                        $partner = getsetting('bard', '`^Seth');
                    }
                }
            }
        }
        return $partner;
    }
}
