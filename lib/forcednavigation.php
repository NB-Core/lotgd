<?php

// translator ready
// addnews ready
// mail ready

use Lotgd\ForcedNavigation;

$baseaccount = array();
function do_forced_nav($anonymous, $overrideforced)
{
    ForcedNavigation::doForcedNav((bool)$anonymous, (bool)$overrideforced);
}
