<?php
namespace Lotgd;

class UserLookup
{
    public static function lookup($query=false, $order=false, $fields=false, $where=false)
    {
        $err = '';
        $searchresult = false;
        if ($order !== false) $order = "ORDER BY $order";
        if ($fields === false) $fields = 'acctid,login,name,level,laston,loggedin,gentimecount,gentime,lastip,uniqueid,emailaddress';
        $sql = 'SELECT ' . $fields . ' FROM ' . db_prefix('accounts');
        if ($query != '') {
            if ($where === false) {
                $query = db_real_escape_string($query);
                $sql_where = "WHERE login LIKE '$query' OR name LIKE '$query' OR acctid = '$query' OR emailaddress LIKE '$query' OR lastip LIKE '$query' OR uniqueid LIKE '$query'";
            } else
                $sql_where = "WHERE $where";
            $searchresult = db_query($sql . " $sql_where $order LIMIT 2");
        }
        if ($query !== false || $searchresult) {
            if (db_num_rows($searchresult) != 1) {
                $name_query = '%';
                for ($x=0;$x<strlen($query);$x++){
                    $char = substr($query,$x,1);
                    if ($char != '\\')
                        $name_query .= $char.'%';
                    else $name_query .= $char;
                }
                if ($where === false)
                    $sql_where="WHERE login LIKE '%$query%' OR acctid LIKE '%$query%' OR name LIKE '%$name_query%' OR emailaddress LIKE '%$query%' OR lastip LIKE '%$query%' OR uniqueid LIKE '%$query%' OR gentimecount LIKE '%$query%' OR level LIKE '%$query%'";
                else
                    $sql_where = "WHERE $where";
                $searchresult = db_query($sql . " $sql_where $order LIMIT 301");
            }
            if (db_num_rows($searchresult)<=0){
                $err = "`\$No results found`0";
            }elseif (db_num_rows($searchresult)>300){
                $err = "`\$Too many results found, narrow your search please.`0";
            }
        }
        return [$searchresult, $err];
    }
}
