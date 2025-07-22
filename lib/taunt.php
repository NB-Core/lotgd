<?php

// Legacy wrapper for Battle class taunt helpers
use Lotgd\Battle;

function select_taunt()
{
    return Battle::selectTaunt();
}

function select_taunt_array()
{
    return Battle::selectTauntArray();
}
