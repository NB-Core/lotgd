<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
// translator ready
// addnews ready
// mail ready

// New Hall of Fame features by anpera
// http://www.anpera.net/forum/viewforum.php?f=27

use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav\VillageNav;
use Lotgd\Nav;
use Lotgd\DateTime;

require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("hof");

$superusermask = SU_HIDE_FROM_LEADERBOARD;
$standardwhere = "(locked=0 AND (superuser & $superusermask) = 0)";

Header::pageHeader("Hall of Fame");
DateTime::checkDay();

Nav::add("Navigation");
VillageNav::render();

$playersperpage = 50;

$op = Http::get('op');
if ($op == "") {
    $op = "kills";
}
$subop = Http::get('subop');
if ($subop == "") {
    $subop = "most";
}

$sql = "SELECT count(acctid) AS c FROM " . Database::prefix("accounts") . " WHERE $standardwhere";
$extra = "";
if ($op == "kills") {
    $extra = " AND dragonkills > 0";
} elseif ($op == "days") {
    $extra = " AND dragonkills > 0 AND bestdragonage > 0";
}
$result = Database::query($sql . $extra);
$row = Database::fetchAssoc($result);
$totalplayers = $row['c'];

$page = (int) Http::get('page');
if ($page == 0) {
    $page = 1;
}
$pageoffset = $page;
if ($pageoffset > 0) {
    $pageoffset--;
}
$pageoffset *= $playersperpage;
$from = $pageoffset + 1;
$to = min($pageoffset + $playersperpage, $totalplayers);
$limit = "$pageoffset,$playersperpage";
$me = ''; //query at the end

Nav::add("Warrior Rankings");
Nav::add("Dragon Kills", "hof.php?op=kills&subop=$subop&page=1");
Nav::add("Gold", "hof.php?op=money&subop=$subop&page=1");
Nav::add("Gems", "hof.php?op=gems&subop=$subop&page=1");
Nav::add("Charm", "hof.php?op=charm&subop=$subop&page=1");
Nav::add("Toughness", "hof.php?op=tough&subop=$subop&page=1");
Nav::add("Resurrections", "hof.php?op=resurrects&subop=$subop&page=1");
Nav::add("Dragon Kill Speed", "hof.php?op=days&subop=$subop&page=1");
Nav::add("Sorting");
Nav::add("Best", "hof.php?op=$op&subop=most&page=$page");
Nav::add("Worst", "hof.php?op=$op&subop=least&page=$page");
Nav::add("Other Stats");
modulehook("hof-add", array());
if ($totalplayers > $playersperpage) {
    Nav::add("Pages");
    for ($i = 0; $i < $totalplayers; $i += $playersperpage) {
        $pnum = ($i / $playersperpage + 1);
        $min = ($i + 1);
        $max = min($i + $playersperpage, $totalplayers);
        if ($page == $pnum) {
            Nav::add(array("`b`#Page %s`0 (%s-%s)`b", $pnum, $min, $max), "hof.php?op=$op&subop=$subop&page=$pnum");
        } else {
            Nav::add(array("Page %s (%s-%s)", $pnum, $min, $max), "hof.php?op=$op&subop=$subop&page=$pnum");
        }
    }
}

