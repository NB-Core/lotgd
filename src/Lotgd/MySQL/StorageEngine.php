<?php

declare(strict_types=1);

namespace Lotgd\MySQL;

use Doctrine\DBAL\ArrayParameterType;

/**
 * Which tables can take part in a transaction.
 *
 * An installation upgraded from an old release may still hold some tables in
 * MyISAM. A transaction cannot roll a write to such a table back, and
 * SELECT … FOR UPDATE takes no row lock on it, so a guarded action that
 * touched one would only look atomic.
 */
final class StorageEngine
{
    private const TRANSACTIONAL = ['innodb', 'xtradb', 'aria'];

    /**
     * The given tables (without prefix) that are missing or not transactional.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    public static function nonTransactional(array $tables): array
    {
        $names = array_map(static fn (string $table): string => Database::prefix($table), $tables);
        $rows = Database::getDoctrineConnection()->fetchAllAssociative(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:names)',
            ['names' => $names],
            ['names' => ArrayParameterType::STRING]
        );

        $engines = [];
        foreach ($rows as $row) {
            $engines[(string) ($row['TABLE_NAME'] ?? '')] = strtolower((string) ($row['ENGINE'] ?? ''));
        }

        $missing = [];
        foreach ($tables as $index => $table) {
            if (!in_array($engines[$names[$index]] ?? '', self::TRANSACTIONAL, true)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }
}
