<?php

declare(strict_types=1);

namespace Lotgd\Encounter;

/**
 * What runs an accepted encounter (docs/ReactiveCore.md, Phase 7). An engine
 * takes the two players' values when the encounter begins and in the end
 * yields an Outcome; what the outcome costs or pays is not its business
 * (Encounter::settle()). The round duel is the first; an arena server that
 * runs its own game would be another, handed the same start values and
 * handing back the same Outcome.
 */
interface Engine
{
    /**
     * The kind of encounter this engine runs (encounters.kind).
     */
    public function kind(): string;

    /**
     * The state the encounter starts with (encounters.data).
     *
     * @param array<int,array<string,mixed>> $players By account id: name, level, attack, defense, hitpoints.
     * @return array<string,mixed>
     */
    public function start(array $players): array;
}
