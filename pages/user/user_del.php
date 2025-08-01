<?php

declare(strict_types=1);

use Lotgd\PlayerFunctions;
use Lotgd\Translator;
use Lotgd\MySQL\Database;

$sql = "SELECT name,superuser from " . Database::prefix("accounts") . " WHERE acctid='$userid'";
$res = Database::query($sql);
PlayerFunctions::charCleanup($userid, CHAR_DELETE_MANUAL);
$fail = false;
while ($row = Database::fetchAssoc($res)) {
    if ($row['superuser'] > 0 && ($session['user']['superuser'] & SU_MEGAUSER) != SU_MEGAUSER) {
        $output->output("`\$You are trying to delete a user with superuser powers. Regardless of the type, ONLY a megauser can do so due to security reasons.");
        $fail = true;
        break;
    }
    AddNews::add("`#%s was unmade by the gods.", $row['name'], true);
    debuglog("deleted user" . $row['name'] . "'0");
}
if ($fail !== true) {
    $sql = "DELETE FROM " . Database::prefix("accounts") . " WHERE acctid='$userid'";
    Database::query($sql);
    $output->output(Database::affectedRows() . " user deleted.");
}
