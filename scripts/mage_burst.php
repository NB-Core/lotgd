<?php

// Example mage companion A.I. script.
// Casts a damaging burst a few times per battle when the foe is wounded.

global $badguy;

if (!isset($badguy['burst_charges'])) {
    $badguy['burst_charges'] = 3;
    if (!isset($badguy['maxhealth'])) {
        $badguy['maxhealth'] = $badguy['creaturehealth'];
    }
}

if ($badguy['burst_charges'] > 0 && $badguy['creaturehealth'] <= $badguy['maxhealth'] * 0.8) {
    apply_buff('companion_mage_burst', [
        'name' => '`!Arcane Burst',
        'rounds' => 1,
        'minioncount' => 1,
        'minbadguydamage' => 5,
        'maxbadguydamage' => 10,
        'effectmsg' => '`!A burst of arcane energy hits the enemy for {damage} damage!',
        'schema' => 'battle',
    ]);
    $badguy['burst_charges']--;
}
