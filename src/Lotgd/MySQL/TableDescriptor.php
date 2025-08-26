<?php

declare(strict_types=1);

namespace Lotgd\MySQL;

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
     * Synchronise a table with the provided descriptor.
     */
    public static function synctable(string $tablename, array $descriptor, bool $nodrop = false): ?int
    {
    //table names should be db_prefix'd before they get in to
    //this function.
        if (!Database::tableExists($tablename)) {
            //the table doesn't exist, so we create it and are done.
            reset($descriptor);
            $sql = self::tableCreateFromDescriptor($tablename, $descriptor);
            debug($sql);
            if (!Database::query($sql)) {
                output("`\$Error:`^ %s`n", Database::error());
                rawoutput("<pre>" . htmlentities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</pre>");
            } else {
                output("`^Table `#%s`^ created.`n", $tablename);
            }
        } else {
            //the table exists, so we need to compare it against the descriptor.
            $existing = self::tableCreateDescriptor($tablename);
            $tableCharset = $descriptor['charset'] ?? null;
            $tableCollation = $descriptor['collation'] ?? null;
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
                // Extract charset from collation only if it contains an underscore
                if (strpos($tableCollation, '_') !== false) {
                    $tableCharset = explode('_', $tableCollation, 2)[0];
                } else {
                    // Collation format is unexpected; fallback to default charset
                    $tableCharset = 'utf8mb4';
                }
            }
            if ($tableCharset && !$tableCollation) {
                $tableCollation = self::defaultCollation($tableCharset);
            }
            $tableCharset = $tableCharset ?? 'utf8mb4';
            $tableCollation = $tableCollation ?? 'utf8mb4_unicode_ci';
            $existingCollation = $existing['collation'] ?? null;
            unset($descriptor['charset'], $descriptor['collation']);
            unset($existing['charset'], $existing['collation']);
            reset($descriptor);
            $changes = array();
            $columnsNeedConversion = false;
            $postConvert = [];
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
                            debug("<b>Warning</b>: the descriptor for <b>$tablename</b> includes a {$val['type']} which isn't named correctly.  It should be named key-$key. In your code, it should look something like this (the important change is bolded):<br> \"<b>key-$key</b>\"=>array(\"type\"=>\"{$val['type']}\",\"columns\"=>\"{$val['columns']}\")<br> The consequence of this is that your keys will be destroyed and recreated each time the table is synchronized until this is addressed.");
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
                    if (!isset($val['collation'])) {
                        $safeTableCollation = $tableCollation ?? 'utf8mb4_unicode_ci';
                        if (isset($existing[$key]['collation']) && $existing[$key]['collation'] !== $safeTableCollation) {
                            $columnsNeedConversion = true;
                        }
                        unset($existing[$key]['collation']);
                    }
                    if (!isset($val['charset'])) {
                        if (isset($existing[$key]['charset']) && $existing[$key]['charset'] !== $tableCharset) {
                            $columnsNeedConversion = true;
                        }
                        unset($existing[$key]['charset']);
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
                if ($colCharset && !$colCollation) {
                    $colCollation = self::defaultCollation($colCharset);
                }
                $newsql = self::descriptorCreateSql($val);
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
                        debug("Old: $oldsql<br>New:$newsql");
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
                            array_push(
                                $changes,
                                "CHANGE {$existing[$key]['name']} $newsql"
                            );
                        }
                    }
                }
                unset($existing[$key]);
            }//end foreach
            if (($existingCollation !== null && $existingCollation !== $tableCollation) || $columnsNeedConversion) {
                $changes[] = "CONVERT TO CHARACTER SET $tableCharset COLLATE $tableCollation";
                foreach ($postConvert as $stmt) {
                    $changes[] = $stmt['sql'];
                }
            } else {
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
                //we have changes to do!  Woohoo!
                $sql = "ALTER TABLE $tablename \n" . join(",\n", $changes);
                debug(nl2br($sql));
                Database::query($sql);
                return count($changes);
            }
        // end if
        }
        return null; //no changes made
    // end function
    }

    /**
     * Generate SQL to create a table from the given descriptor.
     */
    public static function tableCreateFromDescriptor(string $tablename, array $descriptor): string
    {
        $sql = "CREATE TABLE $tablename (\n";
        $type = "INNODB";
        $tableCharset = $descriptor['charset'] ?? null;
        $tableCollation = $descriptor['collation'] ?? null;
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
            } else {
                $tableCharset = 'utf8mb4';
            }
        }
        if ($tableCharset && !$tableCollation) {
            $tableCollation = self::defaultCollation($tableCharset);
        }
        unset($descriptor['charset'], $descriptor['collation']);
        reset($descriptor);
        $i = 0;
        foreach ($descriptor as $key => $val) {
            if ($key === "RequireMyISAM" && $val == 1) {
                // Let's hope that we don't run into badly formatted strings
                // but you know what, if we do, tough
                if (Database::getServerVersion() < "4.0.14") {
                    $type = "MyISAM";
                }
                continue;
            } elseif ($key === "RequireMyISAM") {
                continue;
            }
            if (!isset($val['name'])) {
                if (
                    ($val['type'] == "key" ||
                        $val['type'] == "unique key" ||
                        $val['type'] == "primary key")
                ) {
                    if (substr($key, 0, 4) == "key-") {
                        $val['name'] = substr($key, 4);
                    } else {
                        debug("<b>Warning</b>: the descriptor for <b>$tablename</b> includes a {$val['type']} which isn't named correctly.  It should be named key-$key.  In your code, it should look something like this (the important change is bolded):<br> \"<b>key-$key</b>\"=>array(\"type\"=>\"{$val['type']}\",\"columns\"=>\"{$val['columns']}\")<br> The consequence of this is that your keys will be destroyed and recreated each time the table is synchronized until this is addressed.");
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
            if ($i > 0) {
                $sql .= ",\n";
            }
            $sql .= self::descriptorCreateSql($val);
            $i++;
        }
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
     */
    public static function tableCreateDescriptor(string $tablename): array
    {
    //this function assumes that $tablename is already passed
    //through db_prefix.
        $descriptor = array();

    //reserved function words, expand if necessary, currently not a global setting
        $reserved_words = array('function', 'table','key');

    //fetch column desc's
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
        $tablename_escaped = addslashes($tablename);
        $status = Database::query("SHOW TABLE STATUS LIKE '$tablename_escaped'");
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
        return $candidate;
    }

    /**
     * Convert a descriptor element to SQL.
     */
    public static function descriptorCreateSql(array $input): string
    {
        $input['type'] = self::descriptorSanitizeType($input['type']);
        if ($input['type'] == "key" || $input['type'] == 'unique key') {
            //this is a standard index
            if (is_array($input['columns'])) {
                $input['columns'] = join(",", $input['columns']);
            }
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
            if (is_array($input['columns'])) {
                $input['columns'] = join(",", $input['columns']);
            }
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
                    $escapedDefault = Database::escape($input['default']);
                    $return .= " DEFAULT '{$escapedDefault}'";
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
