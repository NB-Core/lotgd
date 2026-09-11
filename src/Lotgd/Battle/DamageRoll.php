<?php

declare(strict_types=1);

namespace Lotgd\Battle;

/**
 * What one exchange of blows produced.
 *
 * Damage is signed in both directions: a negative creatureDamage is a riposte
 * the attacker takes, and a negative selfDamage is one the defender takes.
 *
 * attackRoll is not the attacker's attack score but the roll made with it. The
 * distinction matters because battle.php feeds this value to
 * Battle::reportPowerMove(), which compares it against the score to decide
 * whether the blow was a power move.
 *
 * Both attackRoll and creatureAttack are null when no exchange took place --
 * one side was already down, so nothing was rolled. That is not the same as
 * rolling a zero, and the difference is visible: the legacy globals $atk and
 * $creatureattack keep their previous values across such a call rather than
 * being reset, and battle.php reads $atk on every round.
 */
final readonly class DamageRoll
{
    public function __construct(
        public int|float $creatureDamage,
        public int|float $selfDamage,
        public int|float|null $attackRoll,
        public int|float|null $creatureAttack,
    ) {
    }
}
