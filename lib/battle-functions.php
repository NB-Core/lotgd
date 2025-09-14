<?php

namespace Lotgd {
    class Fightbar extends \FightBar
    {
    }

    \class_alias(Fightbar::class, 'fightbar');
}

namespace {
    use Lotgd\Battle;

    /**
     * Adds a new creature to the current battle.
     *
     * @param mixed $creature Creature array or numeric ID
     */
    function battle_spawn($creature)
    {
        return Battle::battleSpawn($creature);
    }
}