function display_table(
    $title,
    $sql,
    $none = false,
    $foot = false,
    $data_header = false,
    $tag = false,
    $translate = false
) {
    global $session, $from, $to, $page, $playersperpage, $totalplayers, $output;

    $title = translate_inline($title);
    if ($foot !== false) {
        $foot = translate_inline($foot);
    }
    if ($none !== false) {
        $none = translate_inline($none);
    } else {
        $none = translate_inline("No players found.");
    }
    if ($data_header !== false) {
        $data_header = translate_inline($data_header);
        reset($data_header);
    }
    if ($tag !== false) {
        $tag = translate_inline($tag);
    }
    $rank = translate_inline("Rank");
    $name = translate_inline("Name");

    if ($totalplayers > $playersperpage) {
        $output->output("`c`b`^%s`0`b `7(Page %s: %s-%s of %s)`0`c`n", $title, $page, $from, $to, $totalplayers);
    } else {
        $output->output("`c`b`^%s`0`b`c`n", $title);
    }
    $output->rawOutput("<table cellspacing='0' cellpadding='2' align='center'>");
    $output->rawOutput("<tr class='trhead'>");
    $output->outputNotl("<td>`b$rank`b</td><td>`b$name`b</td>", true);
    if ($data_header !== false) {
        for ($i = 0; $i < count($data_header); $i++) {
            $output->outputNotl("<td>`b{$data_header[$i]}`b</td>", true);
        }
    }
    $result = Database::query($sql);
    if (Database::numRows($result) == 0) {
        $size = ($data_header === false) ? 2 : 2 + count($data_header);
        $output->outputNotl("<tr class='trlight'><td colspan='$size' align='center'>`&$none`0</td></tr>", true);
    } else {
        $i = -1;
        while ($row = Database::fetchAssoc($result)) {
            $i++;
            if ($row['name'] == $session['user']['name']) {
                $output->rawOutput("<tr class='hilight'>");
            } else {
                $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
            }
            $output->outputNotl("<td>%s</td><td>`&%s`0</td>", ($i + $from), $row['name'], true);
            if ($data_header !== false) {
                for ($j = 0; $j < count($data_header); $j++) {
                    $id = "data" . ($j + 1);
                    $val = $row[$id];
                    if (
                        isset($translate[$id]) &&
                            $translate[$id] == 1 && !is_numeric($val)
                    ) {
                        $val = translate_inline($val);
                    }
                    if ($tag !== false) {
                        $val = $val . " " . $tag[$j];
                    }
                    $output->outputNotl("<td align='right'>%s</td>", $val, true);
                }
            }
            $output->rawOutput("</tr>");
        }
    }
    $output->rawOutput("</table>");
    if ($foot !== false) {
        $output->outputNotl("`n`c%s`c", $foot);
    }
}

if ($op == "days") {
    if ($subop == "least") {
        $order = "DESC";
        $meop = ">=";
    } else {
        $order = "ASC";
        $meop = "<=";
    }
} else {
    if ($subop == "least") {
        $order = "ASC";
        $meop = "<=";
    } else {
        $order = "DESC";
        $meop = ">=";
    }
}


$sexsel = "IF(sex,'`%Female`0','`!Male`0')";
$racesel = "IF(race!='0' and race!='',race,'" . RACE_UNKNOWN . "')";

//round modifier for gold   - equals left side of , rounding
//              + equals right side of , rounding
$round_money = "-2";

if ($op == "money") {
    // works only in mysql 5+ due to the derived table stuff
    $sql = "SELECT name,(round(
						(CAST(goldinbank as signed)+cast(gold as signed))
						*(1+0.05*(rand())),$round_money
						)) as sort1 
                FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY sort1 $order, level $order, experience $order, acctid $order LIMIT $limit";
    // for formatting, we need another query...
    $sql = "SELECT name,format(sort1,0) as data1 FROM ($sql) t";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere
                AND round((CAST(goldinbank as signed)+cast(gold as signed))*(1+0.05*(rand())),$round_money)
                $meop " . ($session['user']['goldinbank'] + $session['user']['gold']);
    //edward pointed out that a cast is necessary as signed+unsigned=boffo
