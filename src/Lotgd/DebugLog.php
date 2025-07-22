<?php

declare(strict_types=1);

/**
 * Write entries to the user debug log.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;

class DebugLog
{
    /**
     * Add an entry to the debug log.
     *
     * @param string      $message Text to store
     * @param int|false    $target  Target account id
     * @param int|false    $user    Account id the entry is about
     * @param string|false $field   Optional label
     * @param int|false    $value   Optional value
     * @param bool         $consolidate Consolidate values for same day
     */
    public static function add(string $message, int|false $target = false, int|false $user = false, string|false $field = false, int|false $value = false, bool $consolidate = true): void
    {
        if ($target === false) {
            $target = 0;
        }
        global $session;

        if ($user === false) {
            $user = $session['user']['acctid'];
        }
        $corevalue = $value;
        $id = 0;
        if ($field !== false && $value !== false && $consolidate) {
            $sql = "SELECT * FROM " . Database::prefix('debuglog') . " WHERE actor=$user AND field='$field' AND date>'" . date('Y-m-d 00:00:00') . "'";
            $result = Database::query($sql);
            if (Database::numRows($result) > 0) {
                $row = Database::fetchAssoc($result);
                $value = $row['value'] + $value;
                $message = $row['message'];
                $id = $row['id'];
            }
        }
        if ($corevalue !== false) {
            $message .= " ($corevalue)";
        }
        if ($field === false) {
            $field = '';
        }
        if ($value === false) {
            $value = 0;
        }
        if ($id > 0) {
            $sql = "UPDATE " . Database::prefix('debuglog') . " SET date='" . date('Y-m-d H:i:s') . "', actor='$user', target='$target', message='" . addslashes($message) . "', field='$field', value='$value' WHERE id=$id";
        } else {
            $sql = "INSERT INTO " . Database::prefix('debuglog') . " (id,date,actor,target,message,field,value) VALUES($id,'" . date('Y-m-d H:i:s') . "',$user,$target,'" . addslashes($message) . "','$field','$value')";
        }
        Database::query($sql);
    }
}
