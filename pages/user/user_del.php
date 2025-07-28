<?php
declare(strict_types=1);

use Lotgd\PlayerFunctions;
use Lotgd\Translator;

$sql = "SELECT name,superuser from " . db_prefix("accounts") . " WHERE acctid='$userid'";
$res = db_query($sql);
PlayerFunctions::charCleanup($userid, CHAR_DELETE_MANUAL);
$fail = false;
while ($row = db_fetch_assoc($res)) {
    if ($row['superuser'] > 0 && ($session['user']['superuser'] & SU_MEGAUSER) != SU_MEGAUSER) {
        $output->output("`\$You are trying to delete a user with superuser powers. Regardless of the type, ONLY a megauser can do so due to security reasons.");
        $fail = true;
        break;
    }
    AddNews::add("`#%s was unmade by the gods.", $row['name'], true);
    debuglog("deleted user" . $row['name'] . "'0");
}
if ($fail !== true) {
    $sql = "DELETE FROM " . db_prefix("accounts") . " WHERE acctid='$userid'";
    db_query($sql);
    $output->output(db_affected_rows() . " user deleted.");
}
