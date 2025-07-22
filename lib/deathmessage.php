<?php

use Lotgd\DeathMessage;

function select_deathmessage($forest = true, $extra = [], $extrarep = [])
{
    return DeathMessage::select($forest, $extra, $extrarep);
}

function select_deathmessage_array($forest = true, $extra = [], $extrarep = [])
{
    return DeathMessage::selectArray($forest, $extra, $extrarep);
}
