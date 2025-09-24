<?php

declare(strict_types=1);

use Lotgd\Random;

// addnews ready
// translator ready
// mail ready

/**
 * Legacy random number helper.
 *
 * Wrapper for {@see Random::eRand()} that preserves historical behaviour.
 */
function e_rand($min = null, $max = null): int
{
    $min = ($min === null) ? null : (int) round((float) $min);
    $max = ($max === null) ? null : (int) round((float) $max);

    return Random::eRand($min, $max);
}

/**
 * Random float helper with three decimal precision.
 *
 * Wrapper for {@see Random::rRand()} that casts arguments appropriately.
 */
function r_rand($min = null, $max = null): float
{
    $min = ($min === null) ? null : (float) $min;
    $max = ($max === null) ? null : (float) $max;

    return Random::rRand($min, $max);
}
