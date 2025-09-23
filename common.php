<?php

use Lotgd\Translator;

require_once __DIR__ . '/autoload.php';

use Lotgd\BootstrapErrorHandler;
use Lotgd\SuAccess;
use Lotgd\AddNews;
use Lotgd\Buffs;
use Lotgd\Mounts;
use Lotgd\HolidayText;
use Lotgd\Output;
use Lotgd\Accounts;
use Lotgd\Settings;
use Lotgd\PhpGenericEnvironment;
use Lotgd\ForcedNavigation;
use Lotgd\Nav;
use Lotgd\LocalConfig;
use Lotgd\PageParts;
use Lotgd\Page\Header;
use Lotgd\Util\ScriptName;
use Lotgd\Page\Footer;
use Lotgd\Redirect;
use Lotgd\Template;
use Lotgd\MySQL\Database;
use Lotgd\DateTime;
use Lotgd\Cookies;
use Lotgd\ErrorHandler;
use Lotgd\Page;
use Lotgd\Modules\HookHandler;

BootstrapErrorHandler::register();
// translator ready
// addnews ready
// mail ready

// **** NOTICE ****
// This series of scripts (collectively known as Legend of the Green Dragon
// or LotGD) is copyright as per below.
// You are prohibited by law from removing or altering this copyright
// information in any fashion except as follows:
//      if you have added functionality to the code, you may append your
//      name at the end indicating which parts are copyright by you.
// Eg:
// Copyright 2002-2004, Game: Eric Stevens & JT Traub, modified by Your Name
$copyright = "Game Design and Code: Copyright &copy; 2002-2005, Eric Stevens & JT Traub, &copy; 2006-2007, Dragonprime Development Team <span class='colDkRed'> &copy 2007-? Oliver Brendel remodelling and enhancing</span>";
Page::getInstance()->setCopyright($copyright);
// **** NOTICE ****
// This series of scripts (collectively known as Legend of the Green Dragon
// or LotGD) is copyright as per above.   Read the above paragraph for
// instructions regarding this copyright notice.

// **** NOTICE ****
// This series of scripts (collectively known as Legend of the Green Dragon
// or LotGD) is licensed according to the Creating Commons Attribution
// Non-commercial Share-alike license.  The terms of this license must be
// followed for you to legally use or distribute this software.   This
// license must be used on the distribution of any works derived from this
// work.  This license text may not be removed nor altered in any way.
// Please see the file LICENSE for a full textual description of the license.
$license = "\n<!-- Creative Commons License -->\n<a rel='license' href='http://creativecommons.org/licenses/by-nc-sa/2.0/' target='_blank'><img clear='right' align='left' alt='Creative Commons License' border='0' src='images/somerights20.gif' /></a>\nThis work is licensed under a <a rel='license' href='http://creativecommons.org/licenses/by-nc-sa/2.0/' target='_blank'>Creative Commons License</a>.<br />\n<!-- /Creative Commons License -->\n<!--\n  <rdf:RDF xmlns='http://web.resource.org/cc/' xmlns:dc='http://purl.org/dc/elements/1.1/' xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n	<Work rdf:about=''>\n	  <dc:type rdf:resource='http://purl.org/dc/dcmitype/Interactive' />\n	  <license rdf:resource='http://creativecommons.org/licenses/by-nc-sa/2.0/' />\n	</Work>\n	<License rdf:about='http://creativecommons.org/licenses/by-nc-sa/2.0/'>\n	  <permits rdf:resource='http://web.resource.org/cc/Reproduction' />\n	  <permits rdf:resource='http://web.resource.org/cc/Distribution' />\n	  <requires rdf:resource='http://web.resource.org/cc/Notice' />\n	  <requires rdf:resource='http://web.resource.org/cc/Attribution' />\n	  <prohibits rdf:resource='http://web.resource.org/cc/CommercialUse' />\n	  <permits rdf:resource='http://web.resource.org/cc/DerivativeWorks' />\n	  <requires rdf:resource='http://web.resource.org/cc/ShareAlike' />\n	</License>\n  </rdf:RDF>\n-->\n";
// .... NOTICE *****
// This series of scripts (collectively known as Legend of the Green Dragon
// or LotGD) is licensed according to the Creating Commons Attribution
// Non-commercial Share-alike license.  The terms of this license must be
// followed for you to legally use or distribute this software.   This
// license must be used on the distribution of any works derived from this
// work.  This license text may not be removed nor altered in any way.
// Please see the file LICENSE for a full textual description of the license.

