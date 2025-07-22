<?php

// Legacy wrapper for Battle::fightnav
use Lotgd\Battle;

function fightnav($allowspecial = true, $allowflee = true, $script = false)
{
    Battle::fightnav($allowspecial, $allowflee, $script);
}
