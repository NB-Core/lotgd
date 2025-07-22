<?php

// Legacy wrapper for Nltoappon class
// translator ready
// addnews ready
// mail ready

use Lotgd\Nltoappon;

function nltoappon($in)
{
    return Nltoappon::convert($in);
}
