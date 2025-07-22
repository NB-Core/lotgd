<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;

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
        $sql = 'SELECT * FROM ' . Database::prefix('mounts') . " WHERE mountid='$horse'";
        $result = Database::queryCached($sql, "mountdata-$horse", 3600);
        if (Database::numRows($result) > 0) {
            return Database::fetchAssoc($result);
        }
        return [];
    }
}
