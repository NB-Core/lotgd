<?php

// Legacy wrapper for RegisterGlobal class
// addnews ready
// translator ready
// mail ready

use Lotgd\RegisterGlobal;

function register_global(&$var)
{
    RegisterGlobal::register($var);
}
