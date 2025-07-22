<?php

use Lotgd\UserLookup;

function lookup_user($query = false, $order = false, $fields = false, $where = false)
{
    return UserLookup::lookup($query, $order, $fields, $where);
}
