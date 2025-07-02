<?php
namespace Lotgd;

/**
 * Access to mount data.
 */
class Mounts
{
    /**
     * Retrieve mount information from the database.
     */
    public static function getmount(int $horse = 0): array
    {
        $sql = 'SELECT * FROM ' . db_prefix('mounts') . " WHERE mountid='$horse'";
        $result = db_query_cached($sql, "mountdata-$horse", 3600);
        if (db_num_rows($result) > 0) {
            return db_fetch_assoc($result);
        }
        return [];
    }
}
