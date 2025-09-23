<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Random number utilities.
 *
 * Provides static methods corresponding to legacy random helper
 * functions e_rand() and r_rand() for integer and float values.
 */
class Random
{
    /**
     * Random integer helper.
     *
     * Mirrors the behaviour of legacy e_rand().
     */
    public static function e_rand(?int $min = null, ?int $max = null): int
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

        // Legacy quirk kept for compatibility
        if ($min == 0 && $max == 0) {
            return 0;
        }

        return ($min < $max)
            ? random_int($min, $max)
            : random_int($max, $min);
    }

    public static function eRand($min = null, $max = null)
    {
        return self::e_rand($min, $max);
    }

    /**
     * Random float helper with three decimal precision.
     *
     * Mirrors the behaviour of legacy r_rand().
     */
    public static function r_rand(?float $min = null, ?float $max = null): float
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

        // Legacy quirk kept for compatibility
        if ($min == 0 && $max == 0) {
            return 0;
        }

        return ($min < $max)
            ? random_int((int) $min, (int) $max) / 1000
            : random_int((int) $max, (int) $min) / 1000;
    }

    public static function rRand($min = null, $max = null)
    {
        return self::r_rand($min, $max);
    }
}
