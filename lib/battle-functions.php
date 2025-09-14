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
     *
     * @return mixed
     */
    function battle_spawn($creature)
    {
        return Battle::battleSpawn($creature);
    }

    /**
     * Heal a target in battle.
     *
     * @param int   $amount Amount to heal
     * @param mixed $target Target to heal
     *
     * @return mixed
     */
    function battle_heal(int $amount, $target = false)
    {
        return Battle::battleHeal($amount, $target);
    }

    /**
     * Show current enemies.
     *
     * @param array $enemies List of enemies
     *
     * @return mixed
     */
    function show_enemies(array $enemies = [])
    {
        return Battle::showEnemies($enemies);
    }

    /**
     * Prepare a fight.
     *
     * @param mixed $options Options for the fight
     *
     * @return mixed
     */
    function prepare_fight($options = false)
    {
        return Battle::prepareFight($options);
    }

    /**
     * Prepare companions for battle.
     */
    function prepare_companions()
    {
        Battle::prepareCompanions();
    }

    /**
     * Suspend companions during battle.
     *
     * @param mixed $susp  Companion to suspend
     * @param bool  $nomsg Whether to suppress messages
     */
    function suspend_companions($susp, bool $nomsg = false)
    {
        Battle::suspendCompanions($susp, $nomsg);
    }

    /**
     * Unsuspend companions during battle.
     *
     * @param mixed $susp  Companion to unsuspend
     * @param bool  $nomsg Whether to suppress messages
     */
    function unsuspend_companions($susp, bool $nomsg = false)
    {
        Battle::unsuspendCompanions($susp, $nomsg);
    }

    /**
     * Automatically set the battle target.
     *
     * @param mixed $localenemies Enemies list
     *
     * @return mixed
     */
    function auto_set_target($localenemies)
    {
        return Battle::autoSetTarget($localenemies);
    }

    /**
     * Report a companion's move.
     *
     * @param array  $badguy    Badguy data
     * @param mixed  $companion Companion data
     * @param string $activate  Action to activate
     *
     * @return mixed
     */
    function report_companion_move(&$badguy, $companion, string $activate = 'fight')
    {
        return Battle::reportCompanionMove($badguy, $companion, $activate);
    }

    /**
     * Roll companion damage.
     *
     * @param array $badguy    Badguy data
     * @param mixed $companion Companion data
     *
     * @return mixed
     */
    function roll_companion_damage(&$badguy, $companion)
    {
        return Battle::rollCompanionDamage($badguy, $companion);
    }

    /**
     * Execute an AI script.
     *
     * @param mixed $script Script to execute
     */
    function execute_ai_script($script)
    {
        Battle::executeAiScript($script);
    }
}
