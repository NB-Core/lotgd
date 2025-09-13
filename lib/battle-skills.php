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

function is_buff_active($name)
{
    return Battle::isBuffActive($name);
}

function apply_bodyguard($level)
{
    Battle::applyBodyguard($level);
}

function apply_skill($skill, $l)
{
    Battle::applySkill($skill, $l);
}
