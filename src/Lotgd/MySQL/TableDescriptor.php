<?php

declare(strict_types=1);

namespace Lotgd\MySQL;

use Lotgd\MySQL\Database;
use Lotgd\Output;
use RuntimeException;

use const DATETIME_DATEMIN;

/**
 * Helper for creating, reading and synchronising table descriptors.
 *
 * The class is mostly used by the installer and upgrade routines to keep the
 * database schema in step with the descriptors stored in the source code.
 */
class TableDescriptor
{
// translator ready
// addnews ready
// mail ready
//
//functions to pay attention to in this script:
// synctable() ensures that a table in the database matches the
// descriptor it's passed.
// table_create_descriptor() creates a descriptor from an existing table
// in the database.
// table_create_from_descriptor() writes SQL to create the table described
// by the descriptor.
//
// There's no support for foreign keys that INNODB offers.  Sorry.

    /**
     * MySQL reserved words that require quoting in generated SQL.
     */
    private const RESERVED_WORDS = ['function', 'table', 'key'];

    /**
     * Synchronise a table with the provided descriptor.
     *
     * @param string $tablename  Fully qualified table name.
     * @param array  $descriptor Schema description to match against.
     * @param bool   $nodrop     If true, columns not in the descriptor are left intact.
     *
     * @return int|null Number of schema changes applied. Creating a missing table counts as 1. Returns null when no changes are required or creation failed.
     */
    public static function synctable(string $tablename, array $descriptor, bool $nodrop = false): ?int
    {
    //table names should be Database::prefix'd before they get in to
    //this function.
        if (!Database::tableExists($tablename)) {
            //the table doesn't exist, so we create it and are done.
            reset($descriptor);
            $sql = self::tableCreateFromDescriptor($tablename, $descriptor);
            error_log($sql);
            if (!Database::query($sql)) {
                throw new RuntimeException(Database::error());
            }

            $output = Output::getInstance();
            $output->output("`^Table `#%s`^ created.`n", $tablename);

            return 1;
        } else {
            //the table exists, so we need to compare it against the descriptor.
            $existing = self::tableCreateDescriptor($tablename);
            $existingColumns = $existing;
            $tableCharset = $descriptor['charset'] ?? null;
            $tableCollation = $descriptor['collation'] ?? null;
            // Determine target table charset and collation: prefer explicit values
            // from the descriptor, otherwise derive charset from the collation or
            // fall back to UTF‑8 defaults.
            if (
                $tableCharset
                && $tableCollation
                && strpos($tableCollation, $tableCharset . '_') !== 0
            ) {
                throw new \InvalidArgumentException(
                    "Table charset '$tableCharset' and collation '$tableCollation' are incompatible."
                );
            }
            if (!$tableCharset && $tableCollation) {
                // Pull charset from a supplied collation (eg. utf8mb4_unicode_ci
                // -> utf8mb4). If the collation does not encode a charset (e.g.
                // 'binary') leave the charset unknown for now.
                if (strpos($tableCollation, '_') !== false) {
                    $tableCharset = explode('_', $tableCollation, 2)[0];
                }
            }
            if ($tableCharset && !$tableCollation) {
                // Descriptor only provided charset – lookup the default collation
                // MySQL would use for that charset.
                $tableCollation = self::defaultCollation($tableCharset);
            }
            if (!$tableCharset && !$tableCollation) {
                $tableCharset = 'utf8mb4';
                $tableCollation = 'utf8mb4_unicode_ci';
            }
            $collationEsc = Database::escape($tableCollation);
            if ($tableCharset) {
                $tableCharsetEsc = Database::escape($tableCharset);
                $result = Database::query(
                    "SHOW COLLATION WHERE Collation = '$collationEsc' AND Charset = '$tableCharsetEsc'"
                );
            } else {
                $result = Database::query("SHOW COLLATION WHERE Collation = '$collationEsc'");
            }
            $row = Database::fetchAssoc($result);
            if (!$row) {
                throw new \InvalidArgumentException("Collation '$tableCollation' does not exist.");
            }
            if (!$tableCharset) {
                $tableCharset = $row['Charset'];
                while ($check = Database::fetchAssoc($result)) {
                    if ($check['Charset'] !== $tableCharset) {
                        throw new \InvalidArgumentException(
                            "Collation '$tableCollation' maps to multiple charsets; specify charset explicitly."
                        );
                    }
                }
            } elseif ($row['Charset'] !== $tableCharset) {
                throw new \InvalidArgumentException(
                    "Collation '$tableCollation' does not match charset '$tableCharset'."
                );
            }
            $existingCollation = $existing['collation'] ?? null;
            unset($descriptor['charset'], $descriptor['collation']);
            unset($existing['charset'], $existing['collation']);
            reset($descriptor);
            $tablenameEsc = Database::escape($tablename);
            $status = Database::query("SHOW TABLE STATUS WHERE Name = '$tablenameEsc'");
            $statusRow = Database::fetchAssoc($status);
            $engine = strtoupper($statusRow['Engine'] ?? 'INNODB');
            $changes = array();
            $columnsNeedConversion = [];
            // Statements to reapply explicit column encodings after a table level
            // conversion. Filled only when columns request non-default charsets or
            // collations.
            $postConvert = [];
            $columnMap = [];
            foreach ($descriptor as $key => $val) {
                if ($key == "RequireMyISAM") {
                    continue;
                }
                $val['type'] = self::descriptorSanitizeType($val['type']);
                if (!isset($val['name'])) {
                    if (
                        ($val['type'] == "key" ||
                            $val['type'] == "unique key" ||
                            $val['type'] == "primary key")
                    ) {
                        if (substr($key, 0, 4) == "key-") {
                            $val['name'] = substr($key, 4);
                        } else {
                            $message = sprintf(
                                'Warning: the descriptor for %s includes a %s which is not named correctly. '
                                . 'It should be named key-%s. In your code, it should look something like this: '
                                . '"key-%s"=>array("type"=>"%s","columns"=>"%s"). '
                                . 'The consequence of this is that your keys will be destroyed and recreated each time the table is synchronized until this is addressed.',
                                $tablename,
                                $val['type'],
                                $key,
                                $key,
                                $val['type'],
                                $val['columns']
                            );
                            error_log($message);
                            $val['name'] = $key;
                        }
                    } else {
                        $val['name'] = $key;
                    }
                } else {
                    if (
                        $val['type'] == "key" ||
                        $val['type'] == "unique key" ||
                        $val['type'] == "primary key"
                    ) {
                        $key = "key-" . $val['name'];
                    } else {
                        $key = $val['name'];
                    }
                }
                if (isset($existing[$key])) {
                    $columnName = $existing[$key]['name'];
                    $needsConversion = false;
                    // When the descriptor omits an encoding, strip the existing
                    // charset/collation so comparisons use table defaults and mark
                    // that a conversion may be required.
                    if (!isset($val['collation'])) {
                        $safeTableCollation = $tableCollation ?? 'utf8mb4_unicode_ci';
                        if (isset($existing[$key]['collation']) && $existing[$key]['collation'] !== $safeTableCollation) {
                            $needsConversion = true;
                        }
                        unset($existing[$key]['collation']);
                    }
                    if (!isset($val['charset'])) {
                        if (isset($existing[$key]['charset']) && $existing[$key]['charset'] !== $tableCharset) {
                            $needsConversion = true;
                        }
                        unset($existing[$key]['charset']);
                    }
                    if ($needsConversion) {
                        $columnsNeedConversion[$columnName] = ['newsql' => null, 'changeIndex' => null];
                    }
                }

                $hasExplicitCharset = array_key_exists('charset', $val);
                $hasExplicitCollation = array_key_exists('collation', $val);
                $colCharset = $val['charset'] ?? null;
                $colCollation = $val['collation'] ?? null;
                if (
                    $colCharset
                    && $colCollation
                    && strpos($colCollation, $colCharset . '_') !== 0
                ) {
                    $name = $val['name'] ?? $key;
                    throw new \InvalidArgumentException(
                        "Column '$name' charset '$colCharset' and collation '$colCollation' are incompatible."
                    );
                }
                if (!$colCharset && $colCollation && strpos($colCollation, '_') !== false) {
                    $colCharset = explode('_', $colCollation, 2)[0];
                }
                if ($colCollation) {
                    $colCollationEsc = Database::escape($colCollation);
                    $result = Database::query(
                        "SHOW COLLATION WHERE Collation = '$colCollationEsc'"
                    );
                    $row = Database::fetchAssoc($result);
                    $name = $val['name'] ?? $key;
                    if (!$row) {
                        throw new \InvalidArgumentException(
                            "Column '$name' collation '$colCollation' does not exist."
                        );
                    }
                    if ($colCharset && $row['Charset'] !== $colCharset) {
                        throw new \InvalidArgumentException(
                            "Column '$name' collation '$colCollation' does not match charset '$colCharset'."
                        );
                    }
                    if (!$colCharset) {
                        $colCharset = $row['Charset'];
                    }
                }
                if ($colCharset && !$colCollation) {
                    $colCollation = self::defaultCollation($colCharset);
                }
                // Reduce column sizes for all index types, including primary keys,
                // so composite keys stay within storage engine limits.
                if (in_array($val['type'], ['key', 'unique key', 'primary key'], true)) {
                    [$val['columns']] = self::adjustIndexColumns(
                        $val['columns'],
                        $columnMap,
                        $tableCharset,
                        $engine
                    );
                }
                $newsql = self::descriptorCreateSql($val);
                if (
                    isset($existing[$key]) && isset($columnsNeedConversion[$existing[$key]['name']])
                    && $columnsNeedConversion[$existing[$key]['name']]['newsql'] === null
                ) {
                    $columnsNeedConversion[$existing[$key]['name']]['newsql'] = $newsql;
                }
                $needsPostConvert = ($hasExplicitCharset && $colCharset !== $tableCharset)
                    || ($hasExplicitCollation && $colCollation !== $tableCollation);

                if (!isset($existing[$key])) {
                    //this is a new column.
                    array_push($changes, "ADD $newsql");
                    if ($needsPostConvert) {
                        $postConvert[] = ['sql' => "CHANGE {$val['name']} $newsql", 'needsChange' => false];
                    }
                } else {
                    //this is an existing column, let's make sure the
                    //descriptors match.
                    $oldsql = self::descriptorCreateSql($existing[$key]);
                    if ($needsPostConvert) {
                        $postConvert[] = [
                            'sql' => "CHANGE {$existing[$key]['name']} $newsql",
                            'needsChange' => $oldsql != $newsql,
                        ];
                    } elseif ($oldsql != $newsql) {
                        //this descriptor line has changed.  Change the
                        //table to suit.
                        error_log("Old: $oldsql\nNew: $newsql");
                        if (
                            $existing[$key]['type'] == "key" ||
                            $existing[$key]['type'] == "unique key"
                        ) {
                            array_push(
                                $changes,
                                "DROP KEY {$existing[$key]['name']}"
                            );
                            array_push($changes, "ADD $newsql");
                        } elseif ($existing[$key]['type'] == "primary key") {
                            array_push($changes, "DROP PRIMARY KEY");
                            array_push($changes, "ADD $newsql");
                        } else {
                            $idx = array_push(
                                $changes,
                                "CHANGE {$existing[$key]['name']} $newsql"
                            ) - 1;
                            if (isset($columnsNeedConversion[$existing[$key]['name']])) {
                                $columnsNeedConversion[$existing[$key]['name']]['changeIndex'] = $idx;
                                $columnsNeedConversion[$existing[$key]['name']]['newsql'] = $newsql;
                            }
                        }
                    } else {
                        if (isset($columnsNeedConversion[$existing[$key]['name']]) && $columnsNeedConversion[$existing[$key]['name']]['newsql'] === null) {
                            $columnsNeedConversion[$existing[$key]['name']]['newsql'] = $newsql;
                        }
                    }
                }
                unset($existing[$key]);
                if (
                    $val['type'] !== 'key'
                    && $val['type'] !== 'unique key'
                    && $val['type'] !== 'primary key'
                ) {
                    $columnMap[$val['name']] = $val;
                }
            }//end foreach
            // If the table's collation differs from the desired one, run a
            // table-wide CONVERT and then reapply explicit column encodings.
            // Otherwise, individually alter any columns that require charset
            // conversion.
            if ($existingCollation !== null && $existingCollation !== $tableCollation) {
                $changes[] = "CONVERT TO CHARACTER SET $tableCharset COLLATE $tableCollation";
                foreach ($postConvert as $stmt) {
                    $changes[] = $stmt['sql'];
                }
            } else {
                foreach ($columnsNeedConversion as $col => $info) {
                    $sql = "CHANGE $col {$info['newsql']} CHARACTER SET $tableCharset COLLATE $tableCollation";
                    if ($info['changeIndex'] !== null) {
                        $changes[$info['changeIndex']] = $sql;
                    } else {
                        $changes[] = $sql;
                    }
                }
                foreach ($postConvert as $stmt) {
                    if ($stmt['needsChange']) {
                        $changes[] = $stmt['sql'];
                    }
                }
            }
            //drop no longer needed columns
            if (!$nodrop) {
                reset($existing);
                foreach ($existing as $val) {
                    //This column no longer exists.
                    if ($val['type'] == "key" || $val['type'] == "unique key") {
                        $sql = "DROP KEY {$val['name']}";
                    } elseif ($val['type'] == "primary key") {
                        $sql = "DROP PRIMARY KEY";
                    } else {
                        $sql = "DROP {$val['name']}";
                    }
                    array_push($changes, $sql);
                // end foreach
                }
            }
            if (count($changes) > 0) {
                // Before altering the table, normalise zero datetimes for columns
                // whose descriptor default is DATETIME_DATEMIN.
                foreach ($descriptor as $key => $col) {
                    if (!is_array($col) || !isset($col['type'])) {
                        continue;
                    }
                    $type = strtolower($col['type']);
                    if (
                        $type === 'key'
                        || $type === 'unique key'
                        || $type === 'primary key'
                    ) {
                        continue;
                    }
                    if (
                        (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp'))
                        && (($col['default'] ?? null) === DATETIME_DATEMIN)
                    ) {
                        $column = $col['name'] ?? $key;
                        if (!isset($existingColumns[$column])) {
                            continue;
                        }
                        $updateSql = "UPDATE $tablename SET $column = :DATETIME_DATEMIN"
                            . " WHERE $column < :DATETIME_DATEMIN OR $column = :zeroDate";
                        try {
                            Database::getDoctrineConnection()->executeStatement(
                                $updateSql,
                                [
                                    'DATETIME_DATEMIN' => DATETIME_DATEMIN,
                                    'zeroDate' => '0000-00-00 00:00:00',
                                ]
                            );
                        } catch (\Throwable $e) {
                            error_log($e->getMessage());
                        }
                    }
                }
                //we have changes to do!  Woohoo!
                $sql = "ALTER TABLE $tablename \n" . join(",\n", $changes);
                error_log($sql);
                $result = Database::query($sql);
                if ($result === false) {
                    throw new \RuntimeException(Database::error());
                }

                return count($changes);
            }
        // end if
        }
        return null; //no changes made
    // end function
    }

    /**
     * Generate SQL to create a table from the given descriptor.
     *
     * @param string $tablename  Name of the table to create.
     * @param array  $descriptor Column and option definitions.
     *
     * @return string Fully composed CREATE TABLE statement.
     */
    public static function tableCreateFromDescriptor(string $tablename, array $descriptor): string
    {
        $sql = "CREATE TABLE $tablename (\n";
        $type = "INNODB";
        $tableCharset = $descriptor['charset'] ?? null;
        $tableCollation = $descriptor['collation'] ?? null;
        // Step 1: validate and normalise table level charset/collation options.
        if (
            $tableCharset
            && $tableCollation
            && strpos($tableCollation, $tableCharset . '_') !== 0
        ) {
            throw new \InvalidArgumentException(
                "Table charset '$tableCharset' and collation '$tableCollation' are incompatible."
            );
        }
        if (!$tableCharset && $tableCollation) {
            if (strpos($tableCollation, '_') !== false) {
                $tableCharset = explode('_', $tableCollation, 2)[0];
            }
        }
        if ($tableCharset && !$tableCollation) {
            $tableCollation = self::defaultCollation($tableCharset);
        }
        if (!$tableCharset && !$tableCollation) {
            $tableCharset = 'utf8mb4';
            $tableCollation = 'utf8mb4_unicode_ci';
        }
        if ($tableCollation) {
            $collationEsc = Database::escape($tableCollation);
            if ($tableCharset) {
                $tableCharsetEsc = Database::escape($tableCharset);
                $result = Database::query(
                    "SHOW COLLATION WHERE Collation = '$collationEsc' AND Charset = '$tableCharsetEsc'"
                );
            } else {
                $result = Database::query("SHOW COLLATION WHERE Collation = '$collationEsc'");
            }
            $row = Database::fetchAssoc($result);
            if (!$row) {
                throw new \InvalidArgumentException("Collation '$tableCollation' does not exist.");
            }
            if (!$tableCharset) {
                $tableCharset = $row['Charset'];
                while ($check = Database::fetchAssoc($result)) {
                    if ($check['Charset'] !== $tableCharset) {
                        throw new \InvalidArgumentException(
                            "Collation '$tableCollation' maps to multiple charsets; specify charset explicitly."
                        );
                    }
                }
            } elseif ($row['Charset'] !== $tableCharset) {
                throw new \InvalidArgumentException(
                    "Collation '$tableCollation' does not match charset '$tableCharset'."
                );
            }
        }
        // Step 2: remove table level options; loop through descriptor items to
        //    build column/index SQL snippets.
        unset($descriptor['charset'], $descriptor['collation']);
        reset($descriptor);
        $columnMap = [];
        $i = 0;
        foreach ($descriptor as $key => $val) {
            if ($key === 'RequireMyISAM') {
                if ($val == 1 && Database::getServerVersion() < '4.0.14') {
                    $type = 'MyISAM';
                }
                continue;
            }
            $val['type'] = self::descriptorSanitizeType($val['type']);
            if (!isset($val['name'])) {
                if (
                    ($val['type'] == "key" ||
                        $val['type'] == "unique key" ||
                        $val['type'] == "primary key")
                ) {
                    if (substr($key, 0, 4) == "key-") {
                        $val['name'] = substr($key, 4);
                    } else {
                        $message = sprintf(
                            'Warning: the descriptor for %s includes a %s which is not named correctly. '
                            . 'It should be named key-%s. In your code, it should look something like this: '
                            . '"key-%s"=>array("type"=>"%s","columns"=>"%s"). '
                            . 'The consequence of this is that your keys will be destroyed and recreated each time the table is synchronized until this is addressed.',
                            $tablename,
                            $val['type'],
                            $key,
                            $key,
                            $val['type'],
                            $val['columns']
                        );
                        error_log($message);
                        $val['name'] = $key;
                    }
                } else {
                    $val['name'] = $key;
                }
            } else {
                if (
                    $val['type'] == "key" ||
                    $val['type'] == "unique key" ||
                    $val['type'] == "primary key"
                ) {
                    $key = "key-" . $val['name'];
                } else {
                    $key = $val['name'];
                }
            }
            $colCharset = $val['charset'] ?? null;
            $colCollation = $val['collation'] ?? null;
            if (
                $colCharset
                && $colCollation
                && strpos($colCollation, $colCharset . '_') !== 0
            ) {
                throw new \InvalidArgumentException(
                    "Column '{$val['name']}' charset '$colCharset' and collation '$colCollation' are incompatible."
                );
            }
            if (!$colCharset && $colCollation && strpos($colCollation, '_') !== false) {
                $colCharset = explode('_', $colCollation, 2)[0];
            }
            if ($colCollation) {
                $colCollationEsc = Database::escape($colCollation);
                $result = Database::query(
                    "SHOW COLLATION WHERE Collation = '$colCollationEsc'"
                );
                $row = Database::fetchAssoc($result);
                if (!$row) {
                    throw new \InvalidArgumentException(
                        "Column '{$val['name']}' collation '$colCollation' does not exist."
                    );
                }
                if ($colCharset && $row['Charset'] !== $colCharset) {
                    throw new \InvalidArgumentException(
                        "Column '{$val['name']}' collation '$colCollation' does not match charset '$colCharset'."
                    );
                }
                if (!$colCharset) {
                    $colCharset = $row['Charset'];
                }
            }
            if ($colCharset && !$colCollation) {
                $colCollation = self::defaultCollation($colCharset);
            }
            // Adjust index column lengths—including primary keys—to prevent
            // composite indexes from exceeding the engine's byte limits.
            if (in_array($val['type'], ['key', 'unique key', 'primary key'], true)) {
                [$val['columns']] = self::adjustIndexColumns(
                    $val['columns'],
                    $columnMap,
                    $tableCharset,
                    $type
                );
            } else {
                $columnMap[$val['name']] = $val;
            }
            if ($i > 0) {
                $sql .= ",\n";
            }
            $sql .= self::descriptorCreateSql($val);
            $i++;
        }
        // Step 3: append engine and default encoding settings to final CREATE
        //    TABLE statement.
        $sql .= ") Engine=$type";
        if ($tableCharset || $tableCollation) {
            $sql .= " DEFAULT CHARSET=" . ($tableCharset ?? 'utf8mb4');
            if ($tableCollation) {
                $sql .= " COLLATE=$tableCollation";
            }
        } else {
            $sql .= " DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        return $sql;
    }

    /**
     * Build a descriptor array from an existing table.
     *
     * @param string $tablename Table to introspect.
     *
     * @return array Descriptor mirroring the current table structure.
     */
    public static function tableCreateDescriptor(string $tablename): array
    {
    //this function assumes that $tablename is already passed
    //through Database::prefix.
        $descriptor = array();

    //reserved function words, expand if necessary, currently not a global setting
        $reserved_words = array('function', 'table','key');

    // 1. Fetch column definitions from the database and parse them into the
    //    descriptor array.
        $sql = "SHOW FULL COLUMNS FROM $tablename";
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $item = array();
            // check for reserved
            if (in_array($row['Field'], $reserved_words)) {
                $item['name'] = '`' . $row['Field'] . '`';
            } else {
                $item['name'] = $row['Field'];
            }
            $item['type'] = $row['Type'];
            if ($row['Null'] == "YES") {
                $item['null'] = true;
            }
            if (array_key_exists('Default', $row)) {
                if ($row['Default'] === null || $row['Default'] === 'NULL') {
                    $item['default'] = null;
                } else {
                    $item['default'] = $row['Default'];
                }
            }
            if (isset($row['Extra']) && !empty(trim($row['Extra']))) {
                $item['extra'] = $row['Extra'];
            }
            if (!empty($row['Collation'])) {
                $item['collation'] = $row['Collation'];
                if (strpos($row['Collation'], '_') !== false) {
                    $item['charset'] = explode('_', $row['Collation'], 2)[0];
                }
            }
            $descriptor[$item['name']] = $item;
        }
        // 2. Obtain table level charset/collation and then
        // 3. Generate key/index entries.
        $tablenameEsc = Database::escape($tablename);
        $status = Database::query("SHOW TABLE STATUS WHERE Name = '$tablenameEsc'");
        $row = Database::fetchAssoc($status);
        if ($row && !empty($row['Collation'])) {
            $descriptor['collation'] = $row['Collation'];
            if (strpos($row['Collation'], '_') !== false) {
                $descriptor['charset'] = explode('_', $row['Collation'], 2)[0];
            }
        }
        $sql = "SHOW KEYS FROM $tablename";
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            if ($row['Seq_in_index'] > 1) {
                //this is a secondary+ column on some previous key;
                //add this to that column's keys.
                $str = $row['Column_name'];
                if ($row['Sub_part']) {
                    $str .= "(" . $row['Sub_part'] . ")";
                }
                $descriptor['key-' . $row['Key_name']]['columns'] .=
                "," . $str;
            } else {
                $item = array();
                $item['name'] = $row['Key_name'];
                if ($row['Key_name'] == "PRIMARY") {
                    $item['type'] = "primary key";
                } else {
                    $item['type'] = "key";
                }
                if ($row['Non_unique'] == 0) {
                    $item['unique'] = true;
                }
                $str = $row['Column_name'];
                if ($row['Sub_part']) {
                    $str .= "(" . $row['Sub_part'] . ")";
                }
                $item['columns'] = $str;
                $descriptor['key-' . $item['name']] = $item;
            }//end if
        }//end while

        return $descriptor;
    }

