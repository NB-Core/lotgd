<?php

use Lotgd\Pvp;

function pvplist($location = false, $link = false, $extra = false, $sql = false)
{
    Pvp::listTargets($location, $link, $extra, $sql);
}
