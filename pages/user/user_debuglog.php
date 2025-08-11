<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Sanitize;

if ($petition != "") {
    Nav::add("Navigation");
    Nav::add("Return to the petition", "viewpetition.php?op=view&id=$petition");
}
$debuglog = Database::prefix('debuglog');
$debuglog_archive = Database::prefix('debuglog_archive');
$accounts = Database::prefix('accounts');


// As mySQL cannot use two different indexes in a single query this query can take up to 25s on its own!
// This happens solely on larger debuglogs (where full table scans take quite long), smaller servers
// should not recognize a change.
// It may seem strange, but in this case two single queries are better!
// $sql = "SELECT count(id) AS c FROM $debuglog WHERE actor=$userid OR target=$userid";

$sql = "SELECT COUNT(id) AS c FROM $debuglog WHERE target=$userid";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max = $row['c'];

$sql = "SELECT COUNT(id) AS c FROM $debuglog WHERE actor=$userid";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max += $row['c'];

$sql = "SELECT COUNT(id) AS c FROM $debuglog_archive WHERE target=$userid";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max += $row['c'];

$sql = "SELECT COUNT(id) AS c FROM $debuglog_archive WHERE actor=$userid";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max += $row['c'];


$start = (int)httpget('start');

$sql = "(
                        SELECT {$debuglog}.*,
                                CAST({$debuglog}.field AS CHAR)   AS field_cast,
                                CAST({$debuglog}.value AS CHAR)   AS value_cast,
                                CAST({$debuglog}.message AS CHAR) AS message_cast,
                                CAST(a1.name AS CHAR)             AS actorname,
                                CAST(a2.name AS CHAR)             AS targetname
                                FROM {$debuglog}
                                LEFT JOIN {$accounts} AS a1 ON a1.acctid = {$debuglog}.actor
                                LEFT JOIN {$accounts} AS a2 ON a2.acctid = {$debuglog}.target
                                WHERE {$debuglog}.actor = {$userid}
                ) UNION (
                        SELECT {$debuglog}.*,
                                CAST({$debuglog}.field AS CHAR)   AS field,
                                CAST({$debuglog}.value AS CHAR)   AS value,
                                CAST({$debuglog}.message AS CHAR) AS message,
                                CAST(a2.name AS CHAR)             AS targetname,
                                CAST(a1.name AS CHAR)             AS actorname
                                FROM {$debuglog}
                                LEFT JOIN {$accounts} AS a1 ON a1.acctid = {$debuglog}.actor
                                LEFT JOIN {$accounts} AS a2 ON a2.acctid = {$debuglog}.target
                                WHERE {$debuglog}.target = {$userid}
                ) UNION (
                        SELECT {$debuglog_archive}.*,
                                CAST({$debuglog_archive}.field AS CHAR)   AS field,
                                CAST({$debuglog_archive}.value AS CHAR)   AS value,
                                CAST({$debuglog_archive}.message AS CHAR) AS message,
                                CAST(a1.name AS CHAR)                    AS actorname,
                                CAST(a2.name AS CHAR)                    AS targetname
                                FROM {$debuglog_archive}
                                LEFT JOIN {$accounts} AS a1 ON a1.acctid = {$debuglog_archive}.actor
                                LEFT JOIN {$accounts} AS a2 ON a2.acctid = {$debuglog_archive}.target
                                WHERE {$debuglog_archive}.actor = {$userid}
                ) UNION (
                        SELECT {$debuglog_archive}.*,
                                CAST({$debuglog_archive}.field AS CHAR)   AS field,
                                CAST({$debuglog_archive}.value AS CHAR)   AS value,
                                CAST({$debuglog_archive}.message AS CHAR) AS message,
                                CAST(a2.name AS CHAR)                    AS targetname,
                                CAST(a1.name AS CHAR)                    AS actorname
                                FROM {$debuglog_archive}
                                LEFT JOIN {$accounts} AS a1 ON a1.acctid = {$debuglog_archive}.actor
                                LEFT JOIN {$accounts} AS a2 ON a2.acctid = {$debuglog_archive}.target
                                WHERE {$debuglog_archive}.target = {$userid}
                )

                ORDER BY date DESC
                LIMIT {$start},500";

$next = $start + 500;
$prev = $start - 500;
Nav::add("Operations");
Nav::add("Edit user info", "user.php?op=edit&userid=$userid$returnpetition");
Nav::add("Refresh", "user.php?op=debuglog&userid=$userid&start=$start$returnpetition");
Nav::add("Debug Log");
if ($next < $max) {
    Nav::add("Next page", "user.php?op=debuglog&userid=$userid&start=$next$returnpetition");
}
if ($start > 0) {
    Nav::add(
        "Previous page",
        "user.php?op=debuglog&userid=$userid&start=$prev$returnpetition"
    );
}
$result = Database::query($sql);
$odate = "";
while ($row = Database::fetchAssoc($result)) {
    $dom = date("D, M d", strtotime($row['date']));
    if ($odate != $dom) {
        $output->outputNotl("`n`b`@%s`0`b`n", $dom);
        $odate = $dom;
    }
    $time = date("H:i:s", strtotime($row['date'])) . " (" . reltime(strtotime($row['date'])) . ")";
    $output->outputNotl(
        "`#%s (%s) `^%s - `&%s`7 %s`0",
        Sanitize::sanitize((string)$row['field']),
        Sanitize::sanitize((string)$row['value']),
        $time,
        Sanitize::sanitize((string)$row['actorname']),
        Sanitize::sanitize((string)$row['message'])
    );
    if ($row['target']) {
        $output->output(" \-- Recipient = `\$%s`0", Sanitize::sanitize((string)$row['targetname']));
    }
    $output->outputNotl("`n");
}
