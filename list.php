<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;

// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS", true);
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav\VillageNav;
use Lotgd\Nav;
use Lotgd\DateTime;
use Lotgd\Output;

require_once __DIR__ . "/common.php";
$output = Output::getInstance();

Translator::getInstance()->setSchema("list");

Header::pageHeader("List Warriors");
if ($session['user']['loggedin']) {
    DateTime::checkDay();
    if ($session['user']['alive']) {
        VillageNav::render();
    } else {
        Nav::add("Return to the Graveyard", "graveyard.php");
    }
    Nav::add("Currently Online", "list.php");
    if ($session['user']['clanid'] > 0) {
        Nav::add("Online Clan Members", "list.php?op=clan");
        if ($session['user']['alive']) {
            Nav::add("Clan Hall", "clan.php");
        }
    }
} else {
    Nav::add("Login Screen", "index.php");
    Nav::add("Currently Online", "list.php");
}

$playersperpage = 50;

$sql = "SELECT count(acctid) AS c FROM " . Database::prefix("accounts") . " WHERE locked=0";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$totalplayers = $row['c'];

$op = Http::get('op');
$page = Http::get('page');
$search = '';
$limit = '';

if ($op == "search") {
    $rawName = Http::post('name');
    $n = '';

    if (is_string($rawName)) {
        $n = Database::escape($rawName);
    }

    if ($n !== '') {
        $search = "%";
        for ($x = 0; $x < strlen($n); $x++) {
            $search .= substr($n, $x, 1) . "%";
        }
        $search = " AND name LIKE '" . addslashes($search) . "' ";
    } else {
        $op = "";
    }
}

if ($op !== "search") {
    $pageoffset = (int)$page;
    if ($pageoffset > 0) {
        $pageoffset--;
    }
    $pageoffset *= $playersperpage;
    $from = $pageoffset + 1;
    $to = min($pageoffset + $playersperpage, $totalplayers);

    $limit = " LIMIT $pageoffset,$playersperpage ";
}
if (getsetting('listonlyonline', 1) == 0 || (getsetting('listonlyonline', 1) == 1 && $session['user']['loggedin'])) {
    Nav::add("Pages");
    for ($i = 0; $i < $totalplayers; $i += $playersperpage) {
        $pnum = $i / $playersperpage + 1;
        if ($page == $pnum) {
            Nav::add(array(" ?`b`#Page %s`0 (%s-%s)`b", $pnum, $i + 1, min($i + $playersperpage, $totalplayers)), "list.php?page=$pnum");
        } else {
            Nav::add(array(" ?Page %s (%s-%s)", $pnum, $i + 1, min($i + $playersperpage, $totalplayers)), "list.php?page=$pnum");
        }
    }
}
// Order the list by level, dragonkills, name so that the ordering is total!
// Without this, some users would show up on multiple pages and some users
// wouldn't show up
$remove_offline = true;
if ($page == "" && $op == "") {
    $title = translate_inline("Warriors Currently Online");
    $sql = "SELECT acctid,name,login,alive,location,race,sex,level,laston,loggedin,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'" . date("Y-m-d H:i:s", strtotime("-" . getsetting("LOGINTIMEOUT", 900) . " seconds")) . "' ORDER BY level DESC, dragonkills DESC, login ASC";
    $result = Database::queryCached($sql, "list.php-warsonline");
} elseif ($op == 'clan') {
    if (empty($session['user']['clanid'])) {
        // User is not part of a clan; redirect to the main list.
        redirect('list.php');
    }
    $clanId = (int) $session['user']['clanid'];

    $title = translate_inline("Clan Members Online");
    $sql = "SELECT acctid,name,login,alive,location,race,sex,level,laston,loggedin,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'" . date("Y-m-d H:i:s", strtotime("-" . getsetting("LOGINTIMEOUT", 900) . " seconds")) . "' AND clanid='{$clanId}' ORDER BY level DESC, dragonkills DESC, login ASC";
    $result = Database::query($sql);
} else {
    $remove_offline = false;
    if ($totalplayers > $playersperpage && $op != "search") {
        $title = Translator::getInstance()->sprintfTranslate("Warriors of the realm (Page %s: %s-%s of %s)", ($pageoffset / $playersperpage + 1), $from, $to, $totalplayers);
    } else {
        $title = Translator::getInstance()->sprintfTranslate("Warriors of the realm");
    }
    $output->rawOutput(Translator::clearButton());
    $sql = "SELECT acctid,name,login,alive,hitpoints,location,race,sex,level,laston,loggedin,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE locked=0 $search ORDER BY level DESC, dragonkills DESC, login ASC $limit";
    $result = Database::query($sql);
}
if ($session['user']['loggedin']) {
    $search = translate_inline("Search by name: ");
    $search2 = translate_inline("Search");

    $output->rawOutput("<form action='list.php?op=search' method='POST'>$search<input name='name'><input type='submit' class='button' value='$search2'></form>");
    Nav::add("", "list.php?op=search");
}