    /**
     * Determine the default collation for a given charset.
     */
    private static function defaultCollation(string $charset): string
    {
        $candidate = $charset . '_unicode_ci';
        $candidateEsc = Database::escape($candidate);
        $charsetEsc = Database::escape($charset);
        $result = Database::query("SHOW COLLATION WHERE Collation = '$candidateEsc'");
        if (Database::numRows($result) > 0) {
            return $candidate;
        }
        $result = Database::query("SHOW COLLATION WHERE Charset = '$charsetEsc' AND `Default` = 'Yes'");
        $row = Database::fetchAssoc($result);
        if ($row && isset($row['Collation'])) {
            return $row['Collation'];
        }
        throw new \InvalidArgumentException("Charset '$charset' lacks a default collation.");
    }

    /**
     * Determine the bytes-per-character for a charset.
     */
    private static function charsetBytes(string $charset): int
    {
        static $cache = [];
        if (isset($cache[$charset])) {
            return $cache[$charset];
        }
        $charsetEsc = Database::escape($charset);
        $result = Database::query("SHOW CHARACTER SET WHERE Charset = '$charsetEsc'");
        $row = Database::fetchAssoc($result);
        $bytes = isset($row['Maxlen']) ? (int) $row['Maxlen'] : 1;
        $cache[$charset] = $bytes;
        return $bytes;
    }

