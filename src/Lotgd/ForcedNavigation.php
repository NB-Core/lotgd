<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Serialization;
use Lotgd\Output;
use Lotgd\Redirect;
use Lotgd\PhpGenericEnvironment;

class ForcedNavigation
{
    public static array $baseAccount = [];

    /**
     * Handle forced navigation checks.
     */
    public static function doForcedNav(bool $anonymous, bool $overrideforced): void
    {
        global $session;
        $requestUri = PhpGenericEnvironment::getRequestUri();
        Output::getInstance()->rawOutput("<!--\nAllowAnonymous: " . ($anonymous ? "True" : "False") . "\nOverride Forced Nav: " . ($overrideforced ? "True" : "False") . "\n-->");
        if (isset($session['loggedin']) && $session['loggedin']) {
            $sql = "SELECT * FROM " . Database::prefix('accounts') . " WHERE acctid='" . $session['user']['acctid'] . "'";
            $result = Database::query($sql);
            if (Database::numRows($result) == 1) {
                $session['user'] = Database::fetchAssoc($result);
                global $baseaccount;
                $baseaccount = $session['user'];
                self::$baseAccount = $session['user'];
                $session['bufflist'] = Serialization::safeUnserialize($session['user']['bufflist']);
                if (!is_array($session['bufflist'])) {
                    $session['bufflist'] = [];
                }
                $session['user']['dragonpoints'] = Serialization::safeUnserialize($session['user']['dragonpoints']);
                if (!is_array($session['user']['dragonpoints'])) {
                    $session['user']['dragonpoints'] = [];
                }
                $session['user']['prefs'] = Serialization::safeUnserialize($session['user']['prefs']);
                if (!is_array($session['user']['prefs'])) {
                    $session['user']['prefs'] = [];
                }
                $allowednavs = Serialization::safeUnserialize($session['user']['allowednavs']);
                if (is_array($allowednavs)) {
                    $session['allowednavs'] = $allowednavs;
                } else {
                    $session['allowednavs'] = [];
                }
                if (!$session['user']['loggedin'] || ((date('U') - strtotime($session['user']['laston'])) > Settings::getInstance()->getSetting('LOGINTIMEOUT', 900))) {
                    $session = [];
                    if (defined('AJAX_MODE') && AJAX_MODE) {
                        $session['loggedin'] = false;
                        return;
                    }
                    Redirect::redirect('index.php?op=timeout', 'Account not logged in but session thinks they are.');
                }
            } else {
                $session = [];
                $session['message'] = Translator::translateInline("`4Error, your login was incorrect`0", "login");
                Redirect::redirect('index.php', 'Account Disappeared!');
            }
            Database::freeResult($result);
            if (isset($session['allowednavs'][$requestUri]) && $session['allowednavs'][$requestUri] && $overrideforced !== true) {
                $session['allowednavs'] = [];
            } else {
                if ($overrideforced !== true) {
                    Redirect::redirect('badnav.php', 'Navigation not allowed to ' . $requestUri);
                }
            }
        } else {
            if (!$anonymous) {
                if (defined('AJAX_MODE') && AJAX_MODE) {
                    $session['loggedin'] = false;
                    $session = [];
                    return;
                }
                Redirect::redirect('index.php?op=timeout', 'Not logged in: ' . $requestUri);
            }
        }
    }
}
