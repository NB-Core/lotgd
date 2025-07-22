<?php

// Legacy wrapper for Stripslashes class
use Lotgd\Stripslashes;

function stripslashes_deep($input)
{
    return Stripslashes::deep($input);
}
