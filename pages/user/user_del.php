<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\PlayerFunctions;
use Lotgd\Security\Csrf;
use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\AddNews;
use Lotgd\GameLog;

// Deleting an account was a plain GET reached from a link in the user list, so
// an admin who followed a crafted URL removed the account. The trigger is a
// POST form now; this is the check that makes that mean something.
if (!Csrf::validatePostRequest(Csrf::SCOPE_USER_EDITOR)) {
    debuglog('Rejected user deletion with an invalid CSRF token.');
    http_response_code(400);
    $output->output("`\$Not deleted.`0`n");

    return;
}

$connection = Database::getDoctrineConnection();
$res = $connection->executeQuery(
    'SELECT name, superuser FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
    ['acctid' => $userid],
    ['acctid' => ParameterType::INTEGER]
);
$row = Database::fetchAssoc($res);

if (!is_array($row)) {
    $output->output("`\$No such user.`0`n");

    return;
}

// This check used to run *after* charCleanup(), which meant refusing the
// deletion still left the account stripped of its comments, output cache and
// clan membership -- the guard destroyed exactly what it was there to protect.
// Nothing is removed until it has passed.
if ($row['superuser'] > 0 && ($session['user']['superuser'] & SU_MEGAUSER) != SU_MEGAUSER) {
    $output->output("`\$You are trying to delete a user with superuser powers. Regardless of the type, ONLY a megauser can do so due to security reasons.");

    return;
}

if (!PlayerFunctions::charCleanup($userid, CHAR_DELETE_MANUAL)) {
    return;
}

$username = $row['name'];
AddNews::add("`#%s was unmade by the gods.", $username, true);
debuglog("deleted user" . $username . "'0");

// The row count comes from the statement itself: Database::affectedRows()
// reports what Database::query() last recorded, which a Doctrine statement
// never updates, so asking it here would print a stale number.
$deleted = (int) $connection->executeStatement(
    'DELETE FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
    ['acctid' => $userid],
    ['acctid' => ParameterType::INTEGER]
);
$output->output($deleted . " user deleted.");
GameLog::log(
    'User ' . $userid . ' (' . $username . ') deleted by ' . $session['user']['acctid'],
    'user management'
);
