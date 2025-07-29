<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\MySQL\Database;

class ForcedNavigation
{
    public static array $baseAccount = [];

    /**
     * Handle forced navigation checks.
     */
    public static function doForcedNav(bool $anonymous, bool $overrideforced): void
    {
        global $session, $REQUEST_URI;
        rawoutput("<!--\nAllowAnonymous: " . ($anonymous ? "True" : "False") . "\nOverride Forced Nav: " . ($overrideforced ? "True" : "False") . "\n-->");
        if (isset($session['loggedin']) && $session['loggedin']) {
            $sql = "SELECT * FROM " . Database::prefix('accounts') . " WHERE acctid='" . $session['user']['acctid'] . "'";
            $result = Database::query($sql);
            if (Database::numRows($result) == 1) {
                $session['user'] = Database::fetchAssoc($result);
                global $baseaccount;
                $baseaccount = $session['user'];
                self::$baseAccount = $session['user'];
                $session['bufflist'] = unserialize($session['user']['bufflist']);
                if (!is_array($session['bufflist'])) {
                    $session['bufflist'] = [];
                }
                $session['user']['dragonpoints'] = unserialize($session['user']['dragonpoints']);
                $session['user']['prefs'] = unserialize($session['user']['prefs']);
                if (!is_array($session['user']['dragonpoints'])) {
                    $session['user']['dragonpoints'] = [];
                }
                $allowednavs = Serialization::safeUnserialize($session['user']['allowednavs']);
                if (is_array($allowednavs)) {
                    $session['allowednavs'] = $allowednavs;
                } else {
                    $session['allowednavs'] = [];
                }
                if (!$session['user']['loggedin'] || ((date('U') - strtotime($session['user']['laston'])) > getsetting('LOGINTIMEOUT', 900))) {
                    $session = [];
                    if (defined('AJAX_MODE') && AJAX_MODE) {
                        $session['loggedin'] = false;
                        return;
                    }
                    redirect('index.php?op=timeout', 'Account not logged in but session thinks they are.');
                }
            } else {
                $session = [];
                $session['message'] = Translator::translateInline("`4Error, your login was incorrect`0", "login");
                redirect('index.php', 'Account Disappeared!');
            }
            Database::freeResult($result);
            if (isset($session['allowednavs'][$REQUEST_URI]) && $session['allowednavs'][$REQUEST_URI] && $overrideforced !== true) {
                $session['allowednavs'] = [];
            } else {
                if ($overrideforced !== true) {
                    redirect('badnav.php', 'Navigation not allowed to ' . $REQUEST_URI);
                }
            }
        } else {
            if (!$anonymous) {
                if (defined('AJAX_MODE') && AJAX_MODE) {
                    $session['loggedin'] = false;
                    $session = [];
                    return;
                }
                redirect('index.php?op=timeout', 'Not logged in: ' . $REQUEST_URI);
            }
        }
    }
}
