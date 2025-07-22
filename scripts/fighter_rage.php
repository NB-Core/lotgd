<?php

// Example fighter companion A.I. script.
// Grants an attack bonus when the player's health drops below 50% once per fight.

global $badguy, $session;

if (!isset($badguy['rage_used'])) {
    $badguy['rage_used'] = false;
}

if (!$badguy['rage_used'] && $session['user']['hitpoints'] < $session['user']['maxhitpoints'] * 0.5) {
    apply_buff('companion_fighter_rage', [
        'name' => '`&Battle Frenzy',
        'startmsg' => '`&Your fighter companion enters a frenzy!',
        'roundmsg' => '`&Frenzied strikes empower you.',
        'wearoff' => '`&The frenzy subsides.',
        'atkmod' => 1.2,
        'rounds' => 5,
        'schema' => 'battle',
    ]);
    $badguy['rage_used'] = true;
}
