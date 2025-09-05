<?php

declare(strict_types=1);

use Lotgd\UserLookup;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

$subop = httpget("subop");
$none = Translator::translateInline('NONE');
if ($subop == "xml") {
    header("Content-Type: text/xml");
    $sql = "SELECT DISTINCT " . Database::prefix("accounts") . ".name FROM " . Database::prefix("bans") . ", " . Database::prefix("accounts") . " WHERE (ipfilter='" . addslashes(httpget("ip")) . "' AND " .
        Database::prefix("bans") . ".uniqueid='" .
        addslashes(httpget("id")) . "') AND ((substring(" .
        Database::prefix("accounts") . ".lastip,1,length(ipfilter))=ipfilter " .
        "AND ipfilter<>'') OR (" .  Database::prefix("bans") . ".uniqueid=" .
        Database::prefix("accounts") . ".uniqueid AND " .
        Database::prefix("bans") . ".uniqueid<>''))";
    $r = Database::query($sql);
    $output->rawOutput("<xml>");
    while ($ro = Database::fetchAssoc($r)) {
        $output->rawOutput("<name name=\"" . urlencode(appoencode("`0{$ro['name']}")) . "\"/>");
    }
    if (Database::numRows($r) == 0) {
        $output->rawOutput("<name name=\"$none\"/>");
    }
    $output->rawOutput("</xml>");
    exit();
}
$operator = "<=";


$target = httppost('target');
$since = 'WHERE 0';
$submit = Translator::translateInline("Search");
if ($target == '') {
    $output->rawOutput("<form action='user.php?op=searchban' method='POST'>");
    Nav::add("", "user.php?op=searchban");
    $output->output("Search banned user by name: ");
    $output->rawOutput("<input name='target' value='$target'>");
    $output->rawOutput("<input type='submit' class='button' value='$submit'></form><br><br>");
} elseif (is_numeric($target)) {
    //none
    $sql = "SELECT lastip,uniqueid FROM accounts WHERE acctid=" . $target;
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    $since = "WHERE ipfilter LIKE '%" . $row['lastip'] . "%' OR uniqueid LIKE '%" . $row['uniqueid'] . "%'";
} else {
    $names = UserLookup::lookup($target);
    if ($names[0] !== false) {
        $output->rawOutput("<form action='user.php?op=searchban' method='POST'>");
        Nav::add("", "user.php?op=searchban");
                $output->rawOutput("<label for='target'>");
                $output->output("Search banned user by name: ");
                $output->rawOutput("</label>");
                $output->rawOutput("<select name='target' id='target'>");
        while ($row = Database::fetchAssoc($names[0])) {
            $output->rawOutput("<option value='" . $row['acctid'] . "'>" . $row['login'] . "</option>");
        }
        $output->rawOutput("</select>");
        $output->rawOutput("<input type='submit' class='button' value='$submit'></form><br><br>");
    }
}

$sql = "SELECT * FROM " . Database::prefix("bans") . " $since ORDER BY banexpire ASC";
$result = Database::query($sql);
$output->rawOutput("<script language='JavaScript'>
function getUserInfo(ip,id,divid){
	var filename='user.php?op=removeban&subop=xml&ip='+ip+'&id='+id;
	//set up the DOM object
	var xmldom;
	if (document.implementation &&
			document.implementation.createDocument){
		//Mozilla style browsers
		xmldom = document.implementation.createDocument('', '', null);
	} else if (window.ActiveXObject) {
		//IE style browsers
		xmldom = new ActiveXObject('Microsoft.XMLDOM');
	}
		xmldom.async=false;
	xmldom.load(filename);
	var output='';
	for (var x=0; x<xmldom.documentElement.childNodes.length; x++){
		output = output + unescape(xmldom.documentElement.childNodes[x].getAttribute('name').replace(/\\+/g,' ')) +'<br>';
	}
	document.getElementById('user'+divid).innerHTML=output;
}
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
    $output->rawOutput("<td><a href='user.php?op=delban&ipfilter=" . URLEncode($row['ipfilter']) . "&uniqueid=" . URLEncode($row['uniqueid']) . "'>");
    $output->outputNotl("%s", $liftban, true);
    $output->rawOutput("</a>");
    Nav::add("", "user.php?op=delban&ipfilter=" . URLEncode($row['ipfilter']) . "&uniqueid=" . URLEncode($row['uniqueid']));
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
    if ($row['banexpire'] == DATETIME_DATEMIN) {
        $expire = Translator::translateInline("Never");
    }
    $output->outputNotl("%s", $expire);
    $output->rawOutput("</td><td>");
    $output->outputNotl("%s", $row['banreason']);
    $output->rawOutput("</td><td>");
    $file = "user.php?op=removeban&subop=xml&ip={$row['ipfilter']}&id={$row['uniqueid']}";
    $output->rawOutput("<div id='user$i'><a href='$file' target='_blank' onClick=\"getUserInfo('{$row['ipfilter']}','{$row['uniqueid']}',$i); return false;\">");
    $output->outputNotl("%s", $showuser, true);
    $output->rawOutput("</a></div>");
    Nav::add("", $file);
    $output->rawOutput("</td><td>");
    $output->outputNotl("%s", relativedate($row['lasthit']));
    $output->rawOutput("</td></tr>");
    $i++;
}
$output->rawOutput("</table>");
