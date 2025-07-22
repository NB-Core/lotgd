<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Utility class to generate bell curve random numbers.
 */
class BellRand
{
    /**
     * Return a random number distributed around the center of the range.
     *
     * @param float|int|null $min Minimum value or null for default 0
     * @param float|int|null $max Maximum value or null for default 1
     *
     * @return float|int Random value
     */
    public static function generate(int|float|null $min = null, int|float|null $max = null): int|float
    {
        if ($min === null && $max === null) {
            $min = 0;
            $max = 1;
        }

        if ($max === null) {
            $max = $min;
            $min = 0;
        }

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        if ($min == $max) {
            return $min;
        }

        // Box-Muller transform for normally distributed value
        $u = mt_rand() / mt_getrandmax();
        $v = mt_rand() / mt_getrandmax();
        $n = sqrt(-2 * log(max($u, 1e-10))) * cos(2 * M_PI * $v);
        // scale result roughly to 0..1
        $n = max(min(($n / 6) + 0.5, 1), 0);

        return $min + $n * ($max - $min);
    }
}