//  $sql = "SELECT name,(goldinbank+gold+round((((rand()*10)-5)/100)*(goldinbank+gold))) AS data1 FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY data1 $order, level $order, experience $order, acctid $order LIMIT $limit";
//  $me = "SELECT count(acctid) AS count FROM ".Database::prefix("accounts")." WHERE $standardwhere AND (goldinbank+gold+round((((rand()*10)-5)/100)*(goldinbank+gold))) $meop ".($session['user']['goldinbank'] + $session['user']['gold']);
    $adverb = "richest";
    if ($subop == "least") {
        $adverb = "poorest";
    }
    $title = "The $adverb warriors in the land";
    $foot = "(Gold Amount is accurate to +/- 5%)";
    $headers = array("Estimated Gold");
    $tags = array("gold");
    $table = array($title, $sql, false, $foot, $headers, $tags);
} elseif ($op == "gems") {
    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY gems $order, level $order, experience $order, acctid $order LIMIT $limit";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere AND gems $meop {$session['user']['gems']}";
    if ($subop == "least") {
        $adverb = "least";
    } else {
        $adverb = "most";
    }
    $title = "The warriors with the $adverb gems in the land";
    $table = array($title, $sql);
} elseif ($op == "charm") {
    $sql = "SELECT name,$sexsel AS data1, $racesel AS data2 FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY charm $order, level $order, experience $order, acctid $order LIMIT $limit";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere AND charm $meop {$session['user']['charm']}";
    $adverb = "most beautiful";
    if ($subop == "least") {
        $adverb = "ugliest";
    }
    $title = "The $adverb warriors in the land.";
    $headers = array("Gender", "Race");
    $translate = array("data1" => 1, "data2" => 1);
    $table = array($title, $sql, false, false, $headers, false, $translate);
} elseif ($op == "tough") {
    $sql = "SELECT name,level AS data2 , $racesel as data1 FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY maxhitpoints $order, level $order, experience $order, acctid $order LIMIT $limit";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere AND maxhitpoints $meop {$session['user']['maxhitpoints']}";
    $adverb = "toughest";
    if ($subop == "least") {
        $adverb = "wimpiest";
    }
    $title = "The $adverb warriors in the land";
    $headers = array("Race", "Level");
    $translate = array("data1" => 1);
    $table = array($title, $sql, false, false, $headers, false, $translate);
} elseif ($op == "resurrects") {
    $sql = "SELECT name,level AS data1 FROM " . Database::prefix("accounts") . " WHERE $standardwhere ORDER BY resurrections $order, level $order, experience $order, acctid $order LIMIT $limit";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere AND resurrections $meop {$session['user']['resurrections']}";
    $adverb = "most suicidal";
    if ($subop == "least") {
        $adverb = "least suicidal";
    }
    $title = "The $adverb warriors in the land";
    $headers = array("Level");
    $table = array($title, $sql, false, false, $headers, false);
} elseif ($op == "days") {
    $unk = translate_inline("Unknown");
    $sql = "SELECT name, IF(bestdragonage,bestdragonage,'$unk') AS data1 FROM " . Database::prefix("accounts") . " WHERE $standardwhere $extra ORDER BY bestdragonage $order, level $order, experience $order, acctid $order LIMIT $limit";
    $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere $extra AND bestdragonage $meop {$session['user']['bestdragonage']}";
    $adverb = "fastest";
    if ($subop == "least") {
        $adverb = "slowest";
    }
    $title = "Heroes with the $adverb dragon kills in the land";
    $headers = array("Best Days");
    $none = "There are no heroes in the land.";
    $table = array($title, $sql, $none, false, $headers, false);
} else {
    $unk = translate_inline("Unknown");
    $sql = "SELECT name,dragonkills AS data1,level AS data2,'&nbsp;' AS data3, IF(dragonage,dragonage,'$unk') AS data4, '&nbsp;' AS data5, IF(bestdragonage,bestdragonage,'$unk') AS data6 FROM " . Database::prefix("accounts") . " WHERE $standardwhere $extra ORDER BY dragonkills $order,level $order,experience $order, acctid $order LIMIT $limit";
    if ($session['user']['dragonkills'] > 0) {
        $me = "SELECT count(acctid) AS count FROM " . Database::prefix("accounts") . " WHERE $standardwhere $extra AND dragonkills $meop {$session['user']['dragonkills']}";
    }
    $adverb = "most";
    if ($subop == "least") {
        $adverb = "least";
    }
    $title = "Heroes with the $adverb dragon kills in the land";
    $headers = array("Kills", "Level", "&nbsp;", "Days", "&nbsp;", "Best Days");
    $none = "There are no heroes in the land.";
    $table = array($title, $sql, $none, false, $headers, false);
}

if (isset($table) && is_array($table)) {
    call_user_func_array("display_table", $table);
    if ($me > "" && $totalplayers) {
        $meresult = Database::query($me);
        $row = Database::fetchAssoc($meresult);
        $pct = round(100 * $row['count'] / $totalplayers, 0);
        if ($pct < 1) {
            $pct = 1;
        }
        $output->output("`c`7You rank within around the top `&%s`7%% in this listing.`0`c", $pct);
    }
}

Footer::pageFooter();
