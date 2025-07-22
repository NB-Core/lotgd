<?php

use Lotgd\PlayerFunctions;

$temp_user_stats = ['is_suspended' => false];

function apply_temp_stat($name, $value, $type = "add")
{
    return PlayerFunctions::applyTempStat($name, $value, $type);
}
function check_temp_stat($name, $color = false)
{
    return PlayerFunctions::checkTempStat($name, $color);
}
function suspend_temp_stats()
{
    return PlayerFunctions::suspendTempStats();
}
function restore_temp_stats()
{
    return PlayerFunctions::restoreTempStats();
}
