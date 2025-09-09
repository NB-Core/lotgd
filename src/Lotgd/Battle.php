<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Buffs;
use Lotgd\FightBar;
use Lotgd\BellRand;
use Lotgd\Substitute;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\PlayerFunctions;
use Lotgd\Settings;
use Lotgd\Sanitize;
use Lotgd\Translator;

class Battle
{
    /**
     * Calculate damage for a combat round.
     *
     * @param array $badguy Enemy data (modified in place)
     *
     * @return array{creaturedmg:int,selfdmg:int}
     */
    public static function rollDamage(array &$badguy): array
    {
        global $session, $creatureattack, $creatureatkmod, $adjustment;
        global $creaturedefmod, $defmod, $atkmod, $buffset, $atk, $def, $options;
        $creaturedmg = 0;
        $selfdmg     = 0;

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
            $adjustedselfdefense = (PlayerFunctions::getPlayerDefense() * $adjustment * $defmod);

            if (!isset($badguy['physicalresistance'])) {
                $badguy['physicalresistance'] = 0;
            }
            $settings = Settings::getInstance();
            $powerattack = (int) $settings->getSetting('forestpowerattackchance', 10);
            $powerattackmulti = (float) $settings->getSetting('forestpowerattackmulti', 3);

            while ($creaturedmg == 0 && $selfdmg == 0) {
                $atk = PlayerFunctions::getPlayerAttack() * $atkmod;
                if (random_int(1, 20) == 1 && $options['type'] != 'pvp') {
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
                    if (random_int(1, $powerattack) == 1) {
                        $catkroll *= $powerattackmulti;
                    }
                }
                $selfdmg = 0 - (int) ($pdefroll - $catkroll);
                if ($selfdmg < 0) {
                    $selfdmg = (int) ($selfdmg / 2);
                    $selfdmg = round($selfdmg * $buffset['dmgmod'], 0);
                    $selfdmg = min(0, round($selfdmg - ((int) PlayerFunctions::getPlayerPhysicalResistance()), 0));
                }
                if ($selfdmg > 0) {
                    $selfdmg = round($selfdmg * $buffset['badguydmgmod'], 0);
                    $selfdmg = max(0, round($selfdmg - ((int) PlayerFunctions::getPlayerPhysicalResistance()), 0));
                }
            }
        }

        if ($buffset['invulnerable']) {
            $creaturedmg = abs($creaturedmg);
            $selfdmg = -abs($selfdmg);
        }

