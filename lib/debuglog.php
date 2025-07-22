<?php

use Lotgd\DebugLog;

function debuglog($message, $target = false, $user = false, $field = false, $value = false, $consolidate = true)
{
    DebugLog::add($message, $target, $user, $field, $value, $consolidate);
}
