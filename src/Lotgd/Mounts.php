<?php
declare(strict_types=1);
namespace Lotgd;

/**
 * Access to mount data.
 */
class Mounts
{
    /**
     * Retrieve mount information from the database.
     *
     * @param int $horse Mount id
     *
     * @return array<string,mixed>
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
