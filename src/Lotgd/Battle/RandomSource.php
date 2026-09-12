<?php

declare(strict_types=1);

namespace Lotgd\Battle;

/**
 * The randomness a damage roll consumes.
 *
 * Combat draws from two different generators: random_int() for the discrete
 * chances (the critical hit, the power attack) and BellRand::generate() for the
 * attack and defence rolls themselves. Both sit behind this interface so a test
 * can script a round instead of fighting it.
 *
 * The seam deliberately sits on bell() rather than on the mt_rand() calls
 * inside BellRand: that function consumes two random numbers for a normal range
 * but none at all when $min equals $max, and a test should not have to know
 * which case it is in.
 */
interface RandomSource
{
    /**
     * A uniform integer in [$min, $max], both inclusive.
     */
    public function int(int $min, int $max): int;

    /**
     * A value drawn from the bell curve between $min and $max.
     */
    public function bell(int|float $min, int|float $max): int|float;
}
