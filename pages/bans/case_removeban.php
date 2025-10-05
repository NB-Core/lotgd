<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\DateTime;

$output = Output::getInstance();

Database::query("DELETE FROM " . Database::prefix("bans") . " WHERE banexpire < \"" . date("Y-m-d H:m:s") . "\" AND banexpire<'" . DATETIME_DATEMAX . "'");
$duration =  Http::get("duration");
if (Http::get('notbefore')) {
    $operator = ">=";
} else {
    $operator = "<=";
}

if ($duration == "") {
    $since = " WHERE banexpire $operator '" . date("Y-m-d H:i:s", strtotime("+2 weeks")) . "' AND banexpire < '" . DATETIME_DATEMAX . "'";
        $output->output("`bShowing bans that will expire within 2 weeks.`b`n`n");
} else {
    if ($duration == "forever") {
        $since = " WHERE banexpire='" . DATETIME_DATEMAX . "'";
        $output->output("`bShowing all permanent bans`b`n`n");
    } elseif ($duration == "all") {
        $since = "";
        $output->output("`bShowing all bans`b`n`n");
    } else {
        $since = " WHERE banexpire $operator '" . date("Y-m-d H:i:s", strtotime("+" . $duration)) . "' AND banexpire < '" . DATETIME_DATEMAX . "'";
        $output->output("`bShowing bans that will expire within %s.`b`n`n", $duration);
    }
}
Nav::add("Perma-Bans");
Nav::add("Show", "bans.php?op=removeban&duration=forever");
Nav::add("Will Expire Within");
Nav::add("1 week", "bans.php?op=removeban&duration=1+week");
Nav::add("2 weeks", "bans.php?op=removeban&duration=2+weeks");
Nav::add("3 weeks", "bans.php?op=removeban&duration=3+weeks");
Nav::add("4 weeks", "bans.php?op=removeban&duration=4+weeks");
Nav::add("2 months", "bans.php?op=removeban&duration=2+months");
Nav::add("3 months", "bans.php?op=removeban&duration=3+months");
Nav::add("4 months", "bans.php?op=removeban&duration=4+months");
Nav::add("5 months", "bans.php?op=removeban&duration=5+months");
Nav::add("6 months", "bans.php?op=removeban&duration=6+months");
Nav::add("1 year", "bans.php?op=removeban&duration=1+year");
Nav::add("2 years", "bans.php?op=removeban&duration=2+years");
Nav::add("4 years", "bans.php?op=removeban&duration=4+years");
Nav::add("Show all", "bans.php?op=removeban&duration=all");
Nav::add("Will Expire not before");
Nav::add("1 week", "bans.php?op=removeban&duration=1+week&notbefore=1");
Nav::add("2 weeks", "bans.php?op=removeban&duration=2+weeks&notbefore=1");
Nav::add("3 weeks", "bans.php?op=removeban&duration=3+weeks&notbefore=1");
Nav::add("4 weeks", "bans.php?op=removeban&duration=4+weeks&notbefore=1");
Nav::add("2 months", "bans.php?op=removeban&duration=2+months&notbefore=1");
Nav::add("3 months", "bans.php?op=removeban&duration=3+months&notbefore=1");
Nav::add("4 months", "bans.php?op=removeban&duration=4+months&notbefore=1");
Nav::add("5 months", "bans.php?op=removeban&duration=5+months&notbefore=1");
Nav::add("6 months", "bans.php?op=removeban&duration=6+months&notbefore=1");
Nav::add("1 year", "bans.php?op=removeban&duration=1+year&notbefore=1");
Nav::add("2 years", "bans.php?op=removeban&duration=2+years&notbefore=1");
Nav::add("4 years", "bans.php?op=removeban&duration=4+years&notbefore=1");

