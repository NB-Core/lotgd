<?php
namespace Lotgd;

use Lotgd\Buffs;

use Lotgd\BellRand;
class Battle
{
    public static function rollDamage(&$badguy)
    {
        global $session, $creatureattack, $creatureatkmod, $adjustment;
        global $creaturedefmod, $defmod, $atkmod, $buffset, $atk, $def, $options;

        if ($badguy['creaturehealth'] > 0 && $session['user']['hitpoints'] > 0) {
            if ($options['type'] == 'pvp') {
                $adjustedcreaturedefense = $badguy['creaturedefense'];
            } else {
                $adjustedcreaturedefense = (
                    $creaturedefmod * $badguy['creaturedefense'] /
                    ($adjustment * $adjustment)
                );
            }

            $creatureattack = $badguy['creatureattack'] * $creatureatkmod;
            $adjustedselfdefense = (get_player_defense() * $adjustment * $defmod);

            if (!isset($badguy['physicalresistance'])) {
                $badguy['physicalresistance'] = 0;
            }
            $powerattack = (int) getsetting('forestpowerattackchance', 10);
            $powerattackmulti = (float) getsetting('forestpowerattackmulti', 3);

            while (!isset($creaturedmg) || !isset($selfdmg) || ($creaturedmg == 0 && $selfdmg == 0)) {
                $atk = get_player_attack() * $atkmod;
                if (e_rand(1, 20) == 1 && $options['type'] != 'pvp') {
                    $atk *= 2;
                }
                $patkroll = BellRand::generate(0, $atk);
                $atk = $patkroll;
                $catkroll = BellRand::generate(0, $adjustedcreaturedefense);
                $creaturedmg = 0 - (int) ($catkroll - $patkroll);
                if ($creaturedmg < 0) {
                    $creaturedmg = (int) ($creaturedmg / 2);
                    $creaturedmg = round($buffset['badguydmgmod'] * $creaturedmg, 0);
                    $creaturedmg = min(0, round($creaturedmg - $badguy['physicalresistance']));
                }
                if ($creaturedmg > 0) {
                    $creaturedmg = round($buffset['dmgmod'] * $creaturedmg, 0);
                    $creaturedmg = max(0, round($creaturedmg - $badguy['physicalresistance']));
                }
                $pdefroll = BellRand::generate(0, $adjustedselfdefense);
                $catkroll = BellRand::generate(0, $creatureattack);
                if ($powerattack != 0 && $options['type'] != 'pvp') {
                    if (e_rand(1, $powerattack) == 1) {
                        $catkroll *= $powerattackmulti;
                    }
                }
                $selfdmg = 0 - (int) ($pdefroll - $catkroll);
                if ($selfdmg < 0) {
                    $selfdmg = (int) ($selfdmg / 2);
                    $selfdmg = round($selfdmg * $buffset['dmgmod'], 0);
                    $selfdmg = min(0, round($selfdmg - ((int) get_player_physical_resistance()), 0));
                }
                if ($selfdmg > 0) {
                    $selfdmg = round($selfdmg * $buffset['badguydmgmod'], 0);
                    $selfdmg = max(0, round($selfdmg - ((int) get_player_physical_resistance()), 0));
                }
            }
        } else {
            $creaturedmg = 0;
            $selfdmg = 0;
        }

        if ($buffset['invulnerable']) {
            $creaturedmg = abs($creaturedmg);
            $selfdmg = -abs($selfdmg);
        }

        return [
            'creaturedmg' => (isset($creaturedmg) ? $creaturedmg : 0),
            'selfdmg' => (isset($selfdmg) ? $selfdmg : 0),
        ];
    }

    public static function reportPowerMove($crit, $dmg)
    {
        global $session;
        $uatk = get_player_attack();
        if ($crit > $uatk) {
            $power = 0;
            if ($crit > $uatk * 4) {
                $msg = "`&`bYou execute a `%MEGA`& power move!!!`b`n";
                $power = 1;
            } elseif ($crit > $uatk * 3) {
                $msg = "`&`bYou execute a `^DOUBLE`& power move!!!`b`n";
                $power = 1;
            } elseif ($crit > $uatk * 2) {
                $msg = "`&`bYou execute a power move!!!`b`0`n";
                $power = 1;
            } elseif ($crit > ($uatk * 1.5)) {
                $msg = "`7`bYou execute a minor power move!`b`0`n";
                $power = 1;
            }
            if ($power) {
                tlschema('battle');
                output($msg);
                tlschema();

                $dmg += e_rand($crit / 4, $crit / 2);
                $dmg = max($dmg, 1);
            }
        }
        return $dmg;
    }

