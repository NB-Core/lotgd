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
            if (!$tableCharset && $tableCollation) {
                $tableCharset = explode('_', $tableCollation, 2)[0];
            }
            $tableCharset = $tableCharset ?? 'utf8mb4';
            $tableCollation = $tableCollation ?? 'utf8mb4_unicode_ci';
            $existingCollation = $existing['collation'] ?? null;
            unset($descriptor['charset'], $descriptor['collation']);
            unset($existing['charset'], $existing['collation']);
            reset($descriptor);
            $changes = array();
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
                        unset($existing[$key]['collation']);
                    }
                    if (!isset($val['charset'])) {
                        unset($existing[$key]['charset']);
                    }
                }
                $newsql = self::descriptorCreateSql($val);
                if (!isset($existing[$key])) {
                    //this is a new column.
                    array_push($changes, "ADD $newsql");
                } else {
                    //this is an existing column, let's make sure the
                    //descriptors match.
                    $oldsql = self::descriptorCreateSql($existing[$key]);
                    if ($oldsql != $newsql) {
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
                    }//end if
                }//end if
                unset($existing[$key]);
            }//end foreach
            if ($existingCollation !== null && $existingCollation !== $tableCollation) {
                $changes[] = "CONVERT TO CHARACTER SET $tableCharset COLLATE $tableCollation";
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
        if (!$tableCharset && $tableCollation) {
            $tableCharset = explode('_', $tableCollation, 2)[0];
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
                } else {
                    // Collation name does not follow expected pattern; do not set charset
                    $item['charset'] = null;
                }
            }
            $descriptor[$item['name']] = $item;
        }
        $tablename_escaped = addslashes($tablename);
        $status = Database::query("SHOW TABLE STATUS LIKE '$tablename_escaped'");
        $row = Database::fetchAssoc($status);
        if ($row && !empty($row['Collation'])) {
            $descriptor['collation'] = $row['Collation'];
            $descriptor['charset'] = explode('_', $row['Collation'], 2)[0];
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
            . (isset($input['null']) && $input['null'] ? "" : " NOT NULL")
            . (isset($input['default']) ? " default '{$input['default']}'" : "")
            . (isset($input['charset']) ? " CHARACTER SET {$input['charset']}" : "")
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
