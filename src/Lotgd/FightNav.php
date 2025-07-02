<?php
namespace Lotgd;

/**
 * Provide helper functions for building the fight navigation menu.
 */
class FightNav
{
    /**
     * Display the fight navigation links.
     *
     * @param bool $allowSpecial Allow use of special abilities
     * @param bool $allowFlee    Allow the flee option
     * @param mixed $script      Optional URL where actions should point to
     */
    public static function fightnav(bool $allowSpecial = true, bool $allowFlee = true, $script = false): void
    {
        global $session, $newenemies, $companions;
        tlschema('fightnav');
        if ($script === false) {
            $script = substr($_SERVER['PHP_SELF'], strrpos($_SERVER['PHP_SELF'], '/') + 1) . '?';
        } else {
            if (!strpos($script, '?')) {
                $script .= '?';
            } elseif (substr($script, -1) != '&') {
                $script .= '&';
            }
        }
        $fight = 'Fight';
        $run   = 'Run';
        if (!$session['user']['alive']) {
            $fight = 'F?Torment';
            $run   = 'R?Flee';
        }
        addnav($fight, $script . 'op=fight');
        if ($allowFlee) {
            addnav($run, $script . 'op=run');
        }
        if ($session['user']['superuser'] & SU_DEVELOPER) {
            addnav('Abort', $script);
        }

        if (getsetting('autofight', 0)) {
            addnav('Automatic Fighting');
            addnav('5?For 5 Rounds', $script . 'op=fight&auto=five');
            addnav('1?For 10 Rounds', $script . 'op=fight&auto=ten');
            $auto = getsetting('autofightfull', 0);
            if (($auto == 1 || ($auto == 2 && !$allowFlee)) && count($newenemies) == 1) {
                addnav('U?Until End', $script . 'op=fight&auto=full');
            } elseif ($auto == 1 || ($auto == 2 && !$allowFlee)) {
                addnav('U?Until first enemy dies', $script . 'op=fight&auto=full');
            }
        }

        if ($allowSpecial) {
            addnav('Special Abilities');
            modulehook('fightnav-specialties', ['script' => $script]);

            if ($session['user']['superuser'] & SU_DEVELOPER) {
                addnav('`&Super user`0', '');
                addnav('!?`&&#149; __GOD MODE', $script . 'op=fight&skill=godmode', true);
            }
            modulehook('fightnav', ['script' => $script]);
        }

        if (count($newenemies) > 1) {
            addnav('Targets');
            foreach ($newenemies as $index => $badguy) {
                if ($badguy['creaturehealth'] <= 0 || (isset($badguy['dead']) && $badguy['dead'] == true)) {
                    continue;
                }
                addnav(["%s%s`0", (isset($badguy['istarget']) && $badguy['istarget']) ? '`#*`0' : '', $badguy['creaturename']], $script . "op=fight&newtarget=$index");
            }
        }
        tlschema();
    }
}
