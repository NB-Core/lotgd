<?php

// Legacy wrapper for PlayerFunctions::expForNextLevel

use Lotgd\PlayerFunctions;

function exp_for_next_level($curlevel, $curdk)
{
    return PlayerFunctions::expForNextLevel($curlevel, $curdk);
}
