<?php

// Legacy wrapper for Redirect class
// translator ready
// addnews ready
// mail ready

use Lotgd\Redirect;

function redirect($location, $reason = false)
{
    Redirect::redirect($location, $reason);
}
