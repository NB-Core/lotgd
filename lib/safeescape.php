<?php

// Legacy wrapper for SafeEscape class
// addnews ready
// translator ready
// mail ready

use Lotgd\SafeEscape;

function safeescape($input)
{
    return SafeEscape::escape($input);
}