    public static function suspendBuffs($susp = false, $msg = false)
    {
        global $session, $badguy;
        $suspendnotify = 0;
        reset($session['bufflist']);
        foreach ($session['bufflist'] as $key => $buff) {
            if (array_key_exists('suspended', $buff) && $buff['suspended']) {
                continue;
            }
            if ($susp && (!isset($buff[$susp]) || !$buff[$susp])) {
                $session['bufflist'][$key]['suspended'] = 1;
                $suspendnotify = 1;
            }
            $buff['used'] = 0;
        }

        if ($suspendnotify) {
            $schema = false;
            if ($msg === false) {
                $schema = 'battle';
                $msg = "`&The gods have suspended some of your enhancements!`n";
            }
            if ($schema) {
                tlschema($schema);
            }
            output(sanitize_mb($msg));
            if ($schema) {
                tlschema();
            }
        }
    }

    public static function suspendBuffByName($name, $msg = false)
    {
        global $session;
        if (
            isset($session['bufflist'][$name]) &&
            $session['bufflist'][$name] &&
            (!isset($session['bufflist'][$name]['suspended']) || !$session['bufflist'][$name]['suspended'])
        ) {
            $session['bufflist'][$name]['suspended'] = 1;

            $schema = false;
            if ($msg === false) {
                $schema = 'battle';
                $msg = "`&The gods have suspended some of your enhancements!`n";
            }
            if ($schema) {
                tlschema($schema);
            }
            output($msg);
            if ($schema) {
                tlschema();
            }
        }
    }

    public static function unsuspendBuffByName($name, $msg = false)
    {
        global $session;
        if ($session['bufflist'][$name] && $session['bufflist'][$name]['suspended']) {
            $session['bufflist'][$name]['suspended'] = 0;

            $schema = false;
            if ($msg === false) {
                $schema = 'battle';
                $msg = "`&The gods have restored all suspended enhancements.`n`n";
            }
            if ($schema) {
                tlschema($schema);
            }
            output($msg);
            if ($schema) {
                tlschema();
            }
        }
    }

    public static function isBuffActive($name)
    {
        global $session;
        return (($session['bufflist'][$name] && !$session['bufflist'][$name]['suspended']) ? 1 : 0);
    }

    public static function unsuspendBuffs($susp = false, $msg = false)
    {
        global $session, $badguy;
        $unsuspendnotify = 0;
        reset($session['bufflist']);
        foreach ($session['bufflist'] as $key => $buff) {
            if (array_key_exists('expireafterfight', $buff) && $buff['expireafterfight']) {
                unset($session['bufflist'][$key]);
            } elseif (array_key_exists('suspended', $buff) && $buff['suspended'] && $susp && (!array_key_exists($susp, $buff) || !$buff[$susp])) {
                $session['bufflist'][$key]['suspended'] = 0;
                $unsuspendnotify = 1;
            }
        }

        if ($unsuspendnotify) {
            $schema = false;
            if ($msg === false) {
                $schema = 'battle';
                $msg = "`&The gods have restored all suspended enhancements.`n`n";
            }
            if ($schema) {
                tlschema($schema);
            }
            output($msg);
            if ($schema) {
                tlschema();
            }
        }
    }

    public static function applyBodyguard($level)
    {
        global $session, $badguy;
        if (!isset($session['bufflist']['bodyguard'])) {
            switch ($level) {
                case 1:
                    $badguyatkmod = 1.05;
                    $defmod = 0.95;
                    $rounds = -1;
                    break;
                case 2:
                    $badguyatkmod = 1.1;
                    $defmod = 0.9;
                    $rounds = -1;
                    break;
                case 3:
                    $badguyatkmod = 1.2;
                    $defmod = 0.8;
                    $rounds = -1;
                    break;
                case 4:
                    $badguyatkmod = 1.3;
                    $defmod = 0.7;
                    $rounds = -1;
                    break;
                case 5:
                    $badguyatkmod = 1.4;
                    $defmod = 0.6;
                    $rounds = -1;
                    break;
            }
            Buffs::applyBuff('bodyguard', [
                'startmsg' => "`\${badguy}'s bodyguard protects them!",
                'name' => '`&Bodyguard',
                'wearoff' => 'The bodyguard seems to have fallen asleep.',
                'badguyatkmod' => $badguyatkmod,
                'defmod' => $defmod,
                'rounds' => $rounds,
                'allowinpvp' => 1,
                'expireafterfight' => 1,
                'schema' => 'pvp',
            ]);
        }
    }

    public static function applySkill($skill, $l)
    {
        global $session;
        if ($skill == 'godmode') {
            Buffs::applyBuff('godmode', [
                'name' => '`&GOD MODE',
                'rounds' => 1,
                'wearoff' => 'You feel mortal again.',
                'atkmod' => 25,
                'defmod' => 25,
                'invulnerable' => 1,
                'startmsg' => '`&`bYou feel godlike.`b',
                'schema' => 'skill',
            ]);
        }
        modulehook('apply-specialties');
    }
}
