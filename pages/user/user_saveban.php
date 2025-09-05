<?php

declare(strict_types=1);

use Lotgd\Cookies;
use Lotgd\MySQL\Database;
use Lotgd\Output;

$sql = "INSERT INTO " . Database::prefix("bans") . " (banner,";
$output = Output::getInstance();
$type = httppost("type");
if ($type == "ip") {
    $sql .= "ipfilter";
    $key = "lastip";
    $key_value = httppost('ip');
} else {
    $sql .= "uniqueid";
    $key = "uniqueid";
    $key_value = httppost('id');
}
$sql .= ",banexpire,banreason) VALUES ('" . addslashes($session['user']['name']) . "',";
if ($type == "ip") {
    $sql .= "\"" . httppost("ip") . "\"";
} else {
    $sql .= "\"" . httppost("id") . "\"";
}
$duration = (int)httppost("duration");
if ($duration == 0) {
    $duration = DATETIME_DATEMIN;
} else {
    $duration = date("Y-m-d", strtotime("+$duration days"));
}
    $sql .= ",\"$duration\",";
$sql .= "\"" . httppost("reason") . "\")";
if ($type == "ip") {
    if (
        substr($_SERVER['REMOTE_ADDR'], 0, strlen(httppost("ip"))) ==
            httppost("ip")
    ) {
        $sql = "";
        $output->output("You don't really want to ban yourself now do you??");
        $output->output("That's your own IP address!");
    }
} else {
    if (Cookies::getLgi() == httppost("id")) {
            $sql = "";
            $output->output("You don't really want to ban yourself now do you??");
            $output->output("That's your own ID!");
    }
}
if ($sql != "") {
    $result = Database::query($sql);
    $output->output("%s ban rows entered.`n`n", Database::affectedRows());
    $output->outputNotl("%s", Database::error());
    debuglog("entered a ban: " .  ($type == "ip" ?  "IP: " . httppost("ip") : "ID: " . httppost("id")) . " Ends after: $duration  Reason: \"" .  httppost("reason") . "\"");
    /* log out affected players */
    $sql = "SELECT acctid FROM " . Database::prefix('accounts') . " WHERE $key='$key_value'";
    $result = Database::query($sql);
    $acctids = array();
    while ($row = Database::fetchAssoc($result)) {
        $acctids[] = $row['acctid'];
    }
    if ($acctids != array()) {
        $sql = " UPDATE " . Database::prefix('accounts') . " SET loggedin=0 WHERE acctid IN (" . implode(",", $acctids) . ")";
        $result = Database::query($sql);
        if ($result) {
            $output->output("`\$%s people have been logged out!`n`n`0", Database::affectedRows());
        } else {
            $output->output("`\$Nobody was logged out. Acctids (%s) did not return rows!`n`n`0", implode(",", $acctids));
        }
    } else {
        $output->output("`\$No account-ids found for that IP/ID!`n`n`0");
    }
}