$logd_version = "2.0.0-rc +nb Edition";
Page::getInstance()->setLogdVersion($logd_version);

// Include some commonly needed and useful routines
require_once __DIR__ . "/lib/output.php";
LocalConfig::apply();
require_once __DIR__ . "/src/Lotgd/Config/constants.php";

// Legacy, because modules may rely on that, but those files are already migrated to namespace structure
require_once __DIR__ . "/lib/dbwrapper.php";
require_once __DIR__ . "/lib/modules.php";
require_once __DIR__ . "/lib/translator.php";
require_once __DIR__ . "/lib/sanitize.php";
require_once __DIR__ . "/lib/holiday_texts.php";
require_once __DIR__ . "/lib/nav.php";
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/e_rand.php";
require_once __DIR__ . "/lib/pageparts.php";
require_once __DIR__ . "/lib/tempstat.php";
require_once __DIR__ . "/lib/su_access.php";
require_once __DIR__ . "/lib/datetime.php";
require_once __DIR__ . "/lib/translator.php";
require_once __DIR__ . "/lib/playerfunctions.php";
require_once __DIR__ . "/lib/serialization.php";
require_once __DIR__ . "/lib/settings.php";
require_once __DIR__ . "/lib/buffs.php";
require_once __DIR__ . "/lib/addnews.php";
require_once __DIR__ . "/lib/template.php";
require_once __DIR__ . "/lib/redirect.php";
require_once __DIR__ . "/lib/censor.php";
require_once __DIR__ . "/lib/saveuser.php";
require_once __DIR__ . "/lib/arrayutil.php";
require_once __DIR__ . "/lib/sql.php";
require_once __DIR__ . "/lib/mounts.php";
require_once __DIR__ . "/lib/debuglog.php";
require_once __DIR__ . "/lib/datacache.php";
require_once __DIR__ . "/lib/fightnav.php";
require_once __DIR__ . "/lib/villagenav.php";

ErrorHandler::register();

// Enable zlib output compression when available.
if (function_exists('ini_set') && extension_loaded('zlib')) {
    ini_set('zlib.output_compression', '1');
}

// Start output buffering (compression occurs automatically when enabled).
ob_start();

$pagestarttime = DateTime::getMicroTime();
PhpGenericEnvironment::setPageStartTime($pagestarttime);

// Set some constant defaults in case they weren't set before the inclusion of
// common.php
if (!defined("OVERRIDE_FORCED_NAV")) {
    define("OVERRIDE_FORCED_NAV", false);
}
if (!defined("ALLOW_ANONYMOUS")) {
    define("ALLOW_ANONYMOUS", false);
}
if (!defined('AJAX_MODE')) {
    define('AJAX_MODE', false);
}

//Initialize variables required for this page

// wrappers no longer required for these helpers

//session_register("session");
//deprecated

$time = $_SERVER['REQUEST_TIME'];

/**
 * for a 30 minute timeout, specified in seconds
 */
$timeout_duration = 60 * 60;

/**
 * Here we look for the user.s LAST_ACTIVITY timestamp. If
 * it.s set and indicates our $timeout_duration has passed,
 * blow away any previous $_SESSION data and start a new one.
 */
