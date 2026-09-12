<?php

declare(strict_types=1);

namespace Lotgd\Battle;

/**
 * The side that is swinging: the player, or one of their companions.
 *
 * The two are described by the same four numbers, which is why one type serves
 * both. Companions have no physical resistance, so it defaults to none rather
 * than being nullable.
 */
final readonly class Combatant
{
    public function __construct(
        public float $attack,
        public float $defense,
        public float $hitpoints,
        public float $resistance = 0.0,
    ) {
    }
}
