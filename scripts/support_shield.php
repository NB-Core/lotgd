<?php

// Example support companion A.I. script.
// Provides a short-lived damage shield at the start of battle.

global $badguy;

if (!isset($badguy['shield_given'])) {
    apply_buff('companion_support_shield', [
        'name' => '`@Protective Aura',
        'startmsg' => '`@Your companion surrounds you with a shimmering shield.',
        'wearoff' => '`@The protective aura fades away.',
        'damageshield' => 0.3,
        'effectmsg' => '`@The shield reflects {damage} damage back at the attacker!',
        'effectnodmgmsg' => '`@The shield absorbs the blow.',
        'rounds' => 3,
        'schema' => 'battle',
    ]);
    $badguy['shield_given'] = true;
}
