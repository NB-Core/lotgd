<?php

use Lotgd\GameLog;

function gamelog($message, $category = "general", $filed = false, $acctId = null, $severity = 'info')
{
    GameLog::log($message, $category, $filed, $acctId, $severity);
}