if (isset($_SESSION['LAST_ACTIVITY']) && ($time - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
}
session_start();

/**
 * Finally, update LAST_ACTIVITY so that our timeout
 * is based on it and not the user.s login time.
 */
$_SESSION['LAST_ACTIVITY'] = $time;

//session_start();

$session =& $_SESSION['session'];

// lets us provide output in dbconnect.php that only appears if there's a
// problem connecting to the database server.  Useful for migration moves
// like LotGD.net experienced on 7/20/04.
ob_start();
if (file_exists("dbconnect.php")) {
    $config = require "dbconnect.php";

    if (!is_array($config)) {
        $config = [
            'DB_HOST' => $DB_HOST ?? '',
            'DB_USER' => $DB_USER ?? '',
            'DB_PASS' => $DB_PASS ?? '',
            'DB_NAME' => $DB_NAME ?? '',
            'DB_PREFIX' => $DB_PREFIX ?? '',
            'DB_USEDATACACHE' => $DB_USEDATACACHE ?? 0,
            'DB_DATACACHEPATH' => $DB_DATACACHEPATH ?? '',
        ];
    }

    $DB_HOST = $config['DB_HOST'] ?? '';
    $DB_USER = $config['DB_USER'] ?? '';
    $DB_PASS = $config['DB_PASS'] ?? '';
    $DB_NAME = $config['DB_NAME'] ?? '';
    $DB_PREFIX = $config['DB_PREFIX'] ?? '';
    Database::setPrefix($DB_PREFIX);
    $DB_USEDATACACHE = $config['DB_USEDATACACHE'] ?? 0;
    $DB_DATACACHEPATH = $config['DB_DATACACHEPATH'] ?? '';
    // Validate cache path early and prepare an admin-facing warning if needed
    $GLOBALS['__DATACACHE_WARNING__'] = '';
    if (!empty($DB_USEDATACACHE)) {
        $cachePath = (string) $DB_DATACACHEPATH;
        $invalid = ($cachePath === '')
            || (file_exists($cachePath) && !is_dir($cachePath))
            || (!file_exists($cachePath) && !is_dir(dirname($cachePath)))
            || (file_exists($cachePath) && !is_writable($cachePath));
        if ($invalid) {
            $GLOBALS['__DATACACHE_WARNING__'] = "Data cache is enabled but the configured path is missing or not writable. Set 'DB_DATACACHEPATH' to a writable directory or disable 'DB_USEDATACACHE'.";
        }
    }
} else {
    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
        if (!defined("DB_NODB")) {
            define("DB_NODB", true);
        }
        if (!AJAX_MODE) {
                Header::pageHeader("The game has not yet been installed");
                output("`#Welcome to `@Legend of the Green Dragon`#, a game by Eric Stevens & JT Traub.`n`n");
            output("You must run the game's installer, and follow its instructions in order to set up LoGD.  You can go to the installer <a href='installer.php'>here</a>.", true);
                output("`n`nIf you're not sure why you're seeing this message, it's because this game is not properly configured right now. ");
                output("If you've previously been running the game here, chances are that you lost a file called '`%dbconnect.php`#' from your site.");
                output("If that's the case, no worries, we can get you back up and running in no time, and the installer can help!");
            Nav::add("Game Installer", "installer.php");
                Footer::pageFooter();
        }
                $session = array(); // reset the session so that it doesn't have any old data in it
    }
}

// If you are running a server that has high overhead to *connect* to your
// database (such as a high latency network connection to mysql),
// reversing the commenting of the following two code lines may significantly
// increase your overall performance.  Pconnect uses more server resources though.
// For more details, see
// http://php.net/manual/en/features.persistent-connections.php
//
// Line is important for installer only, step 5
//if (defined("IS_INSTALLER") && httpget('stage')>5)
//  $link = Database::pconnect($DB_HOST, $DB_USER, $DB_PASS);
$link = false;
if (!defined("DB_NODB")) {
        $link = Database::connect($config['DB_HOST'] ?? '', $config['DB_USER'] ?? '', $config['DB_PASS'] ?? '');

        //set charset to UTF-8 (table default, don't change that!)
    if (!Database::setCharset("utf8mb4")) {
            echo "Error setting db connection charset to UTF-8...please check your db connection!";
            exit(0);
    }
}