    /**
     * Extract the character length from a column type.
     */
    private static function columnLength(string $type): ?int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/i', $type, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Approximate byte width for numeric columns.
     */
    private static function numericBytes(string $type): int
    {
        $type = strtolower($type);
        return match (true) {
            str_starts_with($type, 'tinyint')   => 1,
            str_starts_with($type, 'smallint')  => 2,
            str_starts_with($type, 'mediumint') => 3,
            str_starts_with($type, 'bigint')    => 8,
            str_starts_with($type, 'int')       => 4,
            str_starts_with($type, 'double')    => 8,
            str_starts_with($type, 'float')     => 4,
            default                             => 0,
        };
    }

    /**
     * Adjust index column prefixes to satisfy engine key length limits.
     *
     * @param string|array $columns    Column list from the descriptor.
     * @param array        $columnMap  Map of column definitions.
     * @param string|null  $tableCharset Default table charset.
     * @param string       $engine     Storage engine name.
     *
     * @return array{0: array, 1: bool} Adjusted column list and whether truncation occurred.
     */
    private static function adjustIndexColumns(string|array $columns, array $columnMap, ?string $tableCharset, string $engine): array
    {
        $indexLimit = strtoupper($engine) === 'MYISAM' ? 1000 : 767;
        $columns = is_array($columns) ? $columns : explode(',', $columns);
        $columnInfo = [];
        $totalBytes = 0;
        foreach ($columns as $col) {
            $col = trim($col);
            if ($col === '') {
                continue;
            }
            $quoted = $col !== '' && $col[0] === '`';
            if (preg_match('/^`?([\w]+)`?(?:\((\d+)\))?$/', $col, $m)) {
                $name = $m[1];
                $prefix = isset($m[2]) ? (int) $m[2] : null;
                $def = $columnMap[$name] ?? null;
                $charset = $def['charset'] ?? $tableCharset;
                $bytesPerChar = $charset ? self::charsetBytes($charset) : 1;
                $length = $prefix ?? self::columnLength($def['type'] ?? '');
                $isString = $length !== null;
                $bytes = $isString ? $length * $bytesPerChar : self::numericBytes($def['type'] ?? '');
                $needsQuote = $quoted || in_array(strtolower($name), self::RESERVED_WORDS, true);
                $columnInfo[] = [
                    'name'       => $name,
                    'explicit'   => $prefix !== null,
                    'length'     => $length,
                    'bytes'      => $bytes,
                    'bytesChar'  => $bytesPerChar,
                    'isString'   => $isString,
                    'needsQuote' => $needsQuote,
                ];
                $totalBytes += $bytes;
            }
        }
        $needsTruncate = $totalBytes > $indexLimit && $totalBytes > 0;
        if ($needsTruncate) {
            $ratio = $indexLimit / $totalBytes;
            foreach ($columnInfo as &$info) {
                if (!$info['isString']) {
                    continue;
                }
                $newLen = max(1, (int) floor($info['length'] * $ratio));
                $info['length'] = $newLen;
            }
            unset($info);
        }
        $adjusted = [];
        foreach ($columnInfo as $info) {
            $colStr = $info['needsQuote'] ? '`' . $info['name'] . '`' : $info['name'];
            if ($info['isString'] && ($needsTruncate || $info['explicit'])) {
                $colStr .= '(' . $info['length'] . ')';
            }
            $adjusted[] = $colStr;
        }
        return [$adjusted, $needsTruncate];
    }

