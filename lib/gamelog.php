<?php

use Lotgd\GameLog;

function gamelog($message, $category = "general", $filed = false, $acctId = null)
{
    GameLog::log($message, $category, $filed, $acctId);
}