$out = ob_get_contents();
ob_end_clean();

if ($link === false) {
    if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
                // Ignore this bit.  It's only really for Eric's server
                //I won't, because all people can use it //Oliver
                //Yet made a bit more interesting text than just the naughty normal "Unable to connect to database - sorry it didn't work out" stuff
                $notified = false;
        if (file_exists("config/smsnotify.php")) {
                $smsmessage = "No DB Server: " . Database::error();
                require_once __DIR__ . "/config/smsnotify.php";
                $notified = true;
        }
                // And tell the user it died.  No translation here, we need the DB for
                // translation.
        if (!defined("DB_NODB")) {
            define("DB_NODB", true);
        }
        if (!AJAX_MODE) {
                Header::pageHeader("Database Connection Error");
                output("`c`\$Database Connection Error`0`c`n`n");
                output("`xDue to technical problems the game is unable to connect to the database server.`n`n");
            if (!$notified) {
                        //the admin did not want to notify him with a script
                            output("Please notify the head admin or any other staff member you know via email or any other means you have at hand to care about this.`n`n");
                            //add the message as it was not enclosed and posted to the smsnotify file
                            output("Please give them the following error message:`n");
                            output("`i`1%s`0`i`n`n", $smsmessage, true);
            } else {
                            //in any other case
                            output("The admins have been notified of this. As soon as possible they will fix this up.`n`n");
            }
                            output("Sorry for the inconvenience,`n");
                            output("Staff of %s", $_SERVER['SERVER_NAME']);
                            Nav::add("Home", "index.php");
                            Footer::pageFooter();
        }
    }
    define("DB_CONNECTED", false);
} else {
    define("DB_CONNECTED", true);
}

if (!defined("DB_NODB")) {
    if (!DB_CONNECTED || !@Database::selectDb($config['DB_NAME'])) {
        if ((!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) && DB_CONNECTED) {
            // Ignore this bit.  It's only really for Eric's server or people that want to trigger something when the database is jerky
            if (file_exists("config/smsnotify.php")) {
                                $smsmessage = "Cant Attach to DB: " . Database::error();
                require_once __DIR__ . "/config/smsnotify.php";
                $notified = true;
            }
            // And tell the user it died.  No translation here, we need the DB for
            // translation.
            if (!defined("DB_NODB")) {
                define("DB_NODB", true);
            }
            if (!AJAX_MODE) {
                        Header::pageHeader("Database Connection Error");
                        output("`c`\$Database Connection Error`0`c`n`n");
                        output("`xDue to technical problems the game is unable to connect to the database server.`n`n");
                if (!$notified) {
                    //the admin did not want to notify him with a script
                    output("Please notify the head admin or any other staff member you know via email or any other means you have at hand to care about this.`n`n");
                    //add the message as it was not enclosed and posted to the smsnotify file
                    output("Please give them the following error message:`n");
                    output("`i`1%s`0`i`n`n", $smsmessage, true);
                } else {
                    //in any other case
                    output("The admins have been notified of this. As soon as possible they will fix this up.`n`n");
                }
                        output("Sorry for the inconvenience,`n");
                        output("Staff of %s", $_SERVER['SERVER_NAME']);
                        Nav::add("Home", "index.php");
                        Footer::pageFooter();
            }
        }
        define("DB_CHOSEN", false);
    } else {
        define("LINK", $link);
        define("DB_CHOSEN", true);
    }
}

//Generate our settings object
if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
    $settings = new Settings('settings');
}

if (isset($settings) && $logd_version == $settings->getSetting("installer_version", "-1")) {
    define("IS_INSTALLER", false);
}

$charset = isset($settings) ? $settings->getSetting('charset', 'UTF-8') : 'UTF-8';