    /**
     * Convert a descriptor element to a SQL fragment.
     *
     * @param array $input Descriptor for a column or index.
     *
     * @return string SQL definition suitable for inclusion in CREATE/ALTER statements.
     */
    public static function descriptorCreateSql(array $input): string
    {
        $input['type'] = self::descriptorSanitizeType($input['type']);
        if (isset($input['columns']) && is_array($input['columns'])) {
            if (!empty($input['columns']) && is_array(current($input['columns']))) {
                $cols = [];
                foreach ($input['columns'] as $col) {
                    $cols[] = $col['name'] . (isset($col['length']) ? '(' . $col['length'] . ')' : '');
                }
                $input['columns'] = implode(',', $cols);
            } else {
                $input['columns'] = implode(',', $input['columns']);
            }
        }
        if ($input['type'] == "key" || $input['type'] == 'unique key') {
            //this is a standard index
            if (!isset($input['name'])) {
                //if the user didn't define a name we should give it one
                if (strpos($input['columns'], ",") !== false) {
                    //if there are multiple columns, the name is just the
                    //first column
                    $input['name'] =
                    substr($input['columns'], strpos($input['columns'], ","));
                } else {
                    //if there is only one column, the key name is the same
                    //as the column name.
                    $input['name'] = $input['columns'];
                }
            }
            if (substr($input['type'], 0, 7) == "unique ") {
                $input['unique'] = true;
            }
            $return = (isset($input['unique']) && $input['unique'] ? "UNIQUE " : "")
            . "KEY {$input['name']} "
            . "({$input['columns']})";
        } elseif ($input['type'] == "primary key") {
            //this is a primary key
            $return = "PRIMARY KEY ({$input['columns']})";
        } else {
            //this is a standard column
            if (!array_key_exists('extra', $input)) {
                $input['extra'] = "";
            }
            $return = $input['name'] . " "
            . $input['type']
            . (isset($input['null']) && $input['null'] ? "" : " NOT NULL");

            if (array_key_exists('default', $input)) {
                if ($input['default'] === null) {
                    $return .= " DEFAULT NULL";
                } elseif (is_string($input['default'])) {
                    if (preg_match('/^[A-Z_]+(?:\([^)]*\))?$/', $input['default'])) {
                        $return .= " DEFAULT {$input['default']}";
                    } else {
                        $escapedDefault = Database::escape($input['default']);
                        $return .= " DEFAULT '{$escapedDefault}'";
                    }
                } else {
                    $return .= " DEFAULT {$input['default']}";
                }
            }

            $return .= (!empty($input['charset']) ? " CHARACTER SET {$input['charset']}" : "")
            . (isset($input['collation']) ? " COLLATE {$input['collation']}" : "")
            . " " . $input['extra'];
        }
        return $return;
    }

    /**
     * Normalise a descriptor type value.
     *
     * @param string $type Raw descriptor type.
     *
     * @return string Canonicalised type string.
     */
    public static function descriptorSanitizeType(string $type): string
    {
        $type = strtolower($type);
        $changes = array(
        "primary index" => "primary key",
        "primary" => "primary key",
        "index" => "key",
        "unique index" => "unique key",
        );
        if (isset($changes[$type])) {
            return $changes[$type];
        } else {
            return $type;
        }
    }
}
