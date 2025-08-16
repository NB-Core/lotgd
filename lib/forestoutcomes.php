<?php

// Legacy wrapper for \Lotgd\Forest\Outcomes class

use Lotgd\Forest\Outcomes;
use Lotgd\Translator;

if (!function_exists('output')) {
    require_once 'lib/output.php';
}
if (!function_exists('addnav')) {
    require_once 'lib/nav.php';
}
if (!function_exists('restore_buff_fields')) {
    require_once 'lib/playerfunctions.php';
}

function forestvictory($enemies, $denyflawless = false): void
{
    Outcomes::victory($enemies, $denyflawless);
}

function forestdefeat($enemies, $where = 'in the forest'): void
{
    if (is_array($where)) {
        $where = Translator::sprintfTranslate(...$where);
    } elseif (!is_string($where)) {
        $where = 'in the forest';
    }
    Outcomes::defeat($enemies, $where);
}

function buffbadguy($badguy)
{
    return Outcomes::buffBadguy($badguy);
}
