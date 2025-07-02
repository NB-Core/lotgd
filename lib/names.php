<?php
use Lotgd\Names;
require_once 'lib/dbwrapper.php';

function get_player_title($old = false)
{
    return Names::get_player_title($old);
}

function get_player_basename($old = false)
{
    return Names::get_player_basename($old);
}

function change_player_name($newname, $old = false)
{
    return Names::change_player_name($newname, $old);
}

function change_player_ctitle($nctitle, $old = false)
{
    return Names::change_player_ctitle($nctitle, $old);
}

function change_player_title($ntitle, $old = false)
{
    return Names::change_player_title($ntitle, $old);
}
