<?php

// Legacy wrapper for \Lotgd\Forest\Outcomes class

use Lotgd\Forest\Outcomes;

require_once("lib/output.php");
require_once("lib/nav.php");
require_once("lib/playerfunctions.php");

function forestvictory($enemies, $denyflawless = false): void
{
    Outcomes::victory($enemies, $denyflawless);
}

function forestdefeat($enemies, $where = 'in the forest'): void
{
    // Normalize $where to ensure it is always a string
    if (is_array($where)) {
        $where = implode(', ', $where); // Convert array to string
    } elseif (!is_string($where)) {
        $where = 'in the forest'; // Fallback to default value
    }
    Outcomes::defeat($enemies, $where);
}

function buffbadguy($badguy)
{
    return Outcomes::buffBadguy($badguy);
}
