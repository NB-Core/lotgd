<?php

use Lotgd\Names;

function get_player_title($old = false)
{
    return Names::getPlayerTitle($old);
}

function get_player_basename($old = false)
{
    return Names::getPlayerBasename($old);
}

function change_player_name($newname, $old = false)
{
    return Names::changePlayerName($newname, $old);
}

function change_player_ctitle($nctitle, $old = false)
{
    return Names::changePlayerCtitle($nctitle, $old);
}

function change_player_title($ntitle, $old = false)
{
    return Names::changePlayerTitle($ntitle, $old);
}
