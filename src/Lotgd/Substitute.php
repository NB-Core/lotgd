<?php
namespace Lotgd;

/**
 * Helper to replace battle text variables with runtime values.
 */
class Substitute
{
    /**
     * Replace markers in a string.
     *
     * @param string       $string  Text to process
     * @param array|false  $extra   Optional markers
     * @param array|false  $extrarep Replacement values
     */
    public static function apply(string $string, $extra = false, $extrarep = false): string
    {
        global $badguy, $session;
        $search = [
            '{himher}',
            '{heshe}',
            '{hisher}',
            '{goodguyweapon}',
            '{badguyweapon}',
            '{goodguyarmor}',
            '{badguyname}',
            '{goodguyname}',
            '{badguy}',
            '{goodguy}',
            '{weapon}',
            '{armor}',
            '{creatureweapon}',
        ];
        $replace = [
            translate_inline($session['user']['sex'] ? 'her' : 'him', 'buffs'),
            ($session['user']['sex'] ? 'she' : 'he'),
            ($session['user']['sex'] ? 'her' : 'his'),
            $session['user']['weapon'],
            $badguy['creatureweapon'],
            $session['user']['armor'],
            $badguy['creaturename'],
            '`^' . $session['user']['name'] . '`^',
            $badguy['creaturename'],
            '`^' . $session['user']['name'] . '`^',
            $session['user']['weapon'],
            $session['user']['armor'],
            $badguy['creatureweapon'],
        ];
        if ($extra !== false && $extrarep !== false) {
            $search = array_merge($search, $extra);
            $replace = array_merge($replace, $extrarep);
        }
        return str_replace($search, $replace, $string);
    }

    /**
     * Variant of apply() returning an array for sprintf usage.
     */
    public static function applyArray(string $string, $extra = false, $extrarep = false): array
    {
        global $badguy, $session;
        $search = ['{himher}', '{heshe}', '{hisher}'];
        $replace = [($session['user']['sex'] ? 'her' : 'him'), ($session['user']['sex'] ? 'she' : 'he'), ($session['user']['sex'] ? 'her' : 'his')];
        $string = str_replace($search, $replace, $string);
        $search = ['{goodguyweapon}', '{badguyweapon}', '{goodguyarmor}', '{badguyname}', '{goodguyname}', '{badguy}', '{goodguy}', '{weapon}', '{armor}', '{creatureweapon}'];
        if (!isset($badguy)) {
            $badguy = ['creatureweapon' => '', 'creaturename' => ''];
        }
        $replace = [
            $session['user']['weapon'],
            $badguy['creatureweapon'],
            $session['user']['armor'],
            $badguy['creaturename'],
            '`^' . $session['user']['name'] . '`^',
            $badguy['creaturename'],
            '`^' . $session['user']['name'] . '`^',
            $session['user']['weapon'],
            $session['user']['armor'],
            $badguy['creatureweapon'],
        ];
        if ($extra !== false && $extrarep !== false) {
            $search = array_merge($search, $extra);
            $replace = array_merge($replace, $extrarep);
        }
        $replacement_array = [$string];
        $length = strlen($replacement_array[0]);
        for ($x = 0; $x < $length; $x++) {
            foreach ($search as $skey => $sval) {
                $rval = $replace[$skey];
                if (substr($replacement_array[0], $x, strlen($sval)) == $sval) {
                    array_push($replacement_array, $rval);
                    $replacement_array[0] = substr($replacement_array[0], 0, $x) . '%s' . substr($replacement_array[0], $x + strlen($sval));
                    $x = -1;
                    break;
                }
            }
        }
        return $replacement_array;
    }
}
