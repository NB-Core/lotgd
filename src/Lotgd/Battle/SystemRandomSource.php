<?php

declare(strict_types=1);

namespace Lotgd\Battle;

use Lotgd\BellRand;

/**
 * The randomness the game actually fights with.
 *
 * Both methods forward unchanged to what the combat code called directly
 * before, so installing this source is not a behaviour change.
 */
final class SystemRandomSource implements RandomSource
{
    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function bell(int|float $min, int|float $max): int|float
    {
        return BellRand::generate($min, $max);
    }
}
