<?php

use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\Moderate;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Sanitize;
use Lotgd\DataCache;
use Lotgd\Redirect;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";


$output = Output::getInstance();
$settings = Settings::getInstance();

$translator = Translator::getInstance();

$translator->setSchema("moderate");

Commentary::addCommentary();

SuAccess::check(SU_EDIT_COMMENTS);

SuperuserNav::render();

Nav::add("Other");
Nav::add("Commentary Overview", "moderate.php");
Nav::add("Reset Seen Comments", "moderate.php?seen=" . rawurlencode(date("Y-m-d H:i:s")));
Nav::add("B?Player Bios", "bios.php");
if ($session['user']['superuser'] & SU_AUDIT_MODERATION) {
    Nav::add("Audit Moderation", "moderate.php?op=audit");
}
Nav::add("Review by Moderator");
Nav::add("Commentary");
Nav::add("Sections");
Nav::add("Modules");
Nav::add("Clan Halls");

$op = Http::get("op");
if ($op == "commentdelete") {
    $comment = Http::post('comment');
    if (Http::post('delnban') > '') {
        $sql = "SELECT DISTINCT uniqueid,author FROM " . Database::prefix("commentary") . " INNER JOIN " . Database::prefix("accounts") . " ON acctid=author WHERE commentid IN ('" . join("','", array_keys($comment)) . "')";
        $result = Database::query($sql);
        $untildate = date("Y-m-d H:i:s", strtotime("+3 days"));
        $reason = Http::post("reason");
        $reason0 = Http::post("reason0");
        $default = "Banned for comments you posted.";
        if ($reason0 != $reason && $reason0 != $default) {
            $reason = $reason0;
        }
        if ($reason == "") {
            $reason = $default;
        }
        while ($row = Database::fetchAssoc($result)) {
            $sql = "SELECT * FROM " . Database::prefix("bans") . " WHERE uniqueid = '{$row['uniqueid']}'";
            $result2 = Database::query($sql);
            $sql = "INSERT INTO " . Database::prefix("bans") . " (uniqueid,banexpire,banreason,banner) VALUES ('{$row['uniqueid']}','$untildate','$reason','" . addslashes($session['user']['name']) . "')";
            $sql2 = "UPDATE " . Database::prefix("accounts") . " SET loggedin=0 WHERE acctid={$row['author']}";
            if (Database::numRows($result2) > 0) {
                $row2 = Database::fetchAssoc($result2);
                if ($row2['banexpire'] < $untildate) {
                    //don't enter a new ban if a longer lasting one is
                    //already here.
                    Database::query($sql);
                    Database::query($sql2);
                }
            } else {
                Database::query($sql);
                Database::query($sql2);
            }
        }
    }
    if (!isset($comment) || !is_array($comment)) {
        $comment = array();
    }
    $sql = "SELECT " .
        Database::prefix("commentary") . ".*," . Database::prefix("accounts") . ".name," .
        Database::prefix("accounts") . ".login, " . Database::prefix("accounts") . ".clanrank," .
        Database::prefix("clans") . ".clanshort FROM " . Database::prefix("commentary") .
        " INNER JOIN " . Database::prefix("accounts") . " ON " .
        Database::prefix("accounts") . ".acctid = " . Database::prefix("commentary") .
        ".author LEFT JOIN " . Database::prefix("clans") . " ON " .
        Database::prefix("clans") . ".clanid=" . Database::prefix("accounts") .
        ".clanid WHERE commentid IN ('" . join("','", array_keys($comment)) . "')";
    $result = Database::query($sql);
    $invalsections = array();
    while ($row = Database::fetchAssoc($result)) {
        $sql = "INSERT LOW_PRIORITY INTO " . Database::prefix("moderatedcomments") .
            " (moderator,moddate,comment) VALUES ('{$session['user']['acctid']}','" . date("Y-m-d H:i:s") . "','" . addslashes(serialize($row)) . "')";
        Database::query($sql);
        $invalsections[$row['section']] = 1;
    }
    $sql = "DELETE FROM " . Database::prefix("commentary") . " WHERE commentid IN ('" . join("','", array_keys($comment)) . "')";
    Database::query($sql);
    $return = Http::get('return');
    $return = Sanitize::cmdSanitize($return);
    $return = basename($return);
    if (strpos($return, "?") === false && strpos($return, "&") !== false) {
        $x = strpos($return, "&");
        $return = substr($return, 0, $x - 1) . "?" . substr($return, $x + 1);
    }
    foreach ($invalsections as $key => $dummy) {
        DataCache::getInstance()->invalidatedatacache("comments-$key");
    }
    //update moderation cache
    DataCache::getInstance()->invalidatedatacache("comments-or11");
    Redirect::redirect($return);
}

