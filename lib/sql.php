<?php

// Legacy wrapper for Sql class
use Lotgd\Sql;

function sql_error($sql)
{
    return Sql::error($sql);
}
