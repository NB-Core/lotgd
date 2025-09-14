<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
// translator ready
use Lotgd\Forms;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\Template;
use Lotgd\TwigTemplate;
use Lotgd\Cookies;

// addnews ready
// mail ready

define("ALLOW_ANONYMOUS", true);

if (! isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/home.php';
}

require_once __DIR__ . "/common.php";

use Lotgd\Page\Header;
use Lotgd\Page\Footer;


if (isset($_POST['template'])) {
        $skin = $_POST['template'];
    if ($skin !== '' && Template::isValidTemplate($skin)) {
            Template::setTemplateCookie($skin);
            Template::prepareTemplate(true); // set anew
    }
}


if (!isset($session['loggedin'])) {
    $session['loggedin'] = false;
}
if ($session['loggedin']) {
    redirect("badnav.php");
}
if (!isset($session['message'])) {
    $session['message'] = '';
}

Translator::getInstance()->setSchema("home");

$op = Http::get('op');

Header::pageHeader();
output("`cWelcome to Legend of the Green Dragon, a browser based role playing game, based on Seth Able's Legend of the Red Dragon.`n");

if (getsetting("homecurtime", 1)) {
    output("`@The current time in %s is `%%s`@.`0`n", getsetting("villagename", LOCATION_FIELDS), getgametime());
}

if (getsetting("homenewdaytime", 1)) {
    $secstonewday = secondstonextgameday();
    output(
        "`@Next new game day in: `\$%s (real time)`0`n`n",
        date(
            "G\\" . translate_inline("h", "datetime") . ", i\\" . translate_inline("m", "datetime") . ", s\\" . translate_inline("s", "datetime"),
            $secstonewday
        )
    );
}

if (getsetting("homenewestplayer", 1)) {
    $name = "";
    $newplayer = getsetting("newestplayer", "");
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
        output("`QThe newest resident of the realm is: `&%s`0`n`n", $name);
    }
}

clearnav();
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
if (getsetting('impressum', '') != '') {
    Nav::add("Imprint", "about.php");
}

modulehook("index", array());

if (abs(getsetting("OnlineCountLast", 0) - strtotime("now")) > 60) {
    $sql = "SELECT count(acctid) as onlinecount FROM " . Database::prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'" . date("Y-m-d H:i:s", strtotime("-" . getsetting("LOGINTIMEOUT", 900) . " seconds")) . "'";
    $result = Database::query($sql);
    $onlinecount = Database::fetchAssoc($result);
    $onlinecount = $onlinecount ['onlinecount'];
    savesetting("OnlineCount", $onlinecount);
    savesetting("OnlineCountLast", strtotime("now"));
} else {
    $onlinecount = getsetting("OnlineCount", 0);
}
if ($onlinecount < getsetting("maxonline", 0) || getsetting("maxonline", 0) == 0) {
    output("Enter your name and password to enter the realm.`n");
    if ($op == "timeout") {
        if (!isset($session['message'])) {
            $session['message'] = '';
        }
        $session['message'] .= translate_inline(" Your session has timed out, you must log in again.`n");
    }
    if (Cookies::getLgi() === null) {
            $session['message'] .= translate_inline("It appears that you may be blocking cookies from this site.  At least session cookies must be enabled in order to use this site.`n");
            $session['message'] .= translate_inline("`b`#If you are not sure what cookies are, please <a href='http://en.wikipedia.org/wiki/WWW_browser_cookie'>read this article</a> about them, and how to enable them.`b`n");
    }
    if (isset($session['message']) && $session['message'] > "") {
        output_notl("`b`\$%s`b`n", $session['message'], true);
    }
    rawoutput("<script src='src/Lotgd/md5.js' defer></script>");
    rawoutput("<script language='JavaScript'>
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
    $uname = translate_inline("<u>U</u>sername");
    $pass = translate_inline("<u>P</u>assword");
    $butt = translate_inline("Log in");
        $templateVars = ["username" => $uname, "password" => $pass, "button" => $butt];
    if (TwigTemplate::isActive()) {
        $templateVars['template_path'] = TwigTemplate::getPath();
    }
        rawoutput("<form action='login.php' method='POST' onSubmit=\"md5pass();\">" . templatereplace("login", $templateVars) . "</form>");
        output("Did you forget your password? Visit the <a href='create.php?op=forgot'>password reset page</a> to retrieve a new one!`n", true);
    output_notl("`c");
    Nav::add("", "login.php");
    modulehook("index-login", array());
} else {
    output("`\$`bServer full!`b`n`^Please wait until some users have logged out.`n`n`0");
    if ($op == "timeout") {
        $session['message'] .= translate_inline(" Your session has timed out, you must log in again.`n");
    }
    if (Cookies::getLgi() === null) {
            $session['message'] .= translate_inline("It appears that you may be blocking cookies from this site. At least session cookies must be enabled in order to use this site.`n");
            $session['message'] .= translate_inline("`b`#If you are not sure what cookies are, please <a href='http://en.wikipedia.org/wiki/WWW_browser_cookie'>read this article</a> about them, and how to enable them.`b`n");
    }
    if ($session['message'] > "") {
        output("`b`\$%s`b`n", $session['message'], true);
    }
        $templateVars = [];
    if (TwigTemplate::isActive()) {
        $templateVars['template_path'] = TwigTemplate::getPath();
    }
        rawoutput(templatereplace("loginfull", $templateVars));
    output_notl("`c");
}

$msg = getsetting("loginbanner", "*BETA* This is a BETA of this website, things are likely to change now and again, as it is under active development *BETA*");
output_notl("`n`c`b`&%s`0`b`c`n", $msg);
$session['message'] = "";
output("`c`2Game server running version: `@%s`0`c", $logd_version);

if (getsetting("homeskinselect", 1)) {
    rawoutput("<form action='home.php' method='POST'>");
    rawoutput("<table align='center'><tr><td>");
        $form = array("template" => "Choose a different display skin:,theme");
        $cookieTemplate = Template::getTemplateCookie();
    if ($cookieTemplate !== '') {
            $prefs['template'] = Template::addTypePrefix($cookieTemplate);
    } else {
            $prefs['template'] = Template::addTypePrefix(getsetting("defaultskin", DEFAULT_TEMPLATE));
    }
        Forms::showForm($form, $prefs, true);
    $submit = translate_inline("Choose");
    rawoutput("</td><td><br>&nbsp;<input type='submit' class='button login-button' value='$submit'></td>");
    rawoutput("</tr></table></form>");
}
modulehook("index_bottom", array());

Footer::pageFooter();
if ($op == "timeout") {
    session_unset();
    session_destroy(); // destroy if timeout
}