        return [
            'creaturedmg' => $creaturedmg,
            'selfdmg' => $selfdmg,
        ];
    }

    public static function reportPowerMove(int $crit, int $dmg)
    {
        global $session;
        $uatk = PlayerFunctions::getPlayerAttack();
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
                Translator::getInstance()->setSchema('battle');
                Output::getInstance()->output($msg);
                Translator::getInstance()->setSchema();

                $dmg += random_int((int)($crit / 4), (int)($crit / 2));
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
                Translator::getInstance()->setSchema($schema);
            }
            Output::getInstance()->output(Sanitize::sanitizeMb($msg));
            if ($schema) {
                Translator::getInstance()->setSchema();
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
                Translator::getInstance()->setSchema($schema);
            }
            Output::getInstance()->output($msg);
            if ($schema) {
                Translator::getInstance()->setSchema();
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
                Translator::getInstance()->setSchema($schema);
            }
            Output::getInstance()->output($msg);
            if ($schema) {
                Translator::getInstance()->setSchema();
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
                Translator::getInstance()->setSchema($schema);
            }
            Output::getInstance()->output($msg);
            if ($schema) {
                Translator::getInstance()->setSchema();
            }
        }
    }

    public static function applyBodyguard($level)
    {
        global $session, $badguy;
        if (!isset($session['bufflist']['bodyguard'])) {
            $badguyatkmod = 1.0;
            $defmod       = 1.0;
            $rounds       = -1;

            switch ($level) {
                case 1:
                    $badguyatkmod = 1.05;
                    $defmod = 0.95;
                    break;
                case 2:
                    $badguyatkmod = 1.1;
                    $defmod = 0.9;
                    break;
                case 3:
                    $badguyatkmod = 1.2;
                    $defmod = 0.8;
                    break;
                case 4:
                    $badguyatkmod = 1.3;
                    $defmod = 0.7;
                    break;
                case 5:
                    $badguyatkmod = 1.4;
                    $defmod = 0.6;
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

/**
    * Select a random taunt and substitute battle variables.
    */
    public static function selectTaunt(): string
    {
        $sql = 'SELECT taunt FROM ' . Database::prefix('taunts') .
        ' ORDER BY rand(' . random_int(0, mt_getrandmax()) . ') LIMIT 1';

        $result = Database::query($sql);
        if ($result) {
            $row = Database::fetchAssoc($result);
            $taunt = $row['taunt'];
        } else {
            $taunt = "`5\"`6{badgyuname}'s mother wears combat boots`5\", screams {goodguyname}.";
        }

        return Substitute::apply($taunt);
    }

/**
    * Variant of selectTaunt() returning values for sprintf.
    */
    public static function selectTauntArray(): array
    {
        $sql = 'SELECT taunt FROM ' . Database::prefix('taunts') .
        ' ORDER BY rand(' . random_int(0, mt_getrandmax()) . ') LIMIT 1';

        $result = Database::query($sql);
        if ($result) {
            $row = Database::fetchAssoc($result);
            $taunt = $row['taunt'];
        } else {
            $taunt = "`5\"`6{badgyuname}'s mother wears combat boots`5\", screams {goodguyname}.";
        }

        $taunt = Substitute::applyArray($taunt);
        array_unshift($taunt, true, 'taunts');

        return $taunt;
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
        HookHandler::hook('apply-specialties');
    }

    public static function fightnav(bool $allowSpecial = true, bool $allowFlee = true, $script = false): void
    {
        global $session, $newenemies, $companions;
        Translator::getInstance()->setSchema('fightnav');
        if ($script === false) {
            $script = ScriptName::current() . '.php?';
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
        Nav::add($fight, $script . 'op=fight');
        if ($allowFlee) {
            Nav::add($run, $script . 'op=run');
        }
        if ($session['user']['superuser'] & \SU_DEVELOPER) {
            Nav::add('Abort', $script);
        }

        $settings = Settings::getInstance();
        if ($settings->getSetting('autofight', 0)) {
            Nav::add('Automatic Fighting');
            Nav::add('5?For 5 Rounds', $script . 'op=fight&auto=five');
            Nav::add('1?For 10 Rounds', $script . 'op=fight&auto=ten');
            $auto = $settings->getSetting('autofightfull', 0);
            if (($auto == 1 || ($auto == 2 && !$allowFlee)) && count($newenemies) == 1) {
                Nav::add('U?Until End', $script . 'op=fight&auto=full');
            } elseif ($auto == 1 || ($auto == 2 && !$allowFlee)) {
                Nav::add('U?Until first enemy dies', $script . 'op=fight&auto=full');
            }
        }

        if ($allowSpecial) {
            Nav::add('Special Abilities');
            HookHandler::hook('fightnav-specialties', ['script' => $script]);

            if ($session['user']['superuser'] & \SU_DEVELOPER) {
                Nav::add('`&Super user`0', '');
                Nav::add('!?`&&#149; __GOD MODE', $script . 'op=fight&skill=godmode', true);
            }
            HookHandler::hook('fightnav', ['script' => $script]);
        }

        if (count($newenemies) > 1) {
            Nav::add('Targets');
            foreach ($newenemies as $index => $badguy) {
                if ($badguy['creaturehealth'] <= 0 || (isset($badguy['dead']) && $badguy['dead'] == true)) {
                    continue;
                }
                Nav::add(["%s%s`0", (isset($badguy['istarget']) && $badguy['istarget']) ? '`#*`0' : '', $badguy['creaturename']], $script . "op=fight&newtarget=$index");
            }
        }
        Translator::getInstance()->setSchema();
    }

    public static function showEnemies($enemies = [])
    {
        global $enemycounter, $session;
        $output = Output::getInstance();
        $settings = Settings::getInstance();
        $u = &$session['user']; //fast and better, by pointer
        static $fightbar = null;
        if ($fightbar === null) {
            //only once per fight
            $fightbar = new FightBar();
        }

        $barDisplay = (int) ($session['user']['prefs']['forestcreaturebar'] ?? $settings->getSetting('forestcreaturebar', 0));
        if (!isset($session['user']['prefs']['forestcreaturebar'])) {
            $session['user']['prefs']['forestcreaturebar'] = $barDisplay;
        }

        if ($u['alive']) {
            $hitpointstext = Translator::translateInline("Hitpoints");
            $healthtext    = $output->appoencode(Translator::translateInline("`^Health"));
        } else {
            $hitpointstext = Translator::translateInline("Soulpoints");
            $healthtext    = $output->appoencode(Translator::translateInline("`)Soul"));
        }

        //show all enemies including their stats
        foreach ($enemies as $index => $badguy) {
            if ((isset($badguy['istarget']) && $badguy['istarget'] == true) && $enemycounter > 1) {
                $ccode = "`#";
            } else {
                $ccode = "`2";
            }
            if (!isset($badguy['creaturemaxhealth']) && isset($badguy['creaturehealth'])) {
                $badguy['creaturemaxhealth'] = $badguy['creaturehealth'];
            }
            if (isset($badguy['hidehitpoints']) && $badguy['hidehitpoints'] == true) {
                $maxhealth = $health = "???";
            } else {
                $health = $badguy['creaturehealth'];
                $maxhealth = $badguy['creaturemaxhealth'];
            }
            switch ($barDisplay) {
                case 2:
                    $output->output(
                        "%s%s%s%s (Level %s)`n",
                        $ccode,
                        (isset($badguy['istarget']) && $badguy['istarget'] && $enemycounter > 1) ? "*" : "",
                        $badguy['creaturename'],
                        $ccode,
                        $badguy['creaturelevel']
                    );
                    $output->rawOutput("<table style='border:0;padding:0;margin:0;margin-left:20px;'><tr><td>");
                    $output->outputNotl("&nbsp;&nbsp;&nbsp;%s: ", $healthtext, true);
                    $output->rawOutput("</td><td>");
                    $output->rawOutput($fightbar->getBar((int)$badguy['creaturehealth'], (int)$badguy['creaturemaxhealth']));
                    $output->rawOutput("</td><td>");
                    $output->outputNotl("(%s/%s) %s`0`n", $health, $maxhealth, $badguy['creaturehealth'] > 0 ? "" : Translator::translateInline("`7DEFEATED`0"), true);
                    $output->rawOutput("</td></tr></table>");
                    break;

                case 1:
                    $output->output(
                        "%s%s%s%s (Level %s)`n",
                        $ccode,
                        (isset($badguy['istarget']) && $badguy['istarget'] && $enemycounter > 1) ? "*" : "",
                        $badguy['creaturename'],
                        $ccode,
                        $badguy['creaturelevel']
                    );
                    $output->rawOutput("<table style='border:0;padding:0;margin:0;margin-left:20px;'><tr><td>");
                    $output->outputNotl("&nbsp;&nbsp;&nbsp;%s: ", $healthtext, true);
                    $output->rawOutput("</td><td>");
                    $output->rawOutput($fightbar->getBar((int)$badguy['creaturehealth'], (int)$badguy['creaturemaxhealth']));
                    $output->rawOutput("</td><td>");
                    $output->outputNotl("%s`0`n", $badguy['creaturehealth'] > 0 ? "" : Translator::translateInline("`7DEFEATED`0"), true);
                    $output->rawOutput("</td></tr></table>");
                    // no break

                default:
                    $output->output(
                        "%s%s%s%s's %s%s (Level %s): `6%s`0`n",
                        $ccode,
                        (isset($badguy['istarget']) && $badguy['istarget'] && $enemycounter > 1) ? "*" : "",
                        $badguy['creaturename'],
                        $ccode,
                        $hitpointstext,
                        $ccode,
                        $badguy['creaturelevel'],
                        ($badguy['creaturehealth'] > 0 ? $health : Translator::translateInline("`7DEFEATED`0"))
                    );
            }
        }
        if ($u['alive']) {
            $hitpointstext = $u['name'] . "`0";
            $dead = false;
        } else {
            $hitpointstext = Translator::sprintfTranslate("Soul of %s", $u['name']);
            $dead = true;
            $maxsoul = 50 + 10 * $u['level'] + $u['dragonkills'] * 2;
        }

        $hitpoints = (int)$u['hitpoints'];
        $maxhitpoints = (int)$u['maxhitpoints'];
        //your faction display (companions?)
        switch ($barDisplay) {
            case 2:
                $output->output(
                    "`l%s:`n",
                    $hitpointstext
                );
                $output->rawOutput("<table style='border:0;padding:0;margin:0;margin-left:20px;'><tr><td>");
                $output->outputNotl("&nbsp;&nbsp;&nbsp;%s: ", $healthtext, true);
                $output->rawOutput("</td><td>");
                if (!$dead) {
                    $output->rawOutput($fightbar->getBar($hitpoints, $maxhitpoints));
                } else {
                    $output->rawOutput($fightbar->getBar($hitpoints, $maxsoul));
                }
                $output->rawOutput("</td><td>");
                if (!$dead) {
                    $output->outputNotl("(%s/%s) %s`0`n", $hitpoints, $maxhitpoints, $hitpoints > 0 ? "" : Translator::translateInline("`7DEFEATED`0"), true);
                } else {
                    $output->outputNotl("(%s/%s) %s`0`n", $hitpoints, $maxsoul, $hitpoints > 0 ? "" : Translator::translateInline("`7DEFEATED`0"), true);
                }

                $output->rawOutput("</td></tr></table>");
                break;

            case 1:
                $output->output(
                    "`l%s:`n",
                    $hitpointstext
                );
                $output->rawOutput("<table style='border:0;padding:0;margin:0;margin-left:20px;'><tr><td>");
                $output->outputNotl("&nbsp;&nbsp;&nbsp;%s: ", $healthtext, true);
                $output->rawOutput("</td><td>");
                if (!$dead) {
                    $output->rawOutput($fightbar->getBar($hitpoints, $maxhitpoints));
                } else {
                    $output->rawOutput($fightbar->getBar($hitpoints, $maxsoul));
                }
                $output->rawOutput("</td><td>");

                $output->rawOutput("</td></tr></table>");
                // no break

            default:
                $output->output("`l%s: `6%s`0`n", $hitpointstext, $u['hitpoints']);
        }
    }

/**
 * This function prepares the fight, sets up options and gives hook a hook to change options on a per-player basis.
 *
 * @param array $options The options given by a module or basics.
 * @return array The complete options.
 */
    public static function prepareFight($options = false)
    {
        global $companions;
        $settings = Settings::getInstance();
        $basicoptions = [
        "maxattacks" => (int) $settings->getSetting('maxattacks', 4),
        ];
        if (!is_array($options)) {
            $options = array();
        }
        $fightoptions = $options + $basicoptions;
        $fightoptions = HookHandler::hook("fightoptions", $fightoptions);

        // We'll also reset the companions here...
        self::prepareCompanions();
        return $fightoptions;
    }

/**
 * This functions prepares companions to be able to take part in a fight. Uses global copies.
 *
 */
    public static function prepareCompanions()
    {
        global $companions;
        $newcompanions = array();
        if (is_array($companions)) {
            foreach ($companions as $name => $companion) {
                if (!isset($companion['suspended']) || $companion['suspended'] == false) {
                    $companion['used'] = false;
                }
                $newcompanions[$name] = $companion;
            }
        }
        $companions = $newcompanions;
    }

/**
 * Suspends companions on a given parameter.
 *
 * @param string $susp The type of suspension
 * @param mixed $nomsg The message to be displayed upon suspending. If false, no message will be displayed.
 */
    public static function suspendCompanions($susp, $nomsg = false)
    {
        global $companions;
        $newcompanions = array();
        $suspended = false;
        if (is_array($companions)) {
            foreach ($companions as $name => $companion) {
                if ($susp) {
                    if (isset($companion[$susp]) && $companion[$susp] == true) {
                    } else {
                        if (isset($companion['suspended']) && $companion['suspended'] == true) {
                        } else {
                            $suspended = true;
                            $companion['suspended'] = true;
                        }
                    }
                }
                $newcompanions[$name] = $companion;
            }
        }

        if ($suspended) {
            $schema = false;
            if ($nomsg === false) {
                $schema = "battle";
                $nomsg = "`&Your companions stand back during this fight!`n";
            }
            if ($nomsg !== true) {
                if ($schema) {
                    Translator::getInstance()->setSchema($schema);
                }
                Output::getInstance()->output($nomsg);
                if ($schema) {
                    Translator::getInstance()->setSchema();
                }
            }
        }
        $companions = $newcompanions;
    }

/**
 * Enables suspended companions.
 *
 * @param string $susp The type of suspension
 * @param mixed $nomsg The message to be displayed upon unsuspending. If false, no message will be displayed.
 */
    public static function unsuspendCompanions($susp, $nomsg = false)
    {
        global $companions;
        $notify = false;
        $newcompanions = array();
        if (is_array($companions)) {
            foreach ($companions as $name => $companion) {
                if (isset($companion['suspended']) && $companion['suspended'] == true) {
                    $notify = true;
                    $companion['suspended'] = false;
                }
                $newcompanions[$name] = $companion;
            }
        }

        if ($notify) {
            $schema = false;
            if ($nomsg === false) {
                $schema = "battle";
                $nomsg = "`&Your companions return to stand by your side!`n";
            }
            if ($nomsg !== true) {
                if ($schema) {
                    Translator::getInstance()->setSchema($schema);
                }
                Output::getInstance()->output($nomsg);
                if ($schema) {
                    Translator::getInstance()->setSchema();
                }
            }
        }
        $companions = $newcompanions;
    }

/**
 * Automatically chooses the first still living enemy as target for attacks.
 *
 * @param array $localenemies The stack of enemies to find a valid one from.
 * @return array $localenemies The stack with changed targetting.
 */
    public static function autoSetTarget($localenemies)
    {
        $targetted = 0;
        if (is_array($localenemies)) {
            foreach ($localenemies as $index => $badguy) {
                $localenemies[$index] += array("dead" => false, "istarget" => false); // This line will add these two indices if they haven't been set.
                if (count($localenemies) == 1) {
                    $localenemies[$index]['istarget'] = true;
                }
                if ($localenemies[$index]['istarget'] == true && $localenemies[$index]['dead'] == false) {
                    $targetted++;
                }
            }
        }
        if (!$targetted && is_array($localenemies)) {
            foreach ($localenemies as $index => $badguy) {
                if ($localenemies[$index]['dead'] == false && (!isset($badguy['cannotbetarget']) || $badguy['cannotbetarget'] === false)) {
                    $localenemies[$index]['istarget'] = true;
                    $targetted = true;
                    break;
                } else {
                    continue;
                }
            }
        }
        return $localenemies;
    }

/**
 * Based upon the type of the companion different actions are performed and the companion is marked as "used" after that.
 *
 * @param array $companion The companion itself
 * @param string $activate The stage of activation. Can be one of these: "fight", "defend", "heal" or "magic".
 * @return array The changed companion
 */
    public static function reportCompanionMove(&$badguy, $companion, $activate = "fight")
    {
        global $session,$creatureattack,$creatureatkmod,$adjustment;
        global $creaturedefmod,$defmod,$atkmod,$atk,$def,$count,$defended,$needtosstopfighting;
        $output = Output::getInstance();

        if (isset($companion['suspended']) && $companion['suspended'] == true) {
            return $companion;
        }
        if ($activate == "fight" && isset($companion['abilities']['fight']) && $companion['abilities']['fight'] == true && $companion['used'] == false) {
            $roll = self::rollCompanionDamage($badguy, $companion);
            $damage_done = $roll['creaturedmg'];
            $damage_received = $roll['selfdmg'];
            if ($damage_done == 0) {
                 $output->output("`^%s`4 tries to hit %s but `\$MISSES!`n", $companion['name'], $badguy['creaturename']);
            } elseif ($damage_done < 0) {
                $output->output("`^%s`4 tries to hit %s but %s `\$RIPOSTES`4 for `^%s`4 points of damage!`n", $companion['name'], $badguy['creaturename'], $badguy['creaturename'], abs($damage_done));
                $companion['hitpoints'] += $damage_done;
            } else {
                $output->output("`^%s`4 hits %s for `^%s`4 points of damage!`n", $companion['name'], $badguy['creaturename'], $damage_done);
                $badguy['creaturehealth'] -= $damage_done;
            }

            if ($badguy['creaturehealth'] >= 0) {
                if ($damage_received == 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but `^MISSES!`n", $badguy['creaturename'], $companion['name']);
                } elseif ($damage_received < 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but %s `^RIPOSTES`4 for `^%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $companion['name'], abs($damage_received));
                    $badguy['creaturehealth'] += $damage_received;
                } else {
                    $output->output("`^%s`4 hits `\$%s`4 for `\$%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $damage_received);
                    $companion['hitpoints'] -= $damage_received;
                }
            }
            $companion['used'] = true;
        } elseif ($activate == "heal" && isset($companion['abilities']['heal']) && $companion['abilities']['heal'] == true && $companion['used'] == false) {
    // This one will be tricky! We are looking for the first target which can be healed. This can be the player himself
    // or any other companion or our fellow companion himself.
    // But if our little friend is the second companion, all other companions will have been copied to the newenemies
    // array already  ...
            if ($session['user']['hitpoints'] < $session['user']['maxhitpoints']) {
                    $hptoheal = min($companion['abilities']['heal'], $session['user']['maxhitpoints'] - $session['user']['hitpoints']);
                    $session['user']['hitpoints'] += $hptoheal;
                    $companion['used'] = true;
                if (isset($companion['healmsg']) && $companion['healmsg'] != "") {
                    $msg = $companion['healmsg'];
                } else {
                    $msg = "{companion} heals your wounds. You regenerate {damage} hitpoint(s).";
                }
                    $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}","{damage}"), array($companion['name'],$hptoheal));
                    Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
                    $output->output($msg);
                    Translator::getInstance()->setSchema();
            } else {
                    // Okay. We really have to do this :(
                    global $newcompanions;
                    $mynewcompanions = $newcompanions;
                if (!is_array($mynewcompanions)) {
                    $mynewcompanions = array();
                }
                    $healed = false;
                foreach ($mynewcompanions as $myname => $mycompanion) {
                    if (!isset($mycompanion['hitpoints']) || !isset($mycompanion['maxhitpoints']) || $mycompanion['hitpoints'] >= $mycompanion['maxhitpoints'] || $healed || (isset($companion['cannotbehealed']) && $companion['cannotbehealed'] == true)) {
                        continue;
                    } else {
                        $hptoheal = min($companion['abilities']['heal'], $mycompanion['maxhitpoints'] - $mycompanion['hitpoints']);
                        $mycompanion['hitpoints'] += $hptoheal;
                        $companion['used'] = true;
                        $msg = isset($companion['healcompanionmsg']) ? $companion['healcompanionmsg'] : "";
                        if ($msg == "") {
                            $msg = "{companion} heals {target}'s wounds. {target} regenerates {damage} hitpoints.";
                        }
                        $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}","{damage}","{target}"), array($companion['name'],$hptoheal,$mycompanion['name']));
                        Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
                        $output->output($msg);
                        Translator::getInstance()->setSchema();
                        $healed = true;
                        $newcompanions[$myname] = $mycompanion;
                    }
                }
                if (!$healed) {
                    global $companions,$name;
                    $mycompanions = $companions;
                    $foundmyself = false;
                    foreach ($mycompanions as $myname => $mycompanion) {
                        if (!$foundmyself || (isset($companion['cannotbehealed']) && $companion['cannotbehealed'] == true)) {
                            if ($myname == $name) {
                                $foundmyself = true;
                            }
                            continue;
                        } else {
                            //There's someone hiding behind us...
                            foreach ($mycompanions as $myname => $mycompanion) {
                                if ($mycompanion['hitpoints'] >= $mycompanion['maxhitpoints'] || $healed) {
                                    continue;
                                } else {
                                    $hptoheal = min($companion['abilities']['heal'], $mycompanion['maxhitpoints'] - $mycompanion['hitpoints']);
                                    $mycompanion['hitpoints'] += $hptoheal;
                                    $companion['used'] = true;
                                    $msg = (isset($companion['healcompanionmsg']) ? $companion['healcompanionmsg'] : "");
                                    if ($msg == "") {
                                        $msg = "{companion} heals {target}'s wounds. {target} regenerates {damage} hitpoints.";
                                    }
                                    $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}","{damage}","{target}"), array($companion['name'],$hptoheal,$mycompanion['name']));
                                    Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
                                    $output->output($msg);
                                    Translator::getInstance()->setSchema();
                                    $healed = true;
                                    $companions[$myname] = $mycompanion;
                                // These are some totally senseless comments.
                                }
                            }
                        }
                    }
                }
            }
                unset($mynewcompanions);
                unset($mycompanions);
                $roll = self::rollCompanionDamage($badguy, $companion);
                $damage_done = $roll['creaturedmg'];
                $damage_received = $roll['selfdmg'];
            if ($badguy['creaturehealth'] >= 0) {
                if ($damage_received == 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but `^MISSES!`n", $badguy['creaturename'], $companion['name']);
                } elseif ($damage_received < 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but %s `^RIPOSTES`4 for `^%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $companion['name'], abs($damage_received));
                    $badguy['creaturehealth'] += $damage_received;
                } else {
                    $output->output("`^%s`4 hits `\$%s`4 for `\$%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $damage_received);
                    $companion['hitpoints'] -= $damage_received;
                }
            }
                $companion['used'] = true;
        } elseif ($activate == "defend" && isset($companion['abilities']['defend']) && $companion['abilities']['defend'] == true && $defended == false && $companion['used'] == false) {
            $defended = 1;
            $roll = self::rollCompanionDamage($badguy, $companion);
            $damage_done = $roll['creaturedmg'];
            $damage_received = $roll['selfdmg'];
            if ($damage_done == 0) {
                       $output->output("`^%s`4 tries to hit %s but `^MISSES!`n", $companion['name'], $badguy['creaturename']);
            } elseif ($damage_done < 0) {
                       $output->output("`^%s`4 tries to hit %s but %s `^RIPOSTES`4 for `^%s`4 points of damage!`n", $companion['name'], $badguy['creaturename'], $badguy['creaturename'], abs($damage_done));
                       $companion['hitpoints'] += $damage_done;
            } else {
                $output->output("`^%s`4 hits %s for `\$%s`4 points of damage!`n", $companion['name'], $badguy['creaturename'], $damage_done);
                $badguy['creaturehealth'] -= $damage_done;
            }

            if ($badguy['creaturehealth'] >= 0) {
                if ($damage_received == 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but `^MISSES!`n", $badguy['creaturename'], $companion['name']);
                } elseif ($damage_received < 0) {
                    $output->output("`^%s`4 tries to hit `\$%s`4 but %s `^RIPOSTES`4 for `^%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $companion['name'], abs($damage_received));
                    $badguy['creaturehealth'] += $damage_received;
                } else {
                    $output->output("`^%s`4 hits `\$%s`4 for `\$%s`4 points of damage!`n", $badguy['creaturename'], $companion['name'], $damage_received);
                    $companion['hitpoints'] -= $damage_received;
                }
            }
                   $companion['used'] = true;
        } elseif ($activate == "magic" && isset($companion['abilities']['magic']) && $companion['abilities']['magic'] == true && $companion['used'] == false) {
            $roll = self::rollCompanionDamage($badguy, $companion);
            $damage_done = abs($roll['creaturedmg']);
            if ($damage_done == 0) {
                       $msg = $companion['magicfailmsg'];
                if ($msg == "") {
                    $msg = "{companion} shoots a magical arrow at {badguy} but misses.";
                }
                       $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}"), array($companion['name']));
                       Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
                       $output->output($msg);
                       Translator::getInstance()->setSchema();
            } else {
                if (isset($companion['magicmsg'])) {
                    $msg = $companion['magicmsg'];
                } else {
                    $msg = "{companion} shoots a magical arrow at {badguy} and deals {damage} damage.";
                }
                      $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}","{damage}"), array($companion['name'],$damage_done));
                      Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
                      $output->output($msg);
                      Translator::getInstance()->setSchema();
                      $badguy['creaturehealth'] -= $damage_done;
            }
                      $companion['hitpoints'] -= $companion['abilities']['magic'];
                      $companion['used'] = true;
        }
        if ($badguy['creaturehealth'] <= 0) {
            $badguy['dead'] = true;
            $badguy['istarget'] = false;
            $count = 1;
            $needtosstopfighting = true;
        }
        if ($companion['hitpoints'] <= 0) {
            if (isset($companion['dyingtext']) && $companion['dyingtext'] > "") {
                $msg = $companion['dyingtext'];
            } else {
                $msg = "`5Your companion catches his last breath before it dies.";
            }
            $msg = Substitute::applyArray("`)" . $msg . "`0`n", array("{companion}"), array($companion['name']));
            Translator::getInstance()->setSchema(isset($companion['schema']) ? $companion['schema'] : "battle");
            $output->output($msg);
            $output->outputNotl("`0`n");
            Translator::getInstance()->setSchema();
            if (isset($companion['cannotdie']) && $companion['cannotdie'] == true) {
                $companion['hitpoints'] = 0;
            } else {
                return false;
            }
        }

        return $companion;
    }

