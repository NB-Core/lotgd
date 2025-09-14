<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Commentary;
use Lotgd\Accounts;
use Lotgd\Output;
use Lotgd\DataCache;

// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";
$output = Output::getInstance();
require_once __DIR__ . "/lib/nltoappon.php";
require_once __DIR__ . "/lib/http.php";
use Lotgd\Motd;

Translator::getInstance()->setSchema("motd");

$op = httpget('op');
$id = httpget('id');

Commentary::addCommentary();
popup_header("LoGD Message of the Day (MoTD)");

if ($session['user']['superuser'] & SU_POST_MOTD) {
    $addm = translate_inline("Add MoTD");
    $addp = translate_inline("Add Poll");
    $output->rawOutput(" [ <a href='motd.php?op=add'>$addm</a> | <a href='motd.php?op=addpoll'>$addp</a> ]<br/><br/>");
}

if ($op == "vote") {
    $motditem = httppost('motditem');
    $choice = (string)httppost('choice');
    $sql = "DELETE FROM " . Database::prefix("pollresults") . " WHERE motditem='$motditem' AND account='{$session['user']['acctid']}'";
    Database::query($sql);
    $sql = "INSERT INTO " . Database::prefix("pollresults") . " (choice,account,motditem) VALUES ('$choice','{$session['user']['acctid']}','$motditem')";
    Database::query($sql);
    DataCache::getInstance()->invalidatedatacache("poll-$motditem");
    header("Location: motd.php");
    exit();
}
if (($op == "save" || $op == "savenew") && ($session['user']['superuser'] & SU_POST_MOTD)) {
    if (httppost('preview')) {
        $title = httppost('motdtitle');
        $body = nltoappon((string) httppost('motdbody'));
        Motd::motdItem($title, $body, $session['user']['name'], date('Y-m-d H:i:s'), (int) $id);
        Motd::motdForm((int) $id, $_POST);
    } else {
        if ($op == "save") {
            Motd::saveMotd((int) $id);
        } else {
            Motd::savePoll();
        }
        header("Location: motd.php");
        exit();
    }
}
if ($op == "add" || $op == "addpoll" || $op == "del") {
    if ($session['user']['superuser'] & SU_POST_MOTD) {
        if ($op == "add") {
            Motd::motdForm($id);
        } elseif ($op == "addpoll") {
            Motd::motdPollForm($id);
        } elseif ($op == "del") {
            Motd::motdDel($id);
            $output->output("`^Entry deleted.`0`n");
            $return = translate_inline("Return to MoTD");
            $output->rawOutput("<a href='motd.php'>$return</a>");
            addnav('', 'motd.php');
        }
    } else {
        if ($session['user']['loggedin']) {
            $session['user']['experience'] = round($session['user']['experience'] * 0.9, 0);
            AddNews::add(
                "%s was penalized for attempting to defile the gods.",
                $session['user']['name']
            );
            $output->output("You've attempted to defile the gods.  You are struck with a wand of forgetfulness.  Some of what you knew, you no longer know.");
            Accounts::saveUser();
        }
    }
}
if ($op == "") {
    $count = getsetting("motditems", 5);
    $newcount = (int)httppost("newcount");
    if ($newcount == 0 || httppost('proceed') == '') {
        $newcount = 0;
    }
        /*
        Motd::motditem("Beta!","Please see the beta message below.","","", "");
        */
    $month_post = httppost("month");
    //SQL Injection attack possible -> kill it off after 7 letters as format is i.e. "2000-05"
    $month_post = substr($month_post, 0, 7);
    if (preg_match("/[0-9][0-9][0-9][0-9]-[0-9][0-9]/", $month_post) !== 1) {
        //hack attack
        $month_post = "";
    }
    if ($month_post > "") {
        $date_array = explode("-", $month_post);
        $p_year = $date_array[0];
        $p_month = $date_array[1];
        $month_post_end = date("Y-m-t", strtotime($p_year . "-" . $p_month . "-" . "01")); // get last day of month this way, it's a valid DATETIME now
        $sql = "SELECT " . Database::prefix("motd") . ".*,name AS motdauthorname FROM " . Database::prefix("motd") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("accounts") . ".acctid = " . Database::prefix("motd") . ".motdauthor WHERE motddate >= '{$month_post}-01' AND motddate <= '{$month_post_end}' ORDER BY motddate DESC";
                $result = Database::queryCached($sql, "motd-$month_post");
                $result = Database::query($sql);
    } else {
        $sql = "SELECT " . Database::prefix("motd") . ".*,name AS motdauthorname FROM " . Database::prefix("motd") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("accounts") . ".acctid = " . Database::prefix("motd") . ".motdauthor ORDER BY motddate DESC limit $newcount," . ($newcount + $count);
        if ($newcount = 0) { //cache only the last x items
            $result = Database::queryCached($sql, "motd");
        } else {
            $result = Database::query($sql);
        }
    }
    while ($row = Database::fetchAssoc($result)) {
        if (!isset($session['user']['lastmotd'])) {
            $session['user']['lastmotd'] = DATETIME_DATEMIN;
        }
        if ($row['motdauthorname'] == "") {
            $row['motdauthorname'] = "`@Green Dragon Staff`0";
        }
        if ($row['motdtype'] == 0) {
                        Motd::motditem(
                            $row['motdtitle'],
                            $row['motdbody'],
                            $row['motdauthorname'],
                            $row['motddate'],
                            $row['motditem']
                        );
        } else {
                        Motd::pollitem(
                            $row['motditem'],
                            $row['motdtitle'],
                            $row['motdbody'],
                            $row['motdauthorname'],
                            $row['motddate'],
                            $row['motditem']
                        );
        }
    }
    /*
        Motd::motditem("Beta!","For those who might be unaware, this website is still in beta mode.  I'm working on it when I have time, which generally means a couple of changes a week.  Feel free to drop suggestions, I'm open to anything :-)","","", "");
    */

    $result = Database::query("SELECT mid(motddate,1,7) AS d, count(*) AS c FROM " . Database::prefix("motd") . " GROUP BY d ORDER BY d DESC");
    $row = Database::fetchAssoc($result);
    $output->rawOutput("<form action='motd.php' method='POST'>");
        $output->rawOutput("<label for='month'>");
        $output->output("MoTD Archives:");
        $output->rawOutput("</label>");
        $output->rawOutput("<select name='month' id='month' onChange='this.form.submit();' >");
    $output->rawOutput("<option value=''>--Current--</option>");
    while ($row = Database::fetchAssoc($result)) {
        $time = strtotime("{$row['d']}-01");
        $m = translate_inline(date("M", $time));
        $output->rawOutput("<option value='{$row['d']}'" . ($month_post == $row['d'] ? " selected" : "") . ">$m" . date(", Y", $time) . " ({$row['c']})</option>");
    }
    $output->rawOutput("</select>" . Translator::clearButton());
    $showmore = translate_inline("Show more");
    $output->rawOutput("<input type='hidden' name='newcount' value='" . ($count + $newcount) . "'>");
    $output->rawOutput("<input type='submit' value='$showmore' name='proceed'  class='button'>");
    $output->rawOutput(" <input type='submit' value='" . translate_inline("Submit") . "' class='button'>");
    $output->rawOutput("</form>");

    Commentary::commentDisplay("`n`@Commentary:`0`n", "motd");
}

$session['needtoviewmotd'] = false;

$sql = "SELECT motddate FROM " . Database::prefix("motd") . " ORDER BY motditem DESC LIMIT 1";
$result = Database::queryCached($sql, "motddate");
$row = Database::fetchAssoc($result);

if ($row && isset($row['motddate'])) {
    $session['user']['lastmotd'] = $row['motddate'];
} else {
    // Fallback for empty `motd` tables during first-time installations.
    $session['user']['lastmotd'] = '1970-01-01 00:00:00';
}

popup_footer();
