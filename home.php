<?php

declare(strict_types=1);

use Lotgd\Forms;
use Lotgd\Http;
use Lotgd\Modules\HookHandler as HookManager;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Redirect;
use Lotgd\Settings;
use Lotgd\Template;
use Lotgd\Translator;
// translator ready
use Lotgd\TwigTemplate;

// addnews ready
// mail ready

define("ALLOW_ANONYMOUS", true);

if (! isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/home.php';
}

require_once __DIR__ . '/common.php';

$output = Output::getInstance();
$settings = Settings::getInstance();

if (isset($_POST['template'])) {
    $skin = $_POST['template'];
    if ($skin !== '' && Template::isValidTemplate($skin)) {
        Template::setTemplateCookie($skin);
        Template::prepareTemplate(true); // set anew
    }
}

if (! isset($session['loggedin'])) {
    $session['loggedin'] = false;
}
if ($session['loggedin']) {
    Redirect::redirect('badnav.php');
}
if (! isset($session['message'])) {
    $session['message'] = '';
}

Translator::getInstance()->setSchema('home');

$op = Http::get('op');

Header::pageHeader();
$output->output("`cWelcome to Legend of the Green Dragon, a browser based role playing game, based on Seth Able's Legend of the Red Dragon.`n");

if ($settings->getSetting('homecurtime', 1)) {
    $output->output("`@The current time in %s is `%%s`@.`0`n", $settings->getSetting('villagename', LOCATION_FIELDS), getgametime());
}

if ($settings->getSetting('homenewdaytime', 1)) {
    $secstonewday = secondstonextgameday();
    $output->output(
        "`@Next new game day in: `\$%s (real time)`0`n`n",
        date(
            "G\\" . Translator::translateInline('h', 'datetime') . ", i\\" . Translator::translateInline('m', 'datetime') . ", s\\" . Translator::translateInline('s', 'datetime'),
            $secstonewday
        )
    );
}

if ($settings->getSetting('homenewestplayer', 1)) {
    $name = "";
    $newplayer = $settings->getSetting('newestplayer', '');
    if ($newplayer != 0) {
        $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid='$newplayer'";
        $result = Database::queryCached($sql, "newest");
        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            $name = $row['name'];
        }
    } else {
        $name = $newplayer;
    }
    if ($name != "") {
        $output->output("`QThe newest resident of the realm is: `&%s`0`n`n", $name);
    }
}

Nav::clearNav();
Nav::add("New to LoGD?");
Nav::add("Create a character", "create.php");
Nav::add("Game Functions");
Nav::add("Forgotten Password", "create.php?op=forgot");
Nav::add("List Warriors", "list.php");
Nav::add("Daily News", "news.php");
Nav::add("Other Info");
Nav::add("About LoGD", "about.php");
Nav::add("Game Setup Info", "about.php?op=setup");
Nav::add("LoGD Net", "logdnet.php?op=list");
Nav::add("Legal");
if ($settings->getSetting('impressum', '') != '') {
    Nav::add("Imprint", "about.php");
}

HookManager::hook('index', []);

$lastOnlineCountUpdate = (int) $settings->getSetting('OnlineCountLast', 0);
if (abs($lastOnlineCountUpdate - strtotime('now')) > 60) {
    $sql = "SELECT count(acctid) as onlinecount FROM " . Database::prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'" . date("Y-m-d H:i:s", strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds')) . "'";
    $result = Database::query($sql);
    $onlinecount = Database::fetchAssoc($result);
    $onlinecount = (int) ($onlinecount['onlinecount'] ?? 0);
    $settings->saveSetting('OnlineCount', $onlinecount);
    $settings->saveSetting('OnlineCountLast', strtotime('now'));
} else {
    $onlinecount = (int) $settings->getSetting('OnlineCount', 0);
}

