<?php
declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Translator;

$sql = "SELECT name,lastip,uniqueid FROM " . db_prefix("accounts") . " WHERE acctid=\"$userid\"";
$result = db_query($sql);
$row = db_fetch_assoc($result);
if ($row['name'] != "") {
    $output->output("Setting up ban information based on `\$%s`0", $row['name']);
}
$output->rawOutput("<form action='user.php?op=saveban' method='POST'>");
$output->output("Set up a new ban by IP or by ID (recommended IP, though if you have several different users behind a NAT, you can try ID which is easily defeated)`n");
$output->rawOutput("<input type='radio' value='ip' id='ipradio' name='type' checked>");
$output->output("IP: ");
$output->rawOutput("<input name='ip' id='ip' value=\"" . HTMLEntities($row['lastip'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">");
$output->outputNotl("`n");
$output->rawOutput("<input type='radio' value='id' name='type'>");
$output->output("ID: ");
$output->rawOutput("<input name='id' value=\"" . HTMLEntities($row['uniqueid'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">");
$output->output("`nDuration: ");
$output->rawOutput("<input name='duration' id='duration' size='3' value='14'>");
$output->output("Days (0 for permanent)`n");
$reason = httpget("reason");
if ($reason == "") {
    $reason = Translator::translateInline("Don't mess with me.");
}
$output->output("Reason for the ban: ");
$output->rawOutput("<input name='reason' size=50 value=\"$reason\">");
$output->outputNotl("`n");
$pban = Translator::translateInline("Post ban");
$conf = Translator::translateInline("Are you sure you wish to issue a permanent ban?");
$output->rawOutput("<input type='submit' class='button' value='$pban' onClick='if (document.getElementById(\"duration\").value==0) {return confirm(\"$conf\");} else {return true;}'>");
$output->rawOutput("</form>");
$output->output("For an IP ban, enter the beginning part of the IP you wish to ban if you wish to ban a range, or simply a full IP to ban a single IP`n`n");
Nav::add("", "user.php?op=saveban");
if ($row['name'] != "") {
    $id = $row['uniqueid'];
    $ip = $row['lastip'];
    $name = $row['name'];
    $output->output("`0To help locate similar users to `@%s`0, here are some other users who are close:`n", $name);
    $output->output("`bSame ID (%s):`b`n", $id);
    $sql = "SELECT name, lastip, uniqueid, laston, gentimecount FROM " . db_prefix("accounts") . " WHERE uniqueid='" . addslashes($id) . "' ORDER BY lastip";
    $result = db_query($sql);
    while ($row = db_fetch_assoc($result)) {
        $output->output(
            "`0• (%s) `%%s`0 - %s hits, last: %s`n",
            $row['lastip'],
            $row['name'],
            $row['gentimecount'],
            reltime(strtotime($row['laston']))
        );
    }
    $output->outputNotl("`n");
        $oip = "";
    $dots = 0;
    $output->output("`bSimilar IP's`b`n");
    for ($x = strlen($ip); $x > 0; $x--) {
        if ($dots > 1) {
            break;
        }
        $thisip = substr($ip, 0, $x);
        $sql = "SELECT name, lastip, uniqueid, laston, gentimecount FROM " . db_prefix("accounts") . " WHERE lastip LIKE '$thisip%' AND NOT (lastip LIKE '$oip') ORDER BY uniqueid";
        //$output->output("$sql`n");
        $result = db_query($sql);
        if (db_num_rows($result) > 0) {
            $output->output("• IP Filter: %s ", $thisip);
            $output->rawOutput("<a href='#' onClick=\"document.getElementById('ip').value='$thisip'; document.getElementById('ipradio').checked = true; return false\">");
            $output->output("Use this filter");
            $output->rawOutput("</a>");
            $output->outputNotl("`n");
            while ($row = db_fetch_assoc($result)) {
                $output->output("&nbsp;&nbsp;", true);
                $output->output(
                    "• (%s) [%s] `%%s`0 - %s hits, last: %s`n",
                    $row['lastip'],
                    $row['uniqueid'],
                    $row['name'],
                    $row['gentimecount'],
                    reltime(strtotime($row['laston']))
                );
            }
            $output->outputNotl("`n");
        }
        if (substr($ip, $x - 1, 1) == ".") {
            $x--;
            $dots++;
        }
        $oip = $thisip . "%";
    }
}
