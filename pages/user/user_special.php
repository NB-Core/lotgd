<?php

declare(strict_types=1);


if (httppost("newday") != "") {
#   $offset = "-".(24 / (int)getsetting("daysperday",4))." hours";
#   $newdate = date("Y-m-d H:i:s",strtotime($offset));
#   $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") . " SET lasthit='$newdate' WHERE acctid='$userid'";
       $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") . " SET lasthit='" . DATETIME_DATEMIN . "' WHERE acctid=" . (int)$userid;
    \Lotgd\MySQL\Database::query($sql);
} elseif (httppost("fixnavs") != "") {
       $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") . " SET allowednavs='', restorepage='', specialinc='' WHERE acctid=" . (int)$userid;
    \Lotgd\MySQL\Database::query($sql);
       $sql = "DELETE FROM " . \Lotgd\MySQL\Database::prefix("accounts_output") . " WHERE acctid=" . (int)$userid . ";";
    \Lotgd\MySQL\Database::query($sql);
} elseif (httppost("clearvalidation") != "") {
       $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") . " SET emailvalidation='' WHERE acctid=" . (int)$userid;
    \Lotgd\MySQL\Database::query($sql);
}
$op = "edit";
httpset("op", "edit");
