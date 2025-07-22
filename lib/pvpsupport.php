<?php

use Lotgd\Pvp;

function setup_target($name)
{
    return Pvp::setupTarget($name);
}

function pvpvictory($badguy, $killedloc, $options = false)
{
    return Pvp::victory($badguy, $killedloc, $options);
}

function pvpdefeat($badguy, $killedloc, $taunt, $options = false)
{
    return Pvp::defeat($badguy, $killedloc, $taunt, $options);
}
