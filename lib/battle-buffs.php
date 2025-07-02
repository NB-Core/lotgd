<?php
use Lotgd\Buffs;
require_once("lib/substitute.php");

function activate_buffs($tag)
{
    return Buffs::activateBuffs($tag);
}

function process_lifetaps($ltaps, $damage)
{
    Buffs::processLifetaps($ltaps, $damage);
}

function process_dmgshield($dshield, $damage)
{
    Buffs::processDmgshield($dshield, $damage);
}

function expire_buffs()
{
    Buffs::expireBuffs();
}

function expire_buffs_afterbattle()
{
    Buffs::expireBuffsAfterbattle();
}
