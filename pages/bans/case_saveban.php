<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Lotgd\Cookies;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Output;

$output = Output::getInstance();
$type = (string) Http::post('type');
$conn = Database::getDoctrineConnection();
global $session;

$banner = (string) ($session['user']['name'] ?? '');
$targetIdentifier = $type === 'ip' ? (string) Http::post('ip') : (string) Http::post('id');
$reason = (string) Http::post('reason');
$durationInput = (int) Http::post('duration');

if ($durationInput === 0) {
    $duration = DATETIME_DATEMAX;
} else {
    $duration = date('Y-m-d', strtotime(sprintf('+%d days', $durationInput)));
}

if ($type === 'ip') {
    $column = 'ipfilter';
    $key = 'lastip';
    $ipValue = $targetIdentifier;
    $uniqueIdValue = '';
    $isSelfBan = substr($_SERVER['REMOTE_ADDR'], 0, strlen($targetIdentifier)) === $targetIdentifier;
} else {
    $column = 'uniqueid';
    $key = 'uniqueid';
    $ipValue = '';
    $uniqueIdValue = $targetIdentifier;
    $isSelfBan = Cookies::getLgi() === $targetIdentifier;
}

if ($isSelfBan) {
    $output->output("You don't really want to ban yourself now do you??");
    $output->output($type === 'ip' ? "That's your own IP address!" : "That's your own ID!");

    return;
}

$sql = 'INSERT INTO ' . Database::prefix('bans') . ' (banner, ipfilter, uniqueid, banexpire, banreason) VALUES (?, ?, ?, ?, ?)';
$parameters = [$banner, $ipValue, $uniqueIdValue, $duration, $reason];
$affected = $conn->executeStatement($sql, $parameters);
Database::setAffectedRows($affected);

$output->output("%s ban rows entered.`n`n", Database::affectedRows());
$output->outputNotl(Database::error());
debuglog('entered a ban: ' . ($type === 'ip' ? 'IP: ' . $targetIdentifier : 'ID: ' . $targetIdentifier) . " Ends after: $duration  Reason: \"" . $reason . '\"');

/* log out affected players */
$selectSql = 'SELECT acctid FROM ' . Database::prefix('accounts') . " WHERE {$key} = :value";
$accounts = $conn->fetchAllAssociative($selectSql, ['value' => $targetIdentifier]);

$acctids = array_map(static fn (array $row): int => (int) $row['acctid'], $accounts);

if ($acctids !== []) {
    $updateSql = 'UPDATE ' . Database::prefix('accounts') . ' SET loggedin = 0 WHERE acctid IN (?)';
    $updated = $conn->executeStatement($updateSql, [$acctids], [ArrayParameterType::INTEGER]);
    Database::setAffectedRows($updated);

    if ($updated > 0) {
        $output->output("`\$%s people have been logged out!`n`n`0", Database::affectedRows());
    } else {
        $output->output("`\$Nobody was logged out. Acctids (%s) did not return rows!`n`n`0", implode(',', $acctids));
    }
} else {
    $output->output("`\$No account-ids found for that IP/ID!`n`n`0");
}
