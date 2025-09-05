<?php

// Legacy wrapper for Lotgd\MySQL classes

use Lotgd\MySQL\DbMysqli;
use Lotgd\MySQL\Database;

function db_set_charset($charset)
{
    return Database::setCharset($charset);
}
function db_query($sql, $die = true)
{
    return Database::query($sql, $die);
}
function db_query_cached($sql, $name, $duration = 900)
{
    return Database::queryCached($sql, $name, $duration);
}
function db_error()
{
    return Database::error();
}
function db_fetch_assoc(&$result)
{
    return Database::fetchAssoc($result);
}
function db_insert_id()
{
    return Database::insertId();
}
function db_num_rows($result)
{
    return Database::numRows($result);
}
function db_affected_rows($link = false)
{
    return Database::affectedRows();
}
function db_pconnect($host, $user, $pass)
{
    return Database::pconnect($host, $user, $pass);
}
function db_connect($host, $user, $pass)
{
    return Database::connect($host, $user, $pass);
}
function db_get_server_version()
{
    return Database::getServerVersion();
}
function db_select_db($dbname)
{
    return Database::selectDb($dbname);
}
function db_real_escape_string($string)
{
    return Database::escape($string);
}
function db_free_result($result)
{
    return Database::freeResult($result);
}
function db_table_exists($tablename)
{
    return Database::tableExists($tablename);
}
function db_prefix($tablename, $force = null)
{
    return Database::prefix($tablename, $force);
}
