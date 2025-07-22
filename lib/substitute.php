<?php

// Legacy wrapper for Substitute class
use Lotgd\Substitute;

function substitute($string, $extra = false, $extrarep = false)
{
    return Substitute::apply($string, $extra, $extrarep);
}

function substitute_array($string, $extra = false, $extrarep = false)
{
    return Substitute::applyArray($string, $extra, $extrarep);
}