header("Content-Type: text/html; charset=" . $charset);

// Surface cache path issues to administrators (non-fatal).
if (!AJAX_MODE && isset($settings) && ($GLOBALS['__DATACACHE_WARNING__'] ?? '') !== '') {
    // Show only to admins with config rights
    if ((($session['user']['superuser'] ?? 0) & SU_EDIT_CONFIG) == SU_EDIT_CONFIG) {
        Translator::translatorSetup();
        output("`c`4Performance Warning:`0 %s`c`n", $GLOBALS['__DATACACHE_WARNING__'], true);
    }
}

$loginTimeOut = isset($settings) ? $settings->getSetting("LOGINTIMEOUT", 900) : 900;

if (isset($session['lasthit']) && isset($session['loggedin']) && strtotime("-" . $loginTimeOut . " seconds") > $session['lasthit'] && $session['lasthit'] > 0 && $session['loggedin']) {
    // force the abandoning of the session when the user should have been
    // sent to the fields.
    $session = array();
    // technically we should be able to translate this, but for now,
    // ignore it.
    // 1.1.1 now should be a good time to get it on with it, added tl-inline
    Translator::translatorSetup();
    if (!isset($session['message'])) {
        $session['message'] = '';
    }
    $session['message'] .= Translator::translateInline("`nYour session has expired!`n", "common");
}
$session['lasthit'] = strtotime("now");


PhpGenericEnvironment::setup($session);
if (!AJAX_MODE) {
    ForcedNavigation::doForcedNav(ALLOW_ANONYMOUS, OVERRIDE_FORCED_NAV);
}

$script = ScriptName::current();
if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
    mass_module_prepare(array(
                'template-header','template-footer','template-statstart','template-stathead','template-statrow','template-statbuff','template-statend',
                'template-navhead','template-navitem','template-petitioncount','template-adwrapper','template-login','template-loginfull','everyhit',
                "header-$script","footer-$script",'holiday','collapse{','collapse-nav{','}collapse-nav','}collapse','charstats'
                ));
}
// In the event of redirects, we want to have a version of their session we
// can revert to:
$revertsession = $session;
if (!isset($session['user']['loggedin'])) {
    $session['user']['loggedin'] = false;
}

if ($session['user']['loggedin'] != true && !ALLOW_ANONYMOUS) {
    if (!AJAX_MODE) {
        Redirect::redirect('login.php?op=logout');
    }
    // For AJAX_MODE, allow the caller to handle a timed-out session.
}

if (!isset($session['counter'])) {
    $session['counter'] = 0;
}
$session['counter']++;
$nokeeprestore = ["newday.php" => 1, "badnav.php" => 1, "motd.php" => 1, "mail.php" => 1, "petition.php" => 1];
$scriptNameEnv = PhpGenericEnvironment::getScriptName();
if (OVERRIDE_FORCED_NAV) {
    $nokeeprestore[$scriptNameEnv] = 1;
}
if (!isset($nokeeprestore[$scriptNameEnv]) || !$nokeeprestore[$scriptNameEnv]) {
    $session['user']['restorepage'] = PhpGenericEnvironment::getRequestUri();
} else {
}


if (isset($settings) && $logd_version != $settings->getSetting('installer_version', '-1') && (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER))) {
    if (!AJAX_MODE) {
            Header::pageHeader("Upgrade Needed");
            output("`#The game is temporarily unavailable while a game upgrade is applied, please be patient, the upgrade will be completed soon.");
            output("In order to perform the upgrade, an admin will have to run through the installer.");
        output("If you are an admin, please <a href='installer.php'>visit the Installer</a> and complete the upgrade process.`n`n", true);
            output("`@If you don't know what this all means, just sit tight, we're doing an upgrade and will be done soon, you will be automatically returned to the game when the upgrade is complete.");
            rawoutput("<meta http-equiv='refresh' content='30; url={$session['user']['restorepage']}'>");
        Nav::add("Installer (Admins only!)", "installer.php");
            Footer::pageFooter();
    }
        define("NO_SAVE_USER", true);
} elseif (isset($settings) && $logd_version == $settings->getSetting("installer_version", "-1")  && file_exists('installer.php') && basename($_SERVER['SCRIPT_NAME']) !== 'installer.php') {
        // here we have a nasty situation. The installer file exists (ready to be used to get out of any bad situation like being defeated etc and it is no upgrade or new installation. It MUST be deleted
    if (!AJAX_MODE) {
            Header::pageHeader("Major Security Risk");
        output("`\$Remove the file named 'installer.php' from your main game directory! You need to comply in order to get the game up and running.");
            Nav::add("Home", "index.php");
            Footer::pageFooter();
    }
}