$seen = Http::get("seen");
if ($seen > "") {
    $session['user']['recentcomments'] = $seen;
}

Header::pageHeader("Comment Moderation");


if ($op == "") {
    $area = Http::get('area');
    $link = "moderate.php" . ($area ? "?area=$area" : "");
    $refresh = Translator::translateInline("Refresh");
    $output->rawOutput("<form action='$link' method='POST'>");
    $output->rawOutput("<input type='submit' class='button' value='$refresh'>");
    $output->rawOutput("</form>");
    Nav::add("", "$link");
    if ($area == "") {
               Commentary::talkForm("X", "says");
        //commentdisplay("", "' or '1'='1","X",100); //sure, encourage hacking...
        Moderate::commentmoderate('', '', 'X', 100, 'says', false, true);
    } else {
        Moderate::commentmoderate("", $area, "X", 100);
               Commentary::talkForm($area, "says");
    }
} elseif ($op == "audit") {
    $subop = Http::get("subop");
    if ($subop == "undelete") {
        $unkeys = Http::post("mod");
        if ($unkeys && is_array($unkeys)) {
            $sql = "SELECT * FROM " . Database::prefix("moderatedcomments") . " WHERE modid IN ('" . join("','", array_keys($unkeys)) . "')";
            $result = Database::query($sql);
            while ($row = Database::fetchAssoc($result)) {
                $comment = unserialize($row['comment']);
                $id = addslashes($comment['commentid']);
                $postdate = addslashes($comment['postdate']);
                $section = addslashes($comment['section']);
                $author = addslashes($comment['author']);
                $comment = addslashes($comment['comment']);
                $sql = "INSERT LOW_PRIORITY INTO " . Database::prefix("commentary") . " (commentid,postdate,section,author,comment) VALUES ('$id','$postdate','$section','$author','$comment')";
                Database::query($sql);
                DataCache::getInstance()->invalidatedatacache("comments-$section");
            }
            $sql = "DELETE FROM " . Database::prefix("moderatedcomments") . " WHERE modid IN ('" . join("','", array_keys($unkeys)) . "')";
            Database::query($sql);
        } else {
            $output->output("No items selected to undelete -- Please try again`n`n");
        }
    }
    $sql = "SELECT DISTINCT acctid, name FROM " . Database::prefix("accounts") .
        " INNER JOIN " . Database::prefix("moderatedcomments") .
        " ON acctid=moderator ORDER BY name";
    $result = Database::query($sql);
    Nav::add("Commentary");
    Nav::add("Sections");
    Nav::add("Modules");
    Nav::add("Clan Halls");
    Nav::add("Review by Moderator");
    $translator->setSchema("notranslate");
    while ($row = Database::fetchAssoc($result)) {
        Nav::add(" ?" . $row['name'], "moderate.php?op=audit&moderator={$row['acctid']}");
    }
    $translator->setSchema();
    Nav::add("Commentary");
    $output->output("`c`bComment Auditing`b`c");
    $ops = Translator::translateInline("Ops");
    $mod = Translator::translateInline("Moderator");
    $when = Translator::translateInline("When");
    $com = Translator::translateInline("Comment");
    $unmod = Translator::translateInline("Unmoderate");
    $output->rawOutput("<form action='moderate.php?op=audit&subop=undelete' method='POST'>");
    Nav::add("", "moderate.php?op=audit&subop=undelete");
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='0'>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$mod</td><td>$when</td><td>$com</td></tr>");
    $limit = "75";
    $where = "1=1 ";
    $moderator = Http::get("moderator");
    if ($moderator > "") {
        $where .= "AND moderator=$moderator ";
    }
    $sql = "SELECT name, " . Database::prefix("moderatedcomments") .
        ".* FROM " . Database::prefix("moderatedcomments") . " LEFT JOIN " .
        Database::prefix("accounts") .
        " ON acctid=moderator WHERE $where ORDER BY moddate DESC LIMIT $limit";
    $result = Database::query($sql);
    $i = 0;
    $clanrankcolors = array("`!","`#","`^","`&","\$");
    while ($row = Database::fetchAssoc($result)) {
        $i++;
        $output->rawOutput("<tr class='" . ($i % 2 ? 'trlight' : 'trdark') . "'>");
        $output->rawOutput("<td><input type='checkbox' name='mod[{$row['modid']}]' value='1'></td>");
        $output->rawOutput("<td>");
        $output->outputNotl("%s", $row['name']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $output->outputNotl("%s", $row['moddate']);
        $output->rawOutput("</td>");
        $output->rawOutput("<td>");
        $comment = unserialize($row['comment']);
        if (!is_array($comment) || !isset($comment['section'])) {
            $output->output("---no comment found---");
            $output->rawOutput("</td>");
            $output->rawOutput("</tr>");
            continue; //whatever we did here
        }
        $output->outputNotl("`0(%s)", $comment['section']);

        if ($comment['clanrank'] > 0) {
            $output->outputNotl(
                "%s<%s%s>`0",
                $clanrankcolors[ceil($comment['clanrank'] / 10)],
                $comment['clanshort'],
                $clanrankcolors[ceil($comment['clanrank'] / 10)]
            );
        }
        $output->outputNotl("%s", $comment['name']);
        $output->outputNotl("-");
        $output->outputNotl("%s", Sanitize::commentSanitize($comment['comment']));
        $output->rawOutput("</td>");
        $output->rawOutput("</tr>");
    }
    $output->rawOutput("</table>");
    $output->rawOutput("<input type='submit' class='button' value='$unmod'>");
    $output->rawOutput("</form>");
}


Nav::add("Sections");
$translator->setSchema("commentary");
$vname = $settings->getSetting('villagename', LOCATION_FIELDS);
Nav::add(array("%s Square", $vname), "moderate.php?area=village");

if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
    Nav::add("Grotto", "moderate.php?area=superuser");
}

