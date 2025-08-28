<?php

declare(strict_types=1);

namespace Lotgd\Legacy;

final class LegacySql
{
    /**
     * Filter legacy SQL statements for versions newer than the provided one.
     *
     * @param array<string,array<int,string>> $statements
     * @return string[]
     */
    public static function filterStatements(array $statements, string $from): array
    {
        uksort($statements, 'version_compare');

        $result = [];
        foreach ($statements as $version => $sqls) {
            if (version_compare((string) $version, $from, '>')) {
                $result = array_merge($result, $sqls);
            }
        }

        return $result;
    }
}
