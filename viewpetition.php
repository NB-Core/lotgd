<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready

require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema('petition');

SuAccess::check(SU_EDIT_PETITIONS);

Commentary::addCommentary();

SuperuserNav::render();

//WHEN 0 THEN 2 WHEN 1 THEN 3 WHEN 2 THEN 7 WHEN 3 THEN 5 WHEN 4 THEN 1 WHEN 5 THEN 0 WHEN 6 THEN 4 WHEN 7 THEN 6
$statuses = array(
    5 => "`\$Top Level`0",
    4 => "`^Escalated`0",
    0 => "`bUnhandled`b",
    1 => "In-Progress",
    6 => "`%Bug`0",
    7 => "`#Awaiting Points`0",
    3 => "`!Informational`0",
    2 => "`iClosed`i",
    );

$statuses = HookHandler::hook("petition-status", $statuses);
$statuses = Translator::translateInline($statuses);

$op = Http::get("op") ?? "";
$id = Http::get("id") ?? "";
$insertCommentary = (string) Http::post('insertcommentary');
if (!empty(trim($insertCommentary))) {
    /* Update the bug if someone adds comments as well */
    $sql = "UPDATE " . Database::prefix("petitions") . " SET closeuserid='{$session['user']['acctid']}',closedate='" . date("Y-m-d H:i:s") . "' WHERE petitionid='$id'";
    Database::query($sql);
}