if (isset($session['user']['hitpoints']) && $session['user']['hitpoints'] > 0) {
    $session['user']['alive'] = 1;
} else {
    $session['user']['alive'] = 0;
}

if (isset($session['user']['bufflist'])) {
    $session['bufflist'] = unserialize($session['user']['bufflist']);
} else {
    $session['bufflist'] = array();
}
if (!is_array($session['bufflist'])) {
    $session['bufflist'] = array();
}
$remoteAddr = PhpGenericEnvironment::getRemoteAddr();
if ($remoteAddr !== '') {
    $session['user']['lastip'] = $remoteAddr;   //cron i.e. doesn't have an $REMOTE_ADDR
}
$cookieId = Cookies::getLgi();
if ($cookieId === null) {
    if (!isset($session['user']['uniqueid']) || strlen($session['user']['uniqueid']) < 32) {
            $u = md5(microtime());
            Cookies::setLgi($u);
            $session['user']['uniqueid'] = $u;
    } else {
        if (isset($session['user']['uniqueid'])) {
                Cookies::setLgi($session['user']['uniqueid']);
        }
    }
} else {
        $session['user']['uniqueid'] = $cookieId;
}
if (isset($_SERVER['SERVER_NAME'])) {
    $url = "http://" . $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']);
    $url = substr($url, 0, strlen($url) - 1);
    $urlport = "http://" . $_SERVER['SERVER_NAME'] . ":" . $_SERVER['SERVER_PORT'] . dirname($_SERVER['REQUEST_URI']);
    $urlport = substr($urlport, 0, strlen($urlport) - 1);
} else {
    //cron access or such via cli
    $url = "";
    $urlport = "";
}

if (!isset($_SERVER['HTTP_REFERER'])) {
    $_SERVER['HTTP_REFERER'] = "";
}

if (
        substr($_SERVER['HTTP_REFERER'], 0, strlen($url)) == $url ||
        substr($_SERVER['HTTP_REFERER'], 0, strlen($urlport)) == $urlport ||
        $_SERVER['HTTP_REFERER'] == "" ||
        strtolower(substr($_SERVER['HTTP_REFERER'], 0, 7)) != "http://"
) {
} else {
    $site = str_replace("http://", "", $_SERVER['HTTP_REFERER']);
    if (strpos($site, "/")) {
        $site = substr($site, 0, strpos($site, "/"));
    }
    $host = str_replace(":80", "", $_SERVER['HTTP_HOST']);

    if ($site != $host) {
                $sql = "SELECT * FROM " . Database::prefix("referers") . " WHERE uri='{$_SERVER['HTTP_REFERER']}'";
                $result = Database::query($sql);
                $row = Database::fetchAssoc($result);
                Database::freeResult($result);
        if (isset($row['refererid']) && $row['refererid'] > "") {
                $sql = "UPDATE " . Database::prefix("referers") . " SET count=count+1,last='" . date("Y-m-d H:i:s") . "',site='" . addslashes($site) . "',dest='" . addslashes($host) . "/" . addslashes(PhpGenericEnvironment::getRequestUri()) . "',ip='{$_SERVER['REMOTE_ADDR']}' WHERE refererid='{$row['refererid']}'";
        } else {
                $sql = "INSERT INTO " . Database::prefix("referers") . " (uri,count,last,site,dest,ip) VALUES ('{$_SERVER['HTTP_REFERER']}',1,'" . date("Y-m-d H:i:s") . "','" . addslashes($site) . "','" . addslashes($host) . "/" . addslashes(PhpGenericEnvironment::getRequestUri()) . "','{$_SERVER['REMOTE_ADDR']}')";
        }
                Database::query($sql);
    }
}

