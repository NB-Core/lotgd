<?php

declare(strict_types=1);

/**
 * Helpers for selecting random death messages.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Substitute;
use Lotgd\Random;

class DeathMessage
{
    /**
     * Select a random death message.
     *
     * @param bool  $forest   True for forest messages
     * @param array $extra    Placeholder names
     * @param array $extrarep Replacement values
     *
     * @return array
     */
    public static function select(bool $forest = true, array $extra = [], array $extrarep = []): array
    {
        global $session, $badguy;
        $where = ($forest ? 'WHERE forest=1' : 'WHERE graveyard=1');
        $sql = 'SELECT deathmessage,taunt FROM ' . Database::prefix('deathmessages') . " $where ORDER BY rand(" . Random::e_rand() . ') LIMIT 1';
        $result = Database::query($sql);
        if ($result) {
            $row = Database::fetchAssoc($result);
            $deathmessage = $row['deathmessage'];
            $taunt = $row['taunt'];
        } else {
            $taunt = 1;
            $deathmessage = "`5\"`6{goodguyname}'s mother wears combat boots`5\", screams {badguyname}.";
        }
        $deathmessage = Substitute::apply($deathmessage, $extra, $extrarep);
        return ['deathmessage' => $deathmessage, 'taunt' => $taunt];
    }

    /**
     * Select a death message and return the parsed array used by substitute_array.
     *
     * @param bool  $forest   True for forest messages
     * @param array $extra    Placeholders
     * @param array $extrarep Replacement values
     *
     * @return array
     */
    public static function selectArray(bool $forest = true, array $extra = [], array $extrarep = []): array
    {
        global $session, $badguy;
        $where = ($forest ? 'WHERE forest=1' : 'WHERE graveyard=1');
        $sql = 'SELECT deathmessage,taunt FROM ' . Database::prefix('deathmessages') . " $where ORDER BY rand(" . Random::e_rand() . ') LIMIT 1';
        $result = Database::query($sql);
        if ($result) {
            $row = Database::fetchAssoc($result);
            $deathmessage = $row['deathmessage'];
            $taunt = $row['taunt'];
        } else {
            $taunt = 1;
            $deathmessage = "`5\"`6{goodguyname}'s mother wears combat boots`5\", screams {badguyname}.";
        }
        if (isset($extra[0]) && $extra[0] === '{where}') {
            $deathmessage = str_replace($extra[0], $extrarep[0] ?? 'UNKNOWN', $deathmessage);
            array_shift($extra);
            array_shift($extrarep);
        }
        $deathmessage = Substitute::applyArray($deathmessage, $extra, $extrarep);
        array_unshift($deathmessage, true, 'deathmessages');
        return ['deathmessage' => $deathmessage, 'taunt' => $taunt];
    }
}