$max = Database::numRows($result);
if ($max > getsetting("maxlistsize", 100)) {
    $output->output("`\$Too many names match that search.  Showing only the first %s.`0`n", getsetting("maxlistsize", 100));
    $max = getsetting("maxlistsize", 100);
}

// prepare for hook
$rows = array();
while ($row = Database::fetchAssoc($result)) {
    $rows[] = $row;
}
// cut to max size
$rows = array_slice($rows, 0, $max);

// let modules modify the data before we display it
$rows = modulehook("warriorlist", $rows);

if ($page == "" && $op == "") {
    // Count how many warriors are online by the loggedin field in the $rows array
    $loggedin = 0;
    foreach ($rows as $row) {
        if ($row['loggedin']) {
            $loggedin++;
        }
    }
    $title .= Translator::getInstance()->sprintfTranslate(" (%s warriors online)", $loggedin);
}
$output->outputNotl("`c`b" . $title . "`b");

$alive = translate_inline("Alive");
$level = translate_inline("Level");
$name = translate_inline("Name");
$loc = translate_inline("Location");
$race = translate_inline("Race");
$sex = translate_inline("Sex");
$last = translate_inline("Last On");

$output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>", true);
$output->rawOutput("<tr class='trhead'><td>$alive</td><td>$level</td><td>$name</td><td>$loc</td><td>$race</td><td>$sex</td><td>$last</tr>");
$writemail = translate_inline("Write Mail");
$alive = translate_inline("`1Yes`0");
$dead = translate_inline("`4No`0");
$unconscious = translate_inline("`6Unconscious`0");

foreach ($rows as $i => $row) {
    if ($remove_offline === true && !$row['loggedin']) {
        continue;
    }
    $output->rawOutput("<tr class='" . ($i % 2 ? "trdark" : "trlight") . "'><td>", true);
    if ($row['alive'] == true) {
        $a = $alive;
    } elseif (isset($row['hitpoints']) && $row['hitpoints'] > 0) {
        $a = $unconscious;
    } else {
        $a = $dead;
    }
    //$a = translate_inline($row['alive']?"`1Yes`0":"`4No`0");
    $output->outputNotl("%s", $a);
    $output->rawOutput("</td><td>");
    $output->outputNotl("`^%s`0", $row['level']);
    $output->rawOutput("</td><td>");
    if ($session['user']['loggedin']) {
        $output->rawOutput("<a href=\"mail.php?op=write&to=" . rawurlencode($row['login']) . "\" target=\"_blank\" onClick=\"" . popup("mail.php?op=write&to=" . rawurlencode($row['login']) . "") . ";return false;\">");
        $output->rawOutput("<img src='images/newscroll.GIF' width='16' height='16' alt='$writemail' border='0'></a>");
        $output->rawOutput("<a href='bio.php?char=" . $row['acctid'] . "'>");
        Nav::add("", "bio.php?char=" . $row['acctid'] . "");
    }
    $output->outputNotl("`&%s`0", $row['name']);
    if ($session['user']['loggedin']) {
        $output->rawOutput("</a>");
    }
    $output->rawOutput("</td><td>");
    $loggedin = (date("U") - strtotime($row['laston']) < getsetting("LOGINTIMEOUT", 900) && $row['loggedin']);
    $output->outputNotl("`&%s`0", $row['location']);
    if ($loggedin) {
        $online = translate_inline("`#(Online)");
        $output->outputNotl("%s", $online);
    }
    $output->rawOutput("</td><td>");
    if (!$row['race']) {
        $row['race'] = RACE_UNKNOWN;
    }
    Translator::getInstance()->setSchema("race");
    $output->output($row['race']);
    Translator::getInstance()->setSchema();
    $output->rawOutput("</td><td>");
    $sex = translate_inline($row['sex'] ? "`%Female`0" : "`!Male`0");
    $output->outputNotl("%s", $sex);
    $output->rawOutput("</td><td>");
    $laston = DateTime::relativeDate($row['laston']);
    $output->outputNotl("%s", $laston);
    $output->rawOutput("</td></tr>");
}
$output->rawOutput("</table>");
$output->outputNotl("`c");
Footer::pageFooter();
