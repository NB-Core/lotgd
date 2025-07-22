<?php

use Lotgd\Battle;

function rolldamage(&$badguy)
{
    return Battle::rollDamage($badguy);
}

function report_power_move($crit, $dmg)
{
    return Battle::reportPowerMove($crit, $dmg);
}

function suspend_buffs($susp = false, $msg = false)
{
    Battle::suspendBuffs($susp, $msg);
}

function suspend_buff_by_name($name, $msg = false)
{
    Battle::suspendBuffByName($name, $msg);
}

function unsuspend_buff_by_name($name, $msg = false)
{
    Battle::unsuspendBuffByName($name, $msg);
}

function is_buff_active($name)
{
    return Battle::isBuffActive($name);
}

function unsuspend_buffs($susp = false, $msg = false)
{
    Battle::unsuspendBuffs($susp, $msg);
}

function apply_bodyguard($level)
{
    Battle::applyBodyguard($level);
}

function apply_skill($skill, $l)
{
    Battle::applySkill($skill, $l);
}
