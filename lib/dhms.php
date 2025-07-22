<?php

use Lotgd\Dhms;

function dhms($secs, $dec = false)
{
    return Dhms::format($secs, $dec);
}
