<?php

// Legacy wrapper for PullUrl class
// addnews ready
// translator ready
// mail ready

use Lotgd\PullUrl;

function pullurl($url)
{
    return PullUrl::pull($url);
}
