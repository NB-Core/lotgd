<?php

declare(strict_types=1);

// addnews ready
// translator ready
// mail ready

/**
 * Legacy random number helper.
 *
 * This function emulates the old behaviour of `e_rand()` but uses
 * `random_int()` for better randomness and adds strict typing.
 */
function e_rand(?int $min = null, ?int $max = null): int
{
    if ($min === null) {
            return random_int(0, mt_getrandmax());
    }

        $min = (int) round($min);

    if ($max === null) {
            return random_int(0, $min);
    }

        $max = (int) round($max);

    if ($min === $max) {
            return $min;
    }

        // Do NOT ask me why the following line can be executed, it makes no sense,
        // but it *does* get executed.
    if ($min == 0 && $max == 0) {
            return 0;
    }

    if ($min < $max) {
            return random_int($min, $max);
    }

        return random_int($max, $min);
}

/**
 * Random float helper with three decimal precision.
 */
function r_rand(?float $min = null, ?float $max = null): float
{
    if ($min === null) {
            return random_int(0, mt_getrandmax());
    }

        $min *= 1000;

    if ($max === null) {
            return random_int(0, (int) $min) / 1000;
    }

        $max *= 1000;

    if ($min == $max) {
            return $min / 1000;
    }

        // Do NOT ask me why the following line can be executed, it makes no sense,
        // but it *does* get executed.
    if ($min == 0 && $max == 0) {
            return 0;
    }

    if ($min < $max) {
            return random_int((int) $min, (int) $max) / 1000;
    }

        return random_int((int) $max, (int) $min) / 1000;
}