// Eric decide he didn't want petitions to be manually deleted
//
//if ($op=="del"){
//  $sql = "DELETE FROM " . Database::prefix("petitions") . " WHERE petitionid='$id'";
//  Database::query($sql);
//  $sql = "DELETE FROM " . Database::prefix("commentary") . " WHERE section='pet-$id'";
//  Database::query($sql);
//  invalidatedatacache("petition_counts");
//  $op="";
//}
Header::pageHeader("Petition Viewer");
if ($op == "") {
    $sql = "DELETE FROM " . Database::prefix("petitions") . " WHERE status=2 AND closedate<'" . date("Y-m-d H:i:s", strtotime("-7 days")) . "'";
    Database::query($sql);
    $setstat = Http::get("setstat");
    invalidatedatacache("petition_counts");
    if ($setstat != "") {
        $sql = "SELECT status FROM " . Database::prefix("petitions") . " WHERE petitionid='$id'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        if ($row['status'] != $setstat) {
            $sql = "UPDATE " . Database::prefix("petitions") . " SET status='$setstat',closeuserid='{$session['user']['acctid']}',closedate='" . date("Y-m-d H:i:s") . "' WHERE petitionid='$id'";
            Database::query($sql);
        }
    }
    $sort = "";
    $pos = 0;
    foreach ($statuses as $key => $val) {
        $sort .= " WHEN $key THEN $pos";
        $pos++;
    }

    $petitionsperpage = 50;
    $sql = "SELECT count(petitionid) AS c from " . Database::prefix("petitions");
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    $totalpages = ceil($row['c'] / $petitionsperpage);

    $page = Http::get("page");
    if ($page == "") {
        if (isset($session['petitionPage'])) {
            $page = (int)$session['petitionPage'];
        } else {
            $page = 1;
        }
    }
    if ($page < 1) {
        $page = 1;
    }
    if ($page > $totalpages) {
        $page = $totalpages;
    }
    $session['petitionPage'] = $page;

    // No need to show the pages if there is only one.
    if ($totalpages != 1) {
        Nav::add("Page");
        for ($x = 1; $x <= $totalpages; $x++) {
            if ($page == $x) {
                Nav::add(array("`b`#Page %s`0`b", $x), "viewpetition.php?page=$x");
            } else {
                Nav::add(array("Page %s", $x), "viewpetition.php?page=$x");
            }
        }
    }
    if ($page > 1) {
        $limit = (($page - 1) * $petitionsperpage) . "," . $petitionsperpage;
    } else {
        $limit = "$petitionsperpage";
    }

    $sql =
    "SELECT
		petitionid,
		" . Database::prefix("accounts") . ".name,
		" . Database::prefix("petitions") . ".date,
		" . Database::prefix("petitions") . ".status,
		" . Database::prefix("petitions") . ".body,
		" . Database::prefix("petitions") . ".closedate,
		accts.name AS closer,
		CASE status $sort END AS sortorder
	FROM
		" . Database::prefix("petitions") . "
	LEFT JOIN
		" . Database::prefix("accounts") . "
	ON	" . Database::prefix("accounts") . ".acctid=" . Database::prefix("petitions") . ".author
	LEFT JOIN
		" . Database::prefix("accounts") . " AS accts
	ON	accts.acctid=" . Database::prefix("petitions") . ".closeuserid
	ORDER BY
		sortorder ASC,
		date ASC
	LIMIT $limit";
    $result = Database::query($sql);
    Nav::add("Petitions");
    Nav::add("Refresh", "viewpetition.php");
    $num = Translator::translateInline('Num');
    $ops = Translator::translateInline('Ops');
    $from = Translator::translateInline('From');
    $sent = Translator::translateInline('Sent');
    $com = Translator::translateInline('Com');
    $last = Translator::translateInline('Last Updater');
    $when = Translator::translateInline('Updated');
    $view = Translator::translateInline('View');
    $close = Translator::translateInline('Close');
    $mark = Translator::translateInline('Mark');

    $output->rawOutput("<table border='0'><tr class='trhead'><td>$num</td><td>$ops</td><td>$from</td><td>$sent</td><td>$com</td><td>$last</td><td>$when</td></tr>");
    $i = 0;
    $laststatus = -1;
    $catcount = array();
    while ($row = Database::fetchAssoc($result)) {
        if (isset($catcount[$row['status']])) {
            $catcount[$row['status']]++;
        } else {
            $catcount[$row['status']] = 1;
        }
        $i = !$i;
        $sql = "SELECT count(commentid) AS c FROM " . Database::prefix("commentary") .  " WHERE section='pet-{$row['petitionid']}'";
        $res = Database::query($sql);
        $counter = Database::fetchAssoc($res);
        if (array_key_exists('status', $row) && $row['status'] != $laststatus) {
            $output->rawOutput("<tr class='" . ($i ? "trlight" : "trdark") . "'>");
            $output->rawOutput("<td colspan='7' style='background-color:#FAA000'>");
            $output->outputNotl("%s", (array_key_exists($row['status'], $statuses) ? $statuses[$row['status']] : 'Undefined ' . $row['status']), true);
            $output->rawOutput("</td></tr>");
            $i = 1;
            $laststatus = $row['status'];
        }
        $output->rawOutput("<tr class='" . ($i ? "trlight" : "trdark") . "'>");
        $output->rawOutput("<td>");
        $output->outputNotl("%s", $row['petitionid']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td nowrap>[ ");
        $output->rawOutput("<a href='viewpetition.php?op=view&id={$row['petitionid']}'>$view</a>", true);
        $output->rawOutput(" | <a href='viewpetition.php?setstat=2&id={$row['petitionid']}'>$close</a>");
        $output->outputNotl(" | %s: ", $mark);
        $output->outputNotl("<a href='viewpetition.php?setstat=0&id={$row['petitionid']}'>`b`&U`0`b</a>/", true);
        $output->outputNotl("<a href='viewpetition.php?setstat=1&id={$row['petitionid']}'>`7P`0</a>/", true);
        //$output->outputNotl("<a href='viewpetition.php?setstat=3&id={$row['petitionid']}'>`!I`0</a>/",true);
        $output->outputNotl("<a href='viewpetition.php?setstat=4&id={$row['petitionid']}'>`^E`0</a>", true);
        //$output->outputNotl("<a href='viewpetition.php?setstat=5&id={$row['petitionid']}'>`\$T`0</a>/",true);
        //$output->outputNotl("<a href='viewpetition.php?setstat=6&id={$row['petitionid']}'>`%B`0</a>/",true);
        //$output->outputNotl("<a href='viewpetition.php?setstat=7&id={$row['petitionid']}'>`#A`0</a>",true);
        $output->rawOutput(" ]</td>");
        Nav::add("", "viewpetition.php?op=view&id={$row['petitionid']}");
        Nav::add("", "viewpetition.php?setstat=2&id={$row['petitionid']}");
        Nav::add("", "viewpetition.php?setstat=0&id={$row['petitionid']}");
        Nav::add("", "viewpetition.php?setstat=1&id={$row['petitionid']}");
        //Nav::add("","viewpetition.php?setstat=3&id={$row['petitionid']}");
        Nav::add("", "viewpetition.php?setstat=4&id={$row['petitionid']}");
        //Nav::add("","viewpetition.php?setstat=5&id={$row['petitionid']}");
        //Nav::add("","viewpetition.php?setstat=6&id={$row['petitionid']}");
        //Nav::add("","viewpetition.php?setstat=7&id={$row['petitionid']}");
        $output->rawOutput("<td>");
        if ($row['name'] == "") {
            $v = substr($row['body'], 0, strpos($row['body'], "[email"));
            $v = preg_replace("'\\[PHPSESSID\\] = .*'", "", $v);
            $v = preg_replace("'[^a-zA-Z0-91234567890\\[\\]= @.!,?-]'", "", $v);
            // Make sure we don't get something too large.. 50 chars max
            $v = substr($v, 0, 50);
            $output->outputNotl("`\$%s`0", $v);
        } else {
            $output->outputNotl("`&%s`0", $row['name']);
        }
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $output->outputNotl("`7%s`0", reltime(strtotime($row['date'])));
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $output->outputNotl("`#%s`0", $counter['c']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $output->outputNotl("`^%s`0", $row['closer']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        if ($row['closedate'] != 0) {
            $output->outputNotl("`7%s`0", reltime(strtotime($row['closedate'])));
        }
        $output->rawOutput("</td>");
        $output->rawOutput("</tr>");
    }
    $output->rawOutput("</table>");
    //navlist with counter
    if ($catcount != array()) {
        Nav::add("Overview");
    }
    foreach ($catcount as $categorynumber => $amount) {
        Nav::add(array("`t%s`t(%s)",array_key_exists($categorynumber, $statuses) ? $statuses[$categorynumber] : 'Undefined ' . $categorynumber,$amount), "viewpetition.php?page=" . ((int)Http::get('page')));
    }

    //end
    $output->output("`i(Closed petitions will automatically delete themselves when they have been closed for 7 days)`i");
    $output->output("`n`bKey:`b`n");
    $output->rawOutput("<ul><li>");
    $output->output("`\$T = Top Level`0 petitions are for petitions that only server operators can take care of.");
    $output->rawOutput("</li><li>");
    $output->output("`^E = Escalated`0 petitions deal with an issue you can't handle for yourself.");
    $output->output("Mark it escalated so someone with more permissions than you can deal with it.");
    $output->rawOutput("</li><li>");
    $output->output("`b`&U = Unhandled`0`b: No one is currently working on this problem, and it has not been dealt with yet.");
    $output->rawOutput("</li><li>");
    $output->output("P = In-Progress petitions are probably being worked on by someone else, so please leave them be unless they have been around for some time.");
    $output->rawOutput("</li><li>");
    $output->output("`%B = Bug/Suggestion`0 petitions are petitions that detail mistakes, bugs, misspellings, or suggestions for the game.");
    $output->rawOutput("</li><li>");
    $output->output("`#A = Awaiting Points`0 stuff wot is dun and needz teh points added (this is mostly for lotgd.net).");
    $output->rawOutput("</li><li>");
    $output->output("`!I = Informational`0 petitions are just around for others to view, either nothing needed to be done with them, or their issue has been dealt with, but you feel other admins could benefit from reading it.");
    $output->rawOutput("</li><li>");
    $output->output("`iClosed`i petitions are for you have dealt with an issue, these will auto delete when they have been closed for 7 days.");
    HookHandler::hook("petitions-descriptions", array());
    $output->rawOutput("</li></ul>");
} elseif ($op == "view") {
    Nav::add("Petitions");
    Nav::add("Details");
    $viewpageinfo = (int)Http::get("viewpageinfo");
    if ($viewpageinfo == 1) {
        Nav::add("Hide Details", "viewpetition.php?op=view&id=$id");
    } else {
        Nav::add("D?Show Details", "viewpetition.php?op=view&id=$id&viewpageinfo=1");
    }
    Nav::add("Navigation");
    Nav::add("V?Petition Viewer", "viewpetition.php");

    Nav::add("User Ops");

    Nav::add("Petition Ops");
    foreach ($statuses as $key => $val) {
        $plain = full_sanitize($val);
    // Skip empty or unnamed petition categories to mark
        if (empty($val)) {
            continue;
        }
        Nav::add(
            array("%s?Mark %s", substr($plain, 0, 1), $val),
            "viewpetition.php?setstat=$key&id=$id"
        );
    }

    $sql = "SELECT " . Database::prefix("accounts") . ".name," .  Database::prefix("accounts") . ".login," .  Database::prefix("accounts") . ".acctid," .  "author,date,closedate,status,petitionid,ip,body,pageinfo," .  "accts.name AS closer FROM " .  Database::prefix("petitions") . " LEFT JOIN " .  Database::prefix("accounts ") . "ON " .  Database::prefix("accounts") . ".acctid=author LEFT JOIN " .  Database::prefix("accounts") . " AS accts ON accts.acctid=" .  "closeuserid WHERE petitionid='$id' ORDER BY date ASC";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    Nav::add("User Ops");
    if (isset($row['login'])) {
        Nav::add("View User Biography", "bio.php?char=" . $row['acctid']
                        . "&ret=%2Fviewpetition.php%3Fop%3Dview%26id=" . $id);
    }
    if ($row['acctid'] > 0 && $session['user']['superuser'] & SU_EDIT_USERS) {
        Nav::add("User Ops");
        Nav::add("R?Edit User Record", "user.php?op=edit&userid={$row['acctid']}&returnpetition=$id");
    }
    if ($row['acctid'] > 0 && $session['user']['superuser'] & SU_EDIT_DONATIONS) {
        Nav::add("User Ops");
        Nav::add("Edit User Donations", "donators.php?op=add1&name=" . rawurlencode($row['login']) . "&ret=" . urlencode($_SERVER['REQUEST_URI']));
    }
    $write = Translator::translateInline('Write Mail');
    // We assume that petitions are handled in default language
    $yourpeti = Translator::translateMail('Your Petition', 0);
    $peti = Translator::translateMail('Petition', 0);
    $row['body'] = str_replace('[charname]', Translator::translateMail('[charname]', 0), $row['body']);
    $row['body'] = str_replace('[email]', Translator::translateMail('[email]', 0), $row['body']);
    $row['body'] = str_replace('[description]', Translator::translateMail('[description]', 0), $row['body']);
    // For email replies, make sure we don't overflow the URI buffer.
    $reppet = substr(stripslashes($row['body']), 0, 2000);
    //display given category, if any
    $array_body = explode("\n", $row['body']);
    $category_check = "[problem_type] = ";
    foreach ($array_body as $line) {
        $catpos = strpos($line, $category_check);
        if ($catpos !== false) {
            //cat found
            $output->rawOutput("<h2>");
            $output->output("`2Category: %s`n", substr($line, strlen($category_check) - 1));
            $output->rawOutput("</h2>");
        }
    }


    $output->output("`@From: ");
    if ($row['login'] > "") {
        $output->rawOutput("<a href=\"mail.php?op=write&to=" . rawurlencode($row['login']) . "&body=" . rawurlencode("\n\n----- $yourpeti -----\n$reppet") . "&subject=RE:+$peti\" target=\"_blank\" onClick=\"" . popup("mail.php?op=write&to=" . rawurlencode($row['login']) . "&body=" . rawurlencode("\n\n----- $yourpeti -----\n$reppet") . "&subject=RE:+$peti") . ";return false;\"><img src='images/newscroll.GIF' width='16' height='16' alt='$write' border='0'></a>");
    }
    $output->outputNotl("`^`b%s`b`n", $row['name']);
    $output->output("`@Date: `^`b%s`b (%s)`n", $row['date'], reltime(strtotime($row['date'])));
    $output->output("`@Status: %s`n", $statuses[$row['status']]);
    if ($row['closer'] != '') {
        $output->output("`@Last Update: `^%s`@ on `^%s (%s)`n", $row['closer'], $row['closedate'], reltime(strtotime($row['closedate'])));
    }
    $output->output("`@Body:`^`n");
    $output->output("`\$[ipaddress] `^= `#%s`^`n", $row['ip']);
    $body = htmlentities(stripslashes($row['body']), ENT_COMPAT, getsetting("charset", "UTF-8"));
    $body = preg_replace("'([[:alnum:]_.-]+[@][[:alnum:]_.-]{2,}([.][[:alnum:]_.-]{2,})+)'i", "<a href='mailto:\\1?subject=RE: $peti&body=" . str_replace("+", " ", URLEncode("\n\n----- $yourpeti -----\n" . stripslashes($row['body']))) . "'>\\1</a>", $body);
    $body = preg_replace("'([\\[][[:alnum:]_.-]+[\\]])'i", "<span class='colLtRed'>\\1</span>", $body);
    $output->rawOutput("<span style='font-family: fixed-width'>" . nl2br($body) . "</span>");
    //position tracking
    if ($row['body'] != '') {
        $pos = strpos($row['body'], "[abuseplayer]") + 16;
        $endpos = strpos($row['body'], chr(10), $pos);
        if ($pos !== false) {
            $search = substr($row['body'], $pos, $endpos - $pos);
            $search = (int) $search;
            if ($search != 0) {
                HookHandler::hook("petition-abuse", array("acctid" => $search,"abused" => $row['author']));
            }
        }
    }
    Commentary::commentDisplay("`n`@Commentary:`0`n", "pet-$id", "Add information", 200);
    if ($viewpageinfo) {
        $output->output("`n`n`@Page Info:`&`n");
        $row['pageinfo'] = stripslashes($row['pageinfo']);
        $body = HTMLEntities($row['pageinfo'], ENT_COMPAT, getsetting("charset", "UTF-8"));
        $body = preg_replace("'([[:alnum:]_.-]+[@][[:alnum:]_.-]{2,}([.][[:alnum:]_.-]{2,})+)'i", "<a href='mailto:\\1?subject=RE: $peti&body=" . str_replace("+", " ", URLEncode("\n\n----- $yourpeti -----\n" . $row['body'])) . "'>\\1</a>", $body);
        $body = preg_replace("'([\\[][[:alnum:]_.-]+[\\]])'i", "<span class='colLtRed'>\\1</span>", $body);
        $output->rawOutput("<span style='font-family: fixed-width'>" . nl2br($body) . "</span>");
    }
}

if ($id && $op != "") {
    $prevsql = "SELECT p1.petitionid, p1.status FROM " . Database::prefix("petitions") . " AS p1, " . Database::prefix("petitions") . " AS p2
			WHERE p1.petitionid<'$id' AND p2.petitionid='$id' AND p1.status=p2.status ORDER BY p1.petitionid DESC LIMIT 1";
    $prevresult = Database::query($prevsql);
    $prevrow = Database::fetchAssoc($prevresult);
    if ($prevrow) {
        $previd = $prevrow['petitionid'];
        $s = $prevrow['status'];
        $status = $statuses[$s];
        Nav::add("Petitions");
        Nav::add(array("Previous %s",$status), "viewpetition.php?op=view&id=$previd");
    }
    $nextsql = "SELECT p1.petitionid, p1.status FROM " . Database::prefix("petitions") . " AS p1, " . Database::prefix("petitions") . " AS p2
			WHERE p1.petitionid>'$id' AND p2.petitionid='$id' AND p1.status=p2.status ORDER BY p1.petitionid ASC LIMIT 1";
    $nextresult = Database::query($nextsql);
    $nextrow = Database::fetchAssoc($nextresult);
    if ($nextrow) {
        $nextid = $nextrow['petitionid'];
        $s = $nextrow['status'];
        $status = $statuses[$s];
        Nav::add("Petitions");
        Nav::add(array("Next %s",$status), "viewpetition.php?op=view&id=$nextid");
    }
}
Footer::pageFooter();
