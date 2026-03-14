<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Bridge around legacy global functions so modern namespaced services can be statically analysed.
 */
class LegacyBridge
{
    public static function getSetting(string $name, string $default = ''): string
    {
        if (function_exists('getsetting')) {
            return (string) getsetting($name, $default);
        }

        return $default;
    }

    public static function hasDatabaseApi(): bool
    {
        return function_exists('db_prefix')
            && function_exists('db_query')
            && function_exists('db_fetch_assoc')
            && function_exists('db_real_escape_string')
            && function_exists('db_affected_rows');
    }

    public static function dbPrefix(string $table): string
    {
        return function_exists('db_prefix') ? (string) db_prefix($table) : $table;
    }

    public static function dbQuery(string $sql): mixed
    {
        return function_exists('db_query') ? db_query($sql) : false;
    }

    /**
     * @param mixed $result
     *
     * @return array<string, mixed>|false
     */
    public static function dbFetchAssoc(mixed &$result): array|false
    {
        if (!function_exists('db_fetch_assoc')) {
            return false;
        }

        return db_fetch_assoc($result);
    }

    public static function dbEscape(string $value): string
    {
        return function_exists('db_real_escape_string') ? (string) db_real_escape_string($value) : $value;
    }

    public static function dbAffectedRows(): int
    {
        return function_exists('db_affected_rows') ? (int) db_affected_rows() : 0;
    }
}
