<?php

declare(strict_types=1);

use Lotgd\Cookies;
use Lotgd\MySQL\Database;
use Lotgd\Http;
use Lotgd\Output;

$sql = 'INSERT INTO ' . Database::prefix('bans') . ' (banner,';
$output = Output::getInstance();
$type = (string) Http::post('type');
if ($type == "ip") {
    $sql .= "ipfilter";
    $key = "lastip";
    $key_value = Http::post('ip');
} else {
    $sql .= "uniqueid";
    $key = "uniqueid";
    $key_value = Http::post('id');
}
$sql .= ",banexpire,banreason) VALUES ('" . addslashes($session['user']['name']) . "',";
if ($type == "ip") {
    $sql .= "\"" . Http::post('ip') . "\"";
} else {
    $sql .= "\"" . Http::post('id') . "\"";
}
$duration = (int) Http::post('duration');
if ($duration == 0) {
    $duration = DATETIME_DATEMAX;
} else {
    $duration = date("Y-m-d", strtotime("+$duration days"));
}
    $sql .= ",\"$duration\",";
$sql .= "\"" . Http::post('reason') . "\")";
if ($type == "ip") {
    if (
        substr($_SERVER['REMOTE_ADDR'], 0, strlen((string) Http::post('ip'))) ==
            Http::post('ip')
    ) {
        $sql = '';
        $output->output("You don't really want to ban yourself now do you??");
        $output->output("That's your own IP address!");
    }
} else {
    if (Cookies::getLgi() == Http::post('id')) {
            $sql = '';
            $output->output("You don't really want to ban yourself now do you??");
            $output->output("That's your own ID!");
    }
}
if ($sql != "") {
    $result = Database::query($sql);
    $output->output("%s ban rows entered.`n`n", Database::affectedRows());
    $output->outputNotl(Database::error());
    debuglog('entered a ban: ' . ($type == 'ip' ? 'IP: ' . Http::post('ip') : 'ID: ' . Http::post('id')) . " Ends after: $duration  Reason: \"" . Http::post('reason') . '\"');
    /* log out affected players */
    $sql = "SELECT acctid FROM " . Database::prefix('accounts') . " WHERE $key='$key_value'";
    $result = Database::query($sql);
    $acctids = array();
    while ($row = Database::fetchAssoc($result)) {
        $acctids[] = $row['acctid'];
    }
    if ($acctids != array()) {
        $sql = ' UPDATE ' . Database::prefix('accounts') . ' SET loggedin=0 WHERE acctid IN (' . implode(',', $acctids) . ')';
        $result = Database::query($sql);
        if ($result) {
            $output->output("`\$%s people have been logged out!`n`n`0", Database::affectedRows());
        } else {
            $output->output("`\$Nobody was logged out. Acctids (%s) did not return rows!`n`n`0", implode(',', $acctids));
        }
    } else {
        $output->output("`\$No account-ids found for that IP/ID!`n`n`0");
    }
}
