<?php

declare(strict_types=1);

use Lotgd\Accounts;
use Lotgd\AddNews;
use Lotgd\Commentary;
use Lotgd\DataCache;
use Lotgd\Http;
use Lotgd\Motd;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Nltoappon;
use Lotgd\Security\Csrf;

// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";
$output = Output::getInstance();


Translator::getInstance()->setSchema("motd");
$settings = Settings::getInstance();

$op = Http::get('op');
$id = (int) Http::get('id');

Commentary::addCommentary();
Header::popupHeader("LoGD Message of the Day (MoTD)");

if ($session['user']['superuser'] & SU_POST_MOTD) {
    $addm = Translator::translateInline("Add MoTD");
    $addp = Translator::translateInline("Add Poll");
    $output->rawOutput(" [ <a href='motd.php?op=add'>$addm</a> | <a href='motd.php?op=addpoll'>$addp</a> ]<br/><br/>");
}

if ($op == "vote") {
    // POST is the trust boundary: validate each integer according to its value domain.
    $motditem = Motd::validatePollVoteIdentifier(Http::post('motditem'));
    $choice = Motd::validatePollVoteChoice(Http::post('choice'));
    $account = (int)($session['user']['acctid'] ?? 0);
    // The token check is its own clause: folded into the value checks it was
    // hard to see which of the eight conditions was the security one.
    if (
        !Csrf::validatePost(Csrf::SCOPE_MOTD_VOTE)
        || $motditem === null
        || $choice === null
        || $account <= 0
        || empty($session['user']['loggedin'])
    ) {
        debuglog('Rejected invalid or unauthorized MoTD poll vote request.');
        http_response_code(400);
        header("Location: motd.php");
        exit();
    }

    Motd::recordPollVote($motditem, $choice, $account);
    DataCache::getInstance()->invalidatedatacache("poll-$motditem");
    header("Location: motd.php");
    exit();
}
if (($op == "save" || $op == "savenew") && ($session['user']['superuser'] & SU_POST_MOTD)) {
    // SU_POST_MOTD says who may edit, not that this request was meant. Both
    // ops write, so both need the editing token and a POST.
    if (!Csrf::validatePostRequest(Csrf::SCOPE_MOTD_EDIT)) {
        debuglog('Rejected MoTD save with an invalid CSRF token.');
        http_response_code(400);
        header('Location: motd.php');
        exit();
    }
    if (Http::post('preview')) {
        $title = Http::post('motdtitle');
        $body = Nltoappon::convert((string) Http::post('motdbody'));
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
            // This was a plain GET link. The navigation allowlist narrowed it
            // -- the URL is only valid until the next page view consumes the
            // list -- but an admin sitting on the MoTD page who followed a
            // crafted link deleted the entry, because SameSite=Lax sends the
            // cookie on a top-level GET navigation.
            if (!Csrf::validatePostRequest(Csrf::SCOPE_MOTD_EDIT)) {
                debuglog('Rejected MoTD deletion with an invalid CSRF token.');
                http_response_code(400);
                header('Location: motd.php');
                exit();
            }
            Motd::motdDel($id);
            $output->output("`^Entry deleted.`0`n");
            $return = Translator::translateInline("Return to MoTD");
            $output->rawOutput("<a href='motd.php'>$return</a>");
            Nav::add('', 'motd.php');
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
    $count = $settings->getSetting("motditems", 5);
    $newcount = (int)Http::post("newcount");
    if ($newcount == 0 || Http::post('proceed') == '') {
        $newcount = 0;
    }
        /*
        Motd::motditem("Beta!","Please see the beta message below.","","", "");
        */
    $month_post = Http::post("month");
    if (!is_string($month_post)) {
        $month_post = '';
    }
    // This parameter was exploited in the past. The length cut plus an
    // unanchored pattern happened to be enough, but only by the interaction of
    // two independent lines: raise the limit and the hole is back. The pattern
    // is anchored and the values are bound below, so neither line is load
    // bearing on its own any more.
    $month_post = substr($month_post, 0, 7);
    if (preg_match('/^[0-9]{4}-[0-9]{2}$/', $month_post) !== 1) {
        //hack attack
        $month_post = "";
    }
    if ($month_post > "") {
        $date_array = explode("-", $month_post);
        $p_year = $date_array[0];
        $p_month = $date_array[1];
        $month_post_end = date("Y-m-t", strtotime($p_year . "-" . $p_month . "-" . "01")); // get last day of month this way, it's a valid DATETIME now
        $result = Database::getDoctrineConnection()->executeQuery(
            "SELECT " . Database::prefix("motd") . ".*,name AS motdauthorname FROM " . Database::prefix("motd")
                . " LEFT JOIN " . Database::prefix("accounts")
                . " ON " . Database::prefix("accounts") . ".acctid = " . Database::prefix("motd") . ".motdauthor"
                . " WHERE motddate >= :monthstart AND motddate <= :monthend ORDER BY motddate DESC",
            ['monthstart' => $month_post . '-01', 'monthend' => $month_post_end],
            ['monthstart' => ParameterType::STRING, 'monthend' => ParameterType::STRING]
        );
    } else {
        $sql = "SELECT " . Database::prefix("motd") . ".*,name AS motdauthorname FROM " . Database::prefix("motd") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("accounts") . ".acctid = " . Database::prefix("motd") . ".motdauthor ORDER BY motddate DESC limit $newcount," . ($newcount + $count);
        if ($newcount == 0) { //cache only the last x items
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
                            $row['motddate']
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
        $m = Translator::translateInline(date("M", $time));
        $output->rawOutput("<option value='{$row['d']}'" . ($month_post == $row['d'] ? " selected" : "") . ">$m" . date(", Y", $time) . " ({$row['c']})</option>");
    }
    $output->rawOutput("</select>" . Translator::clearButton());
    $showmore = Translator::translateInline("Show more");
    $output->rawOutput("<input type='hidden' name='newcount' value='" . ($count + $newcount) . "'>");
    $output->rawOutput("<input type='submit' value='$showmore' name='proceed'  class='button'>");
    $output->rawOutput(" <input type='submit' value='" . Translator::translateInline("Submit") . "' class='button'>");
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

Footer::popupFooter();
