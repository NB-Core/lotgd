<?php

declare(strict_types=1);

namespace Lotgd\Battle;

/**
 * The modifiers a round is fought under.
 *
 * These are the values battle.php keeps in globals between
 * Buffs::activateBuffs() and the damage roll: the four attack/defence
 * modifiers it copies out of the buffset (battle.php:227-230), the damage
 * modifiers the buffset carries directly, and the two forest settings that
 * govern the power attack.
 *
 * $adjustment is the difficulty scaling the forest applies to a creature's
 * defence. The core sets it to 1 and never moves it; it is carried here rather
 * than folded away because the arithmetic squares it, so a module that does
 * move it changes the result.
 */
final readonly class CombatContext
{
    public function __construct(
        public bool $isPvp = false,
        public float $adjustment = 1.0,
        public float $creatureAtkMod = 1.0,
        public float $creatureDefMod = 1.0,
        public float $atkMod = 1.0,
        public float $defMod = 1.0,
        public float $compAtkMod = 1.0,
        public float $compDefMod = 1.0,
        public float $dmgMod = 1.0,
        public float $badguyDmgMod = 1.0,
        public float $compDmgMod = 1.0,
        public bool $invulnerable = false,
        public int $powerAttackChance = 0,
        public float $powerAttackMulti = 1.0,
    ) {
    }
}