$sql = "SELECT * FROM " . Database::prefix("bans") . " $since ORDER BY banexpire ASC";
$result = Database::query($sql);
$output->rawOutput("<script>
(function () {
    function lotgdLoadAffectedUsers(ip, id, index) {
        var handlers = typeof window.getJaxonHandlers === 'function'
            ? window.getJaxonHandlers()
            : (window.Lotgd && window.Lotgd.Async && window.Lotgd.Async.Handler)
                || (window.JaxonLotgd && window.JaxonLotgd.Async && window.JaxonLotgd.Async.Handler)
                || null;

        if (!handlers || !handlers.Bans || typeof handlers.Bans.affectedUsers !== 'function') {
            console.error('Lotgd.Async.Handler.Bans.affectedUsers is unavailable');
            return false;
        }

        handlers.Bans.affectedUsers(ip, id, 'user' + index);
        return false;
    }

    window.lotgdLoadAffectedUsers = lotgdLoadAffectedUsers;
}());
</script>
");
$output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
$ops = Translator::translateInline("Ops");
$bauth = Translator::translateInline("Ban Author");
$ipd = Translator::translateInline("IP/ID");
$dur = Translator::translateInline("Duration");
$mssg = Translator::translateInline("Message");
$aff = Translator::translateInline("Affects");
$l = Translator::translateInline("Last");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$bauth</td><td>$ipd</td><td>$dur</td><td>$mssg</td><td>$aff</td><td>$l</td></tr>");
$i = 0;
while ($row = Database::fetchAssoc($result)) {
    $liftban = Translator::translateInline("Lift&nbsp;ban");
    $showuser = Translator::translateInline("Click&nbsp;to&nbsp;show&nbsp;users");
    $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
    $output->rawOutput("<td><a href='bans.php?op=delban&ipfilter=" . URLEncode($row['ipfilter']) . "&uniqueid=" . URLEncode($row['uniqueid']) . "'>");
    $output->outputNotl("%s", $liftban, true);
    $output->rawOutput("</a>");
    Nav::add("", "bans.php?op=delban&ipfilter=" . URLEncode($row['ipfilter']) . "&uniqueid=" . URLEncode($row['uniqueid']));
    $output->rawOutput("</td><td>");
    $output->outputNotl("`&%s`0", $row['banner']);
    $output->rawOutput("</td><td>");
    $output->outputNotl("%s", $row['ipfilter']);
    $output->outputNotl("%s", $row['uniqueid']);
    $output->rawOutput("</td><td>");
        // "43200" used so will basically round to nearest day rather than floor number of days

    $expire = Translator::getInstance()->sprintfTranslate(
        "%s days",
        round((strtotime($row['banexpire']) + 43200 - strtotime("now")) / 86400, 0)
    );
    if (substr($expire, 0, 2) == "1 ") {
        $expire = Translator::translateInline("1 day");
    }
    if (date("Y-m-d", strtotime($row['banexpire'])) == date("Y-m-d")) {
        $expire = Translator::translateInline("Today");
    }
    if (
        date("Y-m-d", strtotime($row['banexpire'])) ==
            date("Y-m-d", strtotime("1 day"))
    ) {
        $expire = Translator::translateInline("Tomorrow");
    }
    if ($row['banexpire'] == DATETIME_DATEMAX) {
        $expire = Translator::translateInline("Never");
    }
    $output->outputNotl("%s", $expire);
    $output->rawOutput("</td><td>");
    $output->outputNotl("%s", $row['banreason']);
    $output->rawOutput("</td><td>");
    $ipArgument = json_encode($row['ipfilter'], JSON_THROW_ON_ERROR);
    $idArgument = json_encode($row['uniqueid'], JSON_THROW_ON_ERROR);
    $output->rawOutput("<div id='user$i'><a href='#' onClick=\"return lotgdLoadAffectedUsers({$ipArgument}, {$idArgument}, $i);\">");
    $output->outputNotl("%s", $showuser, true);
    $output->rawOutput("</a></div>");
    $output->rawOutput("</td><td>");
    $output->outputNotl("%s", DateTime::relativeDate($row['lasthit']));
    $output->rawOutput("</td></tr>");
    $i++;
}
$output->rawOutput("</table>");
