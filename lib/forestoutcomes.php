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
    Outcomes::defeat($enemies, $where);
}

function buffbadguy($badguy)
{
    return Outcomes::buffBadguy($badguy);
}
?>