/**
 * Based upon the companion's stats damage values are calculated.
 *
 * @param array $companion
 * @return array
 */

    public static function rollCompanionDamage(&$badguy, $companion)
    {
        global $creatureattack, $creatureatkmod, $adjustment, $options;
        global $creaturedefmod, $compdefmod, $compatkmod, $buffset, $atk, $def;

        $creaturedmg = 0;
        $selfdmg     = 0;

        if ($badguy['creaturehealth'] > 0 && $companion['hitpoints'] > 0) {
            if ($options['type'] == 'pvp') {
                $adjustedcreaturedefense = $badguy['creaturedefense'];
            } else {
                $adjustedcreaturedefense =
                ($creaturedefmod * $badguy['creaturedefense'] /
                 ($adjustment * $adjustment));
            }

            $creatureattack = $badguy['creatureattack'] * $creatureatkmod;
            $adjustedselfdefense = ($companion['defense'] * $adjustment * $compdefmod);

            /*
            debug("Base creature defense: " . $badguy['creaturedefense']);
            debug("Creature defense mod: $creaturedefmod");
            debug("Adjustment: $adjustment");
            debug("Adjusted creature defense: $adjustedcreaturedefense");
            debug("Adjusted creature attack: $creatureattack");
            debug("Adjusted self defense: $adjustedselfdefense");
            */
            $bad_check = 1;
            while ($creaturedmg == 0 && $selfdmg == 0) {
                $atk = $companion['attack'] * $compatkmod;
                if (random_int(1, 20) == 1 && $options['type'] != "pvp") {
                    $atk *= 3;
                }
                /*
                debug("Attack score: $atk");
                */

                $patkroll = BellRand::generate(0, $atk);
                /*
                debug("Player Attack roll: $patkroll");
                */

                // Set up for crit detection
                $atk = $patkroll;
                $catkroll = BellRand::generate(0, $adjustedcreaturedefense);
                /*
                debug("Creature defense roll: $catkroll");
                */

                $creaturedmg = 0 - (int)($catkroll - $patkroll);
                if ($creaturedmg < 0) {
                    $creaturedmg = (int)($creaturedmg / 2);
                    $creaturedmg = round($buffset['badguydmgmod'] * $creaturedmg, 0);
                }
                if ($creaturedmg > 0) {
                    $creaturedmg = round($buffset['compdmgmod'] * $creaturedmg, 0);
                }
                $pdefroll = BellRand::generate(0, $adjustedselfdefense);
                $catkroll = BellRand::generate(0, $creatureattack);
                /*
                   debug("Creature attack roll: $catkroll");
                   debug("Player defense roll: $pdefroll");
                 */
                $selfdmg = 0 - (int)($pdefroll - $catkroll);
                if ($selfdmg < 0) {
                    $selfdmg = (int)($selfdmg / 2);
                    $selfdmg = round($selfdmg * $buffset['compdmgmod'], 0);
                }
                if ($selfdmg > 0) {
                    $selfdmg = round($selfdmg * $buffset['badguydmgmod'], 0);
                }
                $bad_check++;
                if ($bad_check > 50) {
                            //we're getting nowhere
                            $selfdmg = 0;
                            $creaturedmg = 1;
                }
            }
        } else {
            $creaturedmg = 0;
            $selfdmg     = 0;
        }

        // Handle god mode's invulnerability
        if ($buffset['invulnerable']) {
            $creaturedmg = abs($creaturedmg);
            $selfdmg     = -abs($selfdmg);
        }

        return ['creaturedmg' => $creaturedmg, 'selfdmg' => $selfdmg];
    }

