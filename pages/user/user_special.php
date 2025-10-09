<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Http;

if (Http::post('newday') !== false) {
#   $offset = '-' . (24 / (int) \Lotgd\Settings::getInstance()->getSetting('daysperday', 4)) . ' hours';
#   $newdate = date("Y-m-d H:i:s",strtotime($offset));
#   $sql = "UPDATE " . Database::prefix("accounts") . " SET lasthit='$newdate' WHERE acctid='$userid'";
    $sql = "UPDATE " . Database::prefix("accounts") . " SET lasthit='" . DATETIME_DATEMIN . "' WHERE acctid=" . (int)$userid;
    Database::query($sql);
} elseif (Http::post('fixnavs') !== false) {
    $sql = "UPDATE " . Database::prefix("accounts") . " SET allowednavs='', restorepage='', specialinc='' WHERE acctid=" . (int)$userid;
    Database::query($sql);
    $sql = "DELETE FROM " . Database::prefix("accounts_output") . " WHERE acctid=" . (int)$userid . ";";
    Database::query($sql);
} elseif (Http::post('clearvalidation') !== false) {
    $sql = "UPDATE " . Database::prefix("accounts") . " SET emailvalidation='' WHERE acctid=" . (int)$userid;
    Database::query($sql);
}
$op = "edit";
Http::set('op', 'edit');
