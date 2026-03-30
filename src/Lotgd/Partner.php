<?php

declare(strict_types=1);

/**
 * Helper to resolve the partner name for a player.
 */

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\Settings;
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
        $settings = Settings::getInstance();
        if (!isset($session['user']['prefs']['sexuality']) || $session['user']['prefs']['sexuality'] == '') {
            $session['user']['prefs']['sexuality'] = !$session['user']['sex'];
        }
        if ($player === false) {
            $partner = $settings->getSetting('barmaid', '`%Violet');
            if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                $partner = $settings->getSetting('bard', '`^Seth');
            }
        } else {
            if ($session['user']['marriedto'] == INT_MAX) {
                $partner = $settings->getSetting('barmaid', '`%Violet');
                if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                    $partner = $settings->getSetting('bard', '`^Seth');
                }
            } else {
                $sql = 'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid';
                $conn = Database::getDoctrineConnection();
                $row = $conn->executeQuery(
                    $sql,
                    ['acctid' => (int) $session['user']['marriedto']],
                    ['acctid' => ParameterType::INTEGER]
                )->fetchAssociative();
                if (is_array($row)) {
                    $partner = $row['name'];
                } else {
                    $session['user']['marriedto'] = 0;
                    $partner = $settings->getSetting('barmaid', '`%Violet');
                    if ($session['user']['prefs']['sexuality'] == SEX_MALE) {
                        $partner = $settings->getSetting('bard', '`^Seth');
                    }
                }
            }
        }
        return $partner;
    }
}
