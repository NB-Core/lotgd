<?php

use Lotgd\BellRand;

function bell_rand($min = null, $max = null)
{
    return BellRand::generate($min, $max);
}
