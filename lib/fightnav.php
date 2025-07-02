<?php
// Legacy wrapper for FightNav class
use Lotgd\FightNav;

function fightnav($allowspecial = true, $allowflee = true, $script = false)
{
    FightNav::fightnav($allowspecial, $allowflee, $script);
}
