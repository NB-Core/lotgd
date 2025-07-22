<?php

// Legacy wrapper for Spell class
use Lotgd\Spell;

function spell($input, $words = false, $prefix = "<span style='border: 1px dotted #FF0000;'>", $postfix = "</span>")
{
    return Spell::check($input, $words, $prefix, $postfix);
}
