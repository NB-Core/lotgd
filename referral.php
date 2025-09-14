<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Settings;
use Lotgd\Http;

// translator ready
// addnews ready
// mail ready
define("ALLOW_ANONYMOUS", true);
require_once __DIR__ . "/common.php";

global $session;

$settings   = Settings::getInstance();
$translator = Translator::getInstance();
$translator->setSchema("referral");

if ($session['user']['loggedin']) {
    page_header("Referral Page");
    if (file_exists("lodge.php")) {
        addnav("L?Return to the Lodge", "lodge.php");
    } else {
        require_once __DIR__ . "/lib/villagenav.php";
        villagenav();
    }
    output("You will automatically receive %s points for each person that you refer to this website who makes it to level %s.`n`n", $settings->getSetting("refereraward", 25), $settings->getSetting("referminlevel", 4));

    $url = $settings->getSetting(
        "serverurl",
        "http://" . $_SERVER['SERVER_NAME'] .
            ($_SERVER['SERVER_PORT'] == 80 ? "" : ":" . $_SERVER['SERVER_PORT']) .
            dirname($_SERVER['REQUEST_URI'])
    );
    if (!preg_match("/\/$/", $url)) {
        $url = $url . "/";
        $settings->saveSetting("serverurl", $url);
    }

    output("How does the site know that I referred a person?`n");
    output("Easy!  When you tell your friends about this site, give out the following link:`n`n");
    output_notl("%sreferral.php?r=%s`n`n", $url, rawurlencode($session['user']['login']));
    output("If you do, the site will know that you were the one who sent them here.");
    output("When they reach level %s for the first time, you'll get your points!", $settings->getSetting("referminlevel", 4));

    $sql = "SELECT name,level,refererawarded FROM " . Database::prefix("accounts") . " WHERE referer={$session['user']['acctid']} ORDER BY dragonkills,level";
    $result = Database::query($sql);
    $name = $translator->translateInline("Name");
    $level = $translator->translateInline("Level");
    $awarded = $translator->translateInline("Awarded?");
    $yes = $translator->translateInline("`@Yes!`0");
    $no = $translator->translateInline("`\$No!`0");
    $none = $translator->translateInline("`iNone`i");
    output("`n`nAccounts which you referred:`n");
    rawoutput("<table border='0' cellpadding='3' cellspacing='0'><tr><td>$name</td><td>$level</td><td>$awarded</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>");
        output_notl($row['name']);
        rawoutput("</td><td>");
        output_notl($row['level']);
        rawoutput("</td><td>");
        output_notl($row['refererawarded'] ? $yes : $no);
        rawoutput("</td></tr>");
    }
    if (Database::numRows($result) == 0) {
        rawoutput("<tr><td colspan='3' align='center'>");
        output_notl($none);
        rawoutput("</td></tr>");
    }
    rawoutput("</table>", true);
    page_footer();
} else {
    page_header("Welcome to Legend of the Green Dragon");
    output("`@Legend of the Green Dragon is a remake of the classic BBS Door Game Legend of the Red Dragon.");
    output("Adventure into the classic realm that was one of the world's very first multiplayer roleplaying games!");
    addnav("Create a character", "create.php?r=" . HTMLEntities(Http::get('r'), ENT_COMPAT, $settings->getSetting("charset", "UTF-8")));
    addnav("Login Page", "index.php");
    page_footer();
}
