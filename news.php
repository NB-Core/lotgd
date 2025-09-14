<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Motd;
use Lotgd\Battle;
use Lotgd\Output;

// translator ready
// addnews ready
// mail ready
define("ALLOW_ANONYMOUS", true);
require_once __DIR__ . "/common.php";
$output = Output::getInstance();
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/villagenav.php";

$translator = Translator::getInstance();

$translator->setSchema("news");

modulehook("news-intercept", array());


if ($session['user']['loggedin']) {
    checkday();
}
$newsperpage = 50;

$offset = (int)httpget('offset');
$timestamp = strtotime((0 - $offset) . " days");
$sql = "SELECT count(newsid) AS c FROM " . Database::prefix("news") . " WHERE newsdate='" . date("Y-m-d", $timestamp) . "'";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$totaltoday = $row['c'];
$page = (int)httpget('page');
if (!$page) {
    $page = 1;
}
$pageoffset = $page;
if ($pageoffset > 0) {
    $pageoffset--;
}
$pageoffset *= $newsperpage;
$sql = "SELECT * FROM " . Database::prefix("news") . " WHERE newsdate='" . date("Y-m-d", $timestamp) . "' ORDER BY newsid DESC LIMIT $pageoffset,$newsperpage";
$result = Database::query($sql);
page_header("LoGD News");
$date = date("D, M j, Y", $timestamp);

$pagestr = "";
if ($totaltoday > $newsperpage) {
    $pagestr = $translator->sprintfTranslate(
        "(Items %s - %s of %s)",
        $pageoffset + 1,
        min($pageoffset + $newsperpage, $totaltoday),
        $totaltoday
    );
}

$sql2 = "SELECT " . Database::prefix("motd") . ".*,name AS motdauthorname FROM " . Database::prefix("motd") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("accounts") . ".acctid = " . Database::prefix("motd") . ".motdauthor ORDER BY motddate DESC LIMIT 1";
$result2 = Database::queryCached($sql2, "lastmotd");
while ($row = Database::fetchAssoc($result2)) {
        require_once __DIR__ . "/lib/nltoappon.php";
    if ($row['motdauthorname'] == "") {
        $row['motdauthorname'] = translate_inline("`@Green Dragon Staff`0");
    }
    if ($row['motdtype'] == 0) {
            Motd::motditem($row['motdtitle'], $row['motdbody'], $output->appoencode($row['motdauthorname']), $row['motddate'], 0);
    } else {
            Motd::pollitem($row['motditem'], $row['motdtitle'], $row['motdbody'], $row['motdauthorname'], $row['motddate'], false);
    }
}
 $output->outputNotl("`n");
$output->output("`c`b`!News for %s %s`0`b`c", $date, $pagestr);

while ($row = Database::fetchAssoc($result)) {
    $output->outputNotl("`c`2-=-`@=-=`2-=-`@=-=`2-=-`@=-=`2-=-`0`c");
    if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
        $del = translate_inline("Del");
        $output->rawOutput("[ <a href='superuser.php?op=newsdelete&newsid=" . $row['newsid'] . "&return=" . URLEncode($_SERVER['REQUEST_URI']) . "'>$del</a> ]&nbsp;");
        addnav("", "superuser.php?op=newsdelete&newsid={$row['newsid']}&return=" . URLEncode($_SERVER['REQUEST_URI']));
    }
    $translator->setSchema($row['tlschema']);
    if ($row['arguments'] > "") {
        $arguments = array();
        $base_arguments = unserialize($row['arguments']);
        array_push($arguments, $row['newstext']);
        foreach ($base_arguments as $val) {
            array_push($arguments, $val);
        }
        $news = $translator->sprintfTranslate(...$arguments);
        $output->rawOutput(Translator::clearButton());
    } else {
        $news = translate_inline($row['newstext']);
    }
    $translator->setSchema();
    $output->outputNotl("`c" . $news . "`c`n");
}
if (Database::numRows($result) == 0) {
    $output->outputNotl("`c`2-=-`@=-=`2-=-`@=-=`2-=-`@=-=`2-=-`0`c");
    $output->output("`1`b`c Nothing of note happened this day.  All in all a boring day. `c`b`0");
}
 $output->outputNotl("`c`2-=-`@=-=`2-=-`@=-=`2-=-`@=-=`2-=-`0`c");
if (!$session['user']['loggedin']) {
    addnav("Login Screen", "index.php");
} elseif ($session['user']['alive']) {
    villagenav();
} else {
    $translator->setSchema("nav");
    addnav("Log Out");
    addnav("Log out", "login.php?op=logout");

    if ($session['user']['sex'] == 1) {
        addnav("`!`bYou're dead, Jane!`b`0");
    } else {
        addnav("`!`bYou're dead, Jim!`b`0");
    }
    addnav("S?Land of Shades", "shades.php");
    addnav("G?The Graveyard", "graveyard.php");
        Battle::suspendCompanions("allowinshades", true);
    $translator->setSchema();
}
addnav("News");
addnav("Previous News", "news.php?offset=" . ($offset + 1));
if ($offset > 0) {
    addnav("Next News", "news.php?offset=" . ($offset - 1));
}
if ($session['user']['loggedin']) {
    addnav("Preferences", "prefs.php");
}
addnav("About this game", "about.php");

$translator->setSchema("nav");
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    addnav("Superuser");
    addnav(",?Comment Moderation", "moderate.php");
}
if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
    addnav("Superuser");
    addnav("X?Superuser Grotto", "superuser.php");
}
if ($session['user']['superuser'] & SU_INFINITE_DAYS) {
    addnav("Superuser");
    addnav("/?New Day", "newday.php");
}
$translator->setSchema();

addnav("", "news.php");
if ($totaltoday > $newsperpage) {
    addnav("Today's news");
    for ($i = 0; $i < $totaltoday; $i += $newsperpage) {
        $pnum = $i / $newsperpage + 1;
        if ($pnum == $page) {
            addnav(array("`b`#Page %s`0`b", $pnum), "news.php?offset=$offset&page=$pnum");
        } else {
            addnav(array("Page %s", $pnum), "news.php?offset=$offset&page=$pnum");
        }
    }
}

page_footer();
