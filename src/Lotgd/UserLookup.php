<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;

class UserLookup
{
    /**
     * Search for users matching the given criteria.
     *
     * @param string|false $query  Search string
     * @param string|false $order  Order clause
     * @param string|false $fields Fields to retrieve
     * @param string|false $where  Optional where clause
     *
     * @return array{0:mixed,1:string} Database result resource and error message
     */
    public static function lookup(string|false $query = false, string|false $order = false, string|false $fields = false, string|false $where = false): array
    {
        $err = '';
        $searchresult = false;
        if ($order !== false) {
            $order = "ORDER BY $order";
        }
        if ($fields === false) {
            $fields = 'acctid,login,name,level,laston,loggedin,gentimecount,gentime,lastip,uniqueid,emailaddress';
        }
        $sql = 'SELECT ' . $fields . ' FROM ' . Database::prefix('accounts');
        if ($query != '') {
            if ($where === false) {
                $query = Database::escape($query);
                $sql_where = "WHERE login LIKE '$query' OR name LIKE '$query' OR acctid = '$query' OR emailaddress LIKE '$query' OR lastip LIKE '$query' OR uniqueid LIKE '$query'";
            } else {
                $sql_where = "WHERE $where";
            }
            $searchresult = Database::query($sql . " $sql_where $order LIMIT 2");
        }
        if ($query !== false || $searchresult) {
            if (Database::numRows($searchresult) != 1) {
                $name_query = '%';
                for ($x = 0; $x < strlen($query); $x++) {
                    $char = substr($query, $x, 1);
                    if ($char != '\\') {
                        $name_query .= $char . '%';
                    } else {
                        $name_query .= $char;
                    }
                }
                if ($where === false) {
                    $sql_where = "WHERE login LIKE '%$query%' OR acctid LIKE '%$query%' OR name LIKE '%$name_query%' OR emailaddress LIKE '%$query%' OR lastip LIKE '%$query%' OR uniqueid LIKE '%$query%' OR gentimecount LIKE '%$query%' OR level LIKE '%$query%'";
                } else {
                    $sql_where = "WHERE $where";
                }
                $searchresult = Database::query($sql . " $sql_where $order LIMIT 301");
            }
            if (Database::numRows($searchresult) <= 0) {
                $err = "`\$No results found`0";
            } elseif (Database::numRows($searchresult) > 300) {
                $err = "`\$Too many results found, narrow your search please.`0";
            }
        }
        return [$searchresult, $err];
    }
}
