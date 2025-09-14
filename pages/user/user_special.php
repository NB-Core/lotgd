<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;

if (httppost("newday") != "") {
#   $offset = "-".(24 / (int)getsetting("daysperday",4))." hours";
#   $newdate = date("Y-m-d H:i:s",strtotime($offset));
#   $sql = "UPDATE " . Database::prefix("accounts") . " SET lasthit='$newdate' WHERE acctid='$userid'";
       $sql = "UPDATE " . Database::prefix("accounts") . " SET lasthit='" . DATETIME_DATEMIN . "' WHERE acctid=" . (int)$userid;
    Database::query($sql);
} elseif (httppost("fixnavs") != "") {
       $sql = "UPDATE " . Database::prefix("accounts") . " SET allowednavs='', restorepage='', specialinc='' WHERE acctid=" . (int)$userid;
    Database::query($sql);
       $sql = "DELETE FROM " . Database::prefix("accounts_output") . " WHERE acctid=" . (int)$userid . ";";
    Database::query($sql);
} elseif (httppost("clearvalidation") != "") {
       $sql = "UPDATE " . Database::prefix("accounts") . " SET emailvalidation='' WHERE acctid=" . (int)$userid;
    Database::query($sql);
}
$op = "edit";
httpset("op", "edit");
