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
        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('debuglog');

        if ($field !== false && $value !== false && $consolidate) {
            $row = $conn->fetchAssociative(
                "SELECT id, value, message FROM {$table} WHERE actor = :actor AND field = :field AND date > :after",
                [
                    'actor' => $user,
                    'field' => $field,
                    'after' => date('Y-m-d 00:00:00'),
                ]
            );

            if ($row !== false) {
                $value = (int) $row['value'] + (int) $value;
                $message = (string) $row['message'];
                $id = (int) $row['id'];
            }
        }

        if ($corevalue !== false) {
            $message .= " ($corevalue)";
        }

        $params = [
            'id'      => $id,
            'date'    => date('Y-m-d H:i:s'),
            'actor'   => $user,
            'target'  => $target,
            'message' => $message,
            'field'   => $field === false ? '' : $field,
            'value'   => $value === false ? 0 : $value,
        ];

        if ($id > 0) {
            $conn->executeStatement(
                "UPDATE {$table} SET date = :date, actor = :actor, target = :target, message = :message, field = :field, value = :value WHERE id = :id",
                $params
            );
        } else {
            $conn->executeStatement(
                "INSERT INTO {$table} (id, date, actor, target, message, field, value) VALUES (:id, :date, :actor, :target, :message, :field, :value)",
                $params
            );
        }
    }
}
