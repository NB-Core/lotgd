<?php

declare(strict_types=1);

namespace Lotgd\Repository;

use Lotgd\MySQL\Database;

/**
 * Repository with helper methods for clan-related database operations.
 */
class ClanRepository
{
    /**
     * Fetch the account name for the given account id.
     */
    public static function fetchAccountName(int $acctid): ?string
    {
        $sql = 'SELECT name FROM ' . Database::prefix('accounts') . " WHERE acctid={$acctid}";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            return $row['name'];
        }

        return null;
    }

    /**
     * Count members of a clan grouped by their rank.
     *
     * @return array<int, array<string, int>>
     */
    public static function countMembersByRank(int $clanId): array
    {
        $sql = 'SELECT count(acctid) AS c, clanrank FROM ' . Database::prefix('accounts') . " WHERE clanid={$clanId} GROUP BY clanrank ORDER BY clanrank DESC";
        $result = Database::query($sql);
        $rows = [];
        while ($row = Database::fetchAssoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get the highest ranking member of a clan.
     *
     * @return array<string, int|string>|null
     */
    public static function getHighestRankingMember(int $clanId): ?array
    {
        $sql = 'SELECT name,acctid,clanrank FROM ' . Database::prefix('accounts') . ' WHERE clanid=' . $clanId . ' AND clanrank > ' . CLAN_APPLICANT . ' ORDER BY clanrank DESC, clanjoindate';
        $result = Database::query($sql);
        if (Database::numRows($result)) {
            return Database::fetchAssoc($result);
        }

        return null;
    }

    /**
     * Promote an account to clan leader.
     */
    public static function promoteToLeader(int $acctid): void
    {
        $sql = 'UPDATE ' . Database::prefix('accounts') . ' SET clanrank=' . CLAN_LEADER . " WHERE acctid={$acctid}";
        Database::query($sql);
    }
}