/**
 * Adds a new creature to the badguy array.
 *
 * @param mixed $creature A standard badguy array. If numeric, the corresponding badguy will be loaded from the database.
 */
    public static function battleSpawn($creature)
    {
        global $enemies, $newenemies, $badguy,$nextindex;
        if (!is_array($newenemies)) {
            $newenemies = array();
        }
        if (!isset($nextindex)) {
            if (!isset($enemies) || !is_array($enemies)) {
                $enemies = array();
            }
            $nextindex = count($enemies);
        } else {
            $nextindex++;
        }
        if (is_numeric($creature)) {
            $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE creatureid = $creature LIMIT 1";
            $result = Database::query($sql);
            if ($row = Database::fetchAssoc($result)) {
                $newenemies[$nextindex] = $row;
                Output::getInstance()->output("`^%s`2 summons `^%s`2 for help!`n", $badguy['creaturename'], $row['creaturename']);
            }
        } elseif (is_array($creature)) {
            $newenemies[$nextindex] = $creature;
        }
        ksort($newenemies);
    }

/**
 * Allows creatures to heal themselves or another badguy.
 *
 * @param int $amount Amount of helath to be restored
 * @param mixed $target If false badguy will heal itself otherwise the enemy with this index.
 */
    public static function battleHeal($amount, $target = false)
    {
        global $newenemies, $enemies, $badguy;
        $output = Output::getInstance();
        if ($amount > 0) {
            if ($target === false) {
                $badguy['creaturehealth'] += $amount;
                $output->output("`^%s`2 heals itself for `^%s`2 hitpoints.", $badguy['creaturename'], $amount);
            } else {
                if (isset($newenemies[$target])) {
                    // Target had its turn already...
                    if ($newenemies[$target]['dead'] == false) {
                        $newenemies[$target]['creaturehealth'] += $amount;
                        $output->output("`^%s`2 heal `^%s`2 for `^%s`2 hitpoints.", $badguy['creaturename'], $newenemies[$target]['creaturename'], $amount);
                    }
                } else {
                    if ($enemies[$target]['dead'] == false) {
                        $enemies[$target]['creaturehealth'] += $amount;
                        $output->output("`^%s`2 heal `^%s`2 for `^%s`2 hitpoints.", $badguy['creaturename'], $enemies[$target]['creaturename'], $amount);
                    }
                }
            }
        }
    }

/**
 * Executes the given script or loads the script and then executes it.
 *
 * @param string $script the script to be executed.
 */
    public static function executeAiScript($script)
    {
        global $unsetme, $badguy;

        if (!empty($script)) {
            try {
                eval($script);
            } catch (\Throwable $e) {
                \Lotgd\GameLog::log(
                    "AI script error for {$badguy['creaturename']} ({$badguy['creatureid']}): "
                    . $e->getMessage() . ' Script: ' . $script,
                    'battle'
                );

                if (isset($badguy)) {
                    $badguy['creatureaiscript'] = '';
                }
            }
        }
    }
}
