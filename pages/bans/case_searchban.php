<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\PlayerSearch;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\DateTime;

$output = Output::getInstance();

$operator = "<=";
$playerSearch = new PlayerSearch();


$target = Http::post('target');
$since = 'WHERE 0';
$submit = Translator::translateInline("Search");
if ($target == '') {
    $output->rawOutput("<form action='bans.php?op=searchban' method='POST'>");
    Nav::add("", "bans.php?op=searchban");
    $output->output("Search banned user by name: ");
    $output->rawOutput("<input name='target' value='$target'>");
    $output->rawOutput("<input type='submit' class='button' value='$submit'></from><br><br>");
} elseif (is_numeric($target)) {
    //none
    $sql = "SELECT lastip,uniqueid FROM accounts WHERE acctid=" . $target;
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    $since = "WHERE ipfilter LIKE '%" . $row['lastip'] . "%' OR uniqueid LIKE '%" . $row['uniqueid'] . "%'";
} else {
    $names = $playerSearch->legacyLookup((string) $target, ['acctid', 'login', 'name'], 'login');
    if ($names['rows'] !== []) {
        $output->rawOutput("<form action='bans.php?op=searchban' method='POST'>");
        Nav::add("", "bans.php?op=searchban");
                $output->rawOutput("<label for='target'>");
                $output->output("Search banned user by name: ");
                $output->rawOutput("</label>");
                $output->rawOutput("<select name='target' id='target'>");
        $resultRows = $names['rows'];
        while ($row = Database::fetchAssoc($resultRows)) {
            $output->rawOutput("<option value='" . $row['acctid'] . "'>" . $row['login'] . "</option>");
        }
        $output->rawOutput("</select>");
        $output->rawOutput("<input type='submit' class='button' value='$submit'></from><br><br>");
    }
}

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