Nav::add("Land of the Shades", "moderate.php?area=shade");
Nav::add("Grassy Field", "moderate.php?area=grassyfield");

$iname = $settings->getSetting('innname', LOCATION_INN);
// the inn name is a proper name and shouldn't be translated.
$translator->setSchema("notranslate");
Nav::add($iname, "moderate.php?area=inn");
$translator->setSchema();

Nav::add("MotD", "moderate.php?area=motd");
Nav::add("Veterans Club", "moderate.php?area=veterans");
Nav::add("Hunter's Lodge", "moderate.php?area=hunterlodge");
Nav::add("Gardens", "moderate.php?area=gardens");
Nav::add("Clan Hall Waiting Area", "moderate.php?area=waiting");

if ($settings->getSetting('betaperplayer', 1) == 1 && @file_exists("pavilion.php")) {
    Nav::add("Beta Pavilion", "moderate.php?area=beta");
}
$translator->setSchema();

if ($session['user']['superuser'] & SU_MODERATE_CLANS) {
    Nav::add("Clan Halls");
    $sql = "SELECT clanid,clanname,clanshort FROM " . Database::prefix("clans") . " ORDER BY clanid";
    $result = Database::query($sql);
    // these are proper names and shouldn't be translated.
    $translator->setSchema("notranslate");
    while ($row = Database::fetchAssoc($result)) {
        Nav::add(
            array("<%s> %s", $row['clanshort'], $row['clanname']),
            "moderate.php?area=clan-{$row['clanid']}"
        );
    }
    $translator->setSchema();
} elseif (
    $session['user']['superuser'] & SU_EDIT_COMMENTS &&
        $settings->getSetting('officermoderate', 0)
) {
    // the CLAN_OFFICER requirement was chosen so that moderators couldn't
    // just get accepted as a member to any random clan and then proceed to
    // wreak havoc.
    // although this isn't really a big deal on most servers, the choice was
    // made so that staff won't have to have another issue to take into
    // consideration when choosing moderators.  the issue is moot in most
    // cases, as players that are trusted with moderator powers are also
    // often trusted with at least the rank of officer in their respective
    // clans.
    if (
        ($session['user']['clanid'] != 0) &&
            ($session['user']['clanrank'] >= CLAN_OFFICER)
    ) {
        Nav::add("Clan Halls");
        $sql = "SELECT clanid,clanname,clanshort FROM " . Database::prefix("clans") . " WHERE clanid='" . $session['user']['clanid'] . "'";
        $result = Database::query($sql);
        // these are proper names and shouldn't be translated.
        $translator->setSchema("notranslate");
        if ($row = Database::fetchAssoc($result)) {
            Nav::add(
                array("<%s> %s", $row['clanshort'], $row['clanname']),
                "moderate.php?area=clan-{$row['clanid']}"
            );
        } else {
            debug("There was an error while trying to access your clan.");
        }
        $translator->setSchema();
    }
}
Nav::add("Modules");
$mods = array();
$mods = HookHandler::hook("moderate", $mods);
reset($mods);

// These are already translated in the module.
$translator->setSchema("notranslate");
foreach ($mods as $area => $name) {
    Nav::add($name, "moderate.php?area=$area");
}
$translator->setSchema();

Footer::pageFooter();
