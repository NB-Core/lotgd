<?php

declare(strict_types=1);

namespace Lotgd\Repository;

use Doctrine\DBAL\ParameterType;
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
        $connection = Database::getDoctrineConnection();
        $row = $connection->fetchAssociative(
            'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );
        if ($row !== false && isset($row['name'])) {
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
        $connection = Database::getDoctrineConnection();
        return $connection->executeQuery(
            'SELECT count(acctid) AS c, clanrank FROM ' . Database::prefix('accounts')
            . ' WHERE clanid = :clanId GROUP BY clanrank ORDER BY clanrank DESC',
            ['clanId' => $clanId],
            ['clanId' => ParameterType::INTEGER]
        )->fetchAllAssociative();
    }

    /**
     * Get the highest ranking member of a clan.
     *
     * @return array<string, int|string>|null
     */
    public static function getHighestRankingMember(int $clanId): ?array
    {
        $connection = Database::getDoctrineConnection();
        $row = $connection->executeQuery(
            'SELECT name,acctid,clanrank FROM ' . Database::prefix('accounts')
            . ' WHERE clanid = :clanId AND clanrank > :applicantRank ORDER BY clanrank DESC, clanjoindate',
            [
                'clanId' => $clanId,
                'applicantRank' => CLAN_APPLICANT,
            ],
            [
                'clanId' => ParameterType::INTEGER,
                'applicantRank' => ParameterType::INTEGER,
            ]
        )->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Promote an account to clan leader.
     */
    public static function promoteToLeader(int $acctid): void
    {
        $connection = Database::getDoctrineConnection();
        $connection->executeStatement(
            'UPDATE ' . Database::prefix('accounts') . ' SET clanrank = :leaderRank WHERE acctid = :acctid',
            [
                'leaderRank' => CLAN_LEADER,
                'acctid' => $acctid,
            ],
            [
                'leaderRank' => ParameterType::INTEGER,
                'acctid' => ParameterType::INTEGER,
            ]
        );
    }
}
