<?php

// Legacy wrapper for PlayerFunctions class

use Lotgd\PlayerFunctions;

function get_player_attack($player = false)
{
    return PlayerFunctions::getPlayerAttack($player);
}

function explained_get_player_attack($player = false)
{
    return PlayerFunctions::explainedGetPlayerAttack($player);
}

function get_player_defense($player = false)
{
    return PlayerFunctions::getPlayerDefense($player);
}

function explained_get_player_defense($player = false)
{
    return PlayerFunctions::explainedGetPlayerDefense($player);
}

function get_player_speed($player = false)
{
    return PlayerFunctions::getPlayerSpeed($player);
}

function get_player_physical_resistance($player = false)
{
    return PlayerFunctions::getPlayerPhysicalResistance($player);
}

function is_player_online($player = false)
{
    return PlayerFunctions::isPlayerOnline($player);
}

function mass_is_player_online($players = false)
{
    return PlayerFunctions::massIsPlayerOnline($players);
}

function get_player_dragonkillmod($withhitpoints = false)
{
    return PlayerFunctions::getPlayerDragonkillmod($withhitpoints);
}
