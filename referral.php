<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Settings;
use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;

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
    Header::pageHeader("Referral Page");
    if (file_exists("lodge.php")) {
        Nav::add("L?Return to the Lodge", "lodge.php");
    } else {
        VillageNav::render();
    }
    $output->output("You will automatically receive %s points for each person that you refer to this website who makes it to level %s.`n`n", $settings->getSetting("refereraward", 25), $settings->getSetting("referminlevel", 4));

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

    $output->output("How does the site know that I referred a person?`n");
    $output->output("Easy!  When you tell your friends about this site, give out the following link:`n`n");
    $output->outputNotl("%sreferral.php?r=%s`n`n", $url, rawurlencode($session['user']['login']));
    $output->output("If you do, the site will know that you were the one who sent them here.");
    $output->output("When they reach level %s for the first time, you'll get your points!", $settings->getSetting("referminlevel", 4));

    $sql = "SELECT name,level,refererawarded FROM " . Database::prefix("accounts") . " WHERE referer={$session['user']['acctid']} ORDER BY dragonkills,level";
    $result = Database::query($sql);
    $name = $translator->translateInline("Name");
    $level = $translator->translateInline("Level");
    $awarded = $translator->translateInline("Awarded?");
    $yes = $translator->translateInline("`@Yes!`0");
    $no = $translator->translateInline("`\$No!`0");
    $none = $translator->translateInline("`iNone`i");
    $output->output("`n`nAccounts which you referred:`n");
    $output->rawOutput("<table border='0' cellpadding='3' cellspacing='0'><tr><td>$name</td><td>$level</td><td>$awarded</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>");
        $output->outputNotl($row['name']);
        $output->rawOutput("</td><td>");
        $output->outputNotl($row['level']);
        $output->rawOutput("</td><td>");
        $output->outputNotl($row['refererawarded'] ? $yes : $no);
        $output->rawOutput("</td></tr>");
    }
    if (Database::numRows($result) == 0) {
        $output->rawOutput("<tr><td colspan='3' align='center'>");
        $output->outputNotl($none);
        $output->rawOutput("</td></tr>");
    }
    $output->rawOutput("</table>", true);
    Footer::pageFooter();
} else {
    Header::pageHeader("Welcome to Legend of the Green Dragon");
    $output->output("`@Legend of the Green Dragon is a remake of the classic BBS Door Game Legend of the Red Dragon.");
    $output->output("Adventure into the classic realm that was one of the world's very first multiplayer roleplaying games!");
    Nav::add("Create a character", "create.php?r=" . HTMLEntities(Http::get('r'), ENT_COMPAT, $settings->getSetting("charset", "UTF-8")));
    Nav::add("Login Page", "index.php");
    Footer::pageFooter();
}
