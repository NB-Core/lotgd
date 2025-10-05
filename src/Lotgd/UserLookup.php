<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\PlayerSearch;

use function trigger_deprecation;

class UserLookup
{
    private const DEFAULT_FIELDS = [
        'acctid',
        'login',
        'name',
        'level',
        'laston',
        'loggedin',
        'gentimecount',
        'gentime',
        'lastip',
        'uniqueid',
        'emailaddress',
    ];

    /**
     * Search for users matching the given criteria.
     *
     * @param string|false $query  Search string
     * @param string|false $order  Order clause
     * @param string|false $fields Fields to retrieve
     * @param string|false $where  Optional where clause
     *
     * @return array{0:mixed,1:string} Database result resource and error message
     *
     * @deprecated since 2.9.0. Use {@see PlayerSearch::legacyLookup()} or dedicated
     *             PlayerSearch helpers directly.
     */
    public static function lookup(string|false $query = false, string|false $order = false, string|false $fields = false, string|false $where = false): array
    {
        trigger_deprecation('lotgd/core', '2.9.0', 'Lotgd\\UserLookup::lookup() is deprecated. Use Lotgd\\PlayerSearch::legacyLookup() instead.');

        if ($query === false) {
            return [false, ''];
        }

        $fieldsInfo = self::normaliseFields($fields);

        if ($where !== false) {
            return self::runWhereQuery($fieldsInfo['sql'], $order, $where);
        }

        $search = trim((string) $query);

        if ($search === '') {
            return [[], ''];
        }

        $playerSearch = new PlayerSearch();
        $result = $playerSearch->legacyLookup(
            $search,
            $fieldsInfo['columns'],
            is_string($order) && $order !== '' ? $order : null
        );

        return [$result['rows'], $result['error']];
    }

    /**
     * @return array{columns: array<int|string, string>, sql: string}
     */
    private static function normaliseFields(string|false $fields): array
    {
        if ($fields === false) {
            return [
                'columns' => self::DEFAULT_FIELDS,
                'sql'      => implode(',', self::DEFAULT_FIELDS),
            ];
        }

        $columns = [];
        $sqlParts = [];

        foreach (explode(',', $fields) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            $sqlParts[] = $piece;

            if (stripos($piece, ' as ') !== false) {
                [$column, $alias] = preg_split('/\s+as\s+/i', $piece, 2);
                $columns[$column] = $alias;
            } else {
                $columns[] = $piece;
            }
        }

        if ($columns === []) {
            $columns = self::DEFAULT_FIELDS;
            $sqlParts = self::DEFAULT_FIELDS;
        }

        return [
            'columns' => $columns,
            'sql'      => implode(', ', $sqlParts),
        ];
    }

    private static function runWhereQuery(string $fieldsSql, string|false $order, string $where): array
    {
        $orderSql = '';
        if (is_string($order) && trim($order) !== '') {
            $orderSql = ' ORDER BY ' . trim($order);
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s%s',
            $fieldsSql,
            Database::prefix('accounts'),
            $where,
            $orderSql
        );

        $result = Database::query($sql);
        $rows = [];

        if ($result !== false) {
            while (($row = Database::fetchAssoc($result)) !== false && $row !== null) {
                $rows[] = $row;
            }
        }

        $error = $rows === [] ? "`\$No results found`0" : '';

        return [$rows, $error];
    }
}