if (!isset($session['user']['superuser'])) {
    $session['user']['superuser'] = 0;
}
Page::getInstance()->antiCheatProtection();

Template::prepareTemplate();

if (
    ($session['user']['loggedin'] ?? false)
    && (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER))
) {
    if (!isset($session['user']['hashorse'])) {
        $session['user']['hashorse'] = 0;
    }
    Mounts::getInstance()->loadPlayerMount($session['user']['hashorse']);

    global $playermount; // Legacy setting for modules
    $playermount = Mounts::getInstance()->getPlayerMount();
    $temp_comp = @unserialize($session['user']['companions']);
    $companions = array();
    if (is_array($temp_comp)) {
        foreach ($temp_comp as $name => $companion) {
            if (is_array($companion)) {
                $companions[$name] = $companion;
            }
        }
    }
    unset($temp_comp);

    $beta = $settings->getSetting("beta", 0);
    if (!$beta && $settings->getSetting("betaperplayer", 1) == 1) {
        if (isset($session['user']['beta'])) {
            $beta = $session['user']['beta'];
        } else {
            $beta = 0;
        }
    }

    if (isset($session['user']['clanid'])) {
            $sql = "SELECT * FROM " . Database::prefix("clans") . " WHERE clanid='{$session['user']['clanid']}'";
            $result = Database::queryCached($sql, "clandata-{$session['user']['clanid']}", 3600);
        if (Database::numRows($result) > 0) {
                $claninfo = Database::fetchAssoc($result);
        } else {
            $claninfo = array();
            $session['user']['clanid'] = 0;
            $session['user']['clanrank'] = 0;
        }
    } else {
        $claninfo = array();
        $session['user']['clanid'] = 0;
        $session['user']['clanrank'] = 0;
    }

    if ($session['user']['superuser'] & SU_MEGAUSER) {
        $session['user']['superuser'] =
            $session['user']['superuser'] | SU_EDIT_USERS;
    }

    Translator::translatorSetup();
}

if (
    ($session['user']['loggedin'] ?? false)
    && (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER))
    && $settings->getSetting('debug', 0)
) {
    //Server runs in Debug mode, tell the superuser about it
    if (($session['user']['superuser'] & SU_EDIT_CONFIG) == SU_EDIT_CONFIG) {
        Translator::getInstance()->setSchema("debug");
        output("<center>`\$<h2>SERVER RUNNING IN DEBUG MODE</h2></center>`n`n", true);
        Translator::getInstance()->setSchema();
    }
}

// After setup, allow modification of colors and nested tags
$output = \Lotgd\Output::getInstance();
$colors = HookHandler::hook("core-colors", $output->getColors());
$output->setColors($colors);
// and nested tag handling
$nestedtags = HookHandler::hook("core-nestedtags", $output->getNestedTags());
$output->setNestedTags($nestedtags);
// and nested tag eval
$nestedeval = HookHandler::hook("core-nestedtags-eval", $output->getNestedTagEval());
$output->setNestedTagEval($nestedeval);


// WARNING:
// do not hook on this modulehook unless you really need your module to run
// on every single page hit.  This is called even when the user is not
// logged in!!!
// This however is the only context where blockmodule can be called safely!
// You should do as LITTLE as possible here and consider if you can hook on
// a page header instead.
if (!defined('IS_INSTALLER') || (defined('IS_INSTALLER') && !IS_INSTALLER)) {
    HookHandler::hook('everyhit');
}