$maxOnline = (int) $settings->getSetting('maxonline', 0);
if ($onlinecount < $maxOnline || $maxOnline === 0) {
    $output->output("Enter your name and password to enter the realm.`n");
    if ($op === 'timeout') {
        if (! isset($session['message'])) {
            $session['message'] = '';
        }
        $session['message'] .= Translator::translateInline(" Your session has timed out, you must log in again.`n");
    }

    if (! empty($session['flags']['lgi_failed'])) {
        $session['message'] .= Translator::translateInline("It appears that you may be blocking cookies from this site.  At least session cookies must be enabled in order to use this site.`n");
        $session['message'] .= Translator::translateInline("`b`#If you are not sure what cookies are, please <a href='http://en.wikipedia.org/wiki/WWW_browser_cookie'>read this article</a> about them, and how to enable them.`b`n");
    }

    if (! TwigTemplate::isActive() && $session['message'] > '') {
        $output->outputNotl("`b`\$%s`b`n", $session['message'], true);
    }

    $output->rawOutput("<script src='src/Lotgd/md5.js' defer></script>");
    $output->rawOutput("<script language='JavaScript'>
        <!--
        function md5pass(){
                //encode passwords before submission to protect them even from network sniffing attacks.
                var passbox = document.getElementById('password');
                if (passbox.value.substring(0, 5) != '!md5!') {
                        passbox.value = '!md5!' + hex_md5(passbox.value);
                }
        }
        //-->
        </script>");

    $usernameLabel = Translator::translateInline("<u>U</u>sername");
    $passwordLabel = Translator::translateInline("<u>P</u>assword");
    $loginLabel = Translator::translateInline('Log in');

    $message = (string) ($session['message'] ?? '');
    $message = preg_replace('/(?:\A(?:`n|\s)+|(?:`n|\s)+\z)/', '', $message) ?? '';
    $message = trim($message);

    $templateVars = [
        'username' => $usernameLabel,
        'password' => $passwordLabel,
        'button' => $loginLabel,
        'message' => $output->appoencode($message),
    ];

    if (TwigTemplate::isActive()) {
        $templateVars['template_path'] = TwigTemplate::getPath();
    }

    $output->rawOutput("<form action='login.php' method='POST' onSubmit=\"md5pass();\">" . templatereplace('login', $templateVars) . "</form>");
    $output->output("Did you forget your password? Visit the <a href='create.php?op=forgot'>password reset page</a> to retrieve a new one!`n", true);
    $output->outputNotl('`c');
    Nav::add('', 'login.php');
    HookManager::hook('index-login', []);
} else {
    $output->output("`\$`bServer full!`b`n`^Please wait until some users have logged out.`n`n`0");
    if ($op === 'timeout') {
        $session['message'] .= Translator::translateInline(" Your session has timed out, you must log in again.`n");
    }

    if (! empty($session['flags']['lgi_failed'])) {
        $session['message'] .= Translator::translateInline("It appears that you may be blocking cookies from this site. At least session cookies must be enabled in order to use this site.`n");
        $session['message'] .= Translator::translateInline("`b`#If you are not sure what cookies are, please <a href='http://en.wikipedia.org/wiki/WWW_browser_cookie'>read this article</a> about them, and how to enable them.`b`n");
    }

    if ($session['message'] > '') {
        $output->output("`b`\$%s`b`n", $session['message'], true);
    }

    $templateVars = [];
    if (TwigTemplate::isActive()) {
        $templateVars['template_path'] = TwigTemplate::getPath();
    }

    $output->rawOutput(templatereplace('loginfull', $templateVars));
    $output->outputNotl('`c');
}

$msg = $settings->getSetting('loginbanner', "*BETA* This is a BETA of this website, things are likely to change now and again, as it is under active development *BETA*");
$output->outputNotl("`n`c`b`&%s`0`b`c`n", $msg);
$session['message'] = "";
$output->output("`c`2Game server running version: `@%s`0`c", $logd_version);

if ($settings->getSetting('homeskinselect', 1)) {
    $output->rawOutput("<form action='home.php' method='POST'>");
    $output->rawOutput("<table align='center'><tr><td>");
    $form = ['template' => 'Choose a different display skin:,theme'];
    $cookieTemplate = Template::getTemplateCookie();
    if ($cookieTemplate !== '') {
        $prefs['template'] = Template::addTypePrefix($cookieTemplate);
    } else {
        $prefs['template'] = Template::addTypePrefix($settings->getSetting('defaultskin', DEFAULT_TEMPLATE));
    }
    Forms::showForm($form, $prefs, true);
    $submit = Translator::translateInline('Choose');
    $output->rawOutput("</td><td><br>&nbsp;<input type='submit' class='button login-button' value='$submit'></td>");
    $output->rawOutput("</tr></table></form>");
}
HookManager::hook('index_bottom', []);

Footer::pageFooter();
if ($op == "timeout") {
    session_unset();
    session_destroy(); // destroy if timeout
}
