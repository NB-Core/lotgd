<?php
namespace Lotgd;

/**
 * Helpers for selecting random death messages.
 */
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
    public static function select($forest = true, $extra = [], $extrarep = [])
    {
        global $session, $badguy;
        $where = ($forest ? 'WHERE forest=1' : 'WHERE graveyard=1');
        $sql = 'SELECT deathmessage,taunt FROM ' . db_prefix('deathmessages') . " $where ORDER BY rand(" . e_rand() . ') LIMIT 1';
        $result = db_query($sql);
        if ($result) {
            $row = db_fetch_assoc($result);
            $deathmessage = $row['deathmessage'];
            $taunt = $row['taunt'];
        } else {
            $taunt = 1;
            $deathmessage = "`5\"`6{goodguyname}'s mother wears combat boots`5\", screams {badguyname}.";
        }
        $deathmessage = substitute($deathmessage, $extra, $extrarep);
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
    public static function selectArray($forest = true, $extra = [], $extrarep = [])
    {
        global $session, $badguy;
        $where = ($forest ? 'WHERE forest=1' : 'WHERE graveyard=1');
        $sql = 'SELECT deathmessage,taunt FROM ' . db_prefix('deathmessages') . " $where ORDER BY rand(" . e_rand() . ') LIMIT 1';
        $result = db_query($sql);
        if ($result) {
            $row = db_fetch_assoc($result);
            $deathmessage = $row['deathmessage'];
            $taunt = $row['taunt'];
        } else {
            $taunt = 1;
            $deathmessage = "`5\"`6{goodguyname}'s mother wears combat boots`5\", screams {badguyname}.";
        }
        if (isset($extra[0]) && $extra[0] == '{where}') {
            $deathmessage = str_replace($extra[0], $extrarep[0] ?? 'UNKNOWN', $deathmessage);
        }
        $deathmessage = substitute_array($deathmessage, $extra, $extrarep);
        array_unshift($deathmessage, true, 'deathmessages');
        return ['deathmessage' => $deathmessage, 'taunt' => $taunt];
    }
}

