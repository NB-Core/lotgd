<?php
declare(strict_types=1);
namespace Lotgd\Forest;

use Lotgd\AddNews;
use Lotgd\Battle;
use Lotgd\DeathMessage;
use Lotgd\PageParts;
use Lotgd\Translator;
use Lotgd\Settings;
use Lotgd\Nav;

/**
 * Helper methods for handling forest fight results and creature buffs.
 */
class Outcomes
{
    /**
     * Apply rewards and penalties for winning a forest battle.
     *
     * @param array       $enemies      List of encountered enemies
     * @param bool|string $denyflawless Custom text to deny flawless bonus
     */
    public static function victory(array $enemies, bool|string $denyflawless = false): void
    {
        global $session, $options, $settings;
        $diddamage = false;
        $creaturelevel = 0;
        $gold = 0;
        $exp = 0;
        $expbonus = 0;
        $count = 0;
        foreach ($enemies as $badguy) {
            $dropMinGold = isset($settings) && $settings instanceof Settings
                ? $settings->getSetting('dropmingold', 0)
                : getsetting('dropmingold', 0);
            if ($dropMinGold) {
                $badguy['creaturegold'] = e_rand(round($badguy['creaturegold'] / 4), round(3 * $badguy['creaturegold'] / 4));
            } else {
                $badguy['creaturegold'] = e_rand(0, $badguy['creaturegold']);
            }
            $gold += $badguy['creaturegold'];
            if (isset($badguy['creaturelose'])) {
                $msg = translate_inline($badguy['creaturelose'], 'battle');
                output_notl("`b`&%s`0`b`n", $msg);
            }
            output("`b`\$You have slain %s!`0`b`n", $badguy['creaturename']);
            $count++;
            if ($badguy['diddamage'] == 1) {
                $diddamage = true;
            }
            $creaturelevel = max($creaturelevel, $badguy['creaturelevel']);
            if (!$denyflawless && isset($badguy['denyflawless']) && $badguy['denyflawless'] > '') {
                $denyflawless = $badguy['denyflawless'];
            }
            $expbonus += round(($badguy['creatureexp'] * (1 + .25 * ($badguy['creaturelevel'] - $session['user']['level']))) - $badguy['creatureexp'], 0);
        }
        $multibonus = $count > 1 ? 1 : 0;
        $expbonus += $session['user']['dragonkills'] * $session['user']['level'] * $multibonus;
        $totalexp = array_sum($options['experience']);
        $exp = round($totalexp / $count);
        $gold = e_rand(round($gold / $count), round($gold), 0);
        $expbonus = round($expbonus / $count, 0);

        if ($gold) {
            output("`#You receive `^%s`# gold!`n", $gold);
            debuglog('received gold for slaying a monster.', false, false, 'forestwin', $badguy['creaturegold']);
        }
        $gemChance = isset($settings) && $settings instanceof Settings
            ? $settings->getSetting('forestgemchance', 25)
            : getsetting('forestgemchance', 25);
        $args = modulehook('alter-gemchance', ['chance' => $gemChance]);
        $gemchances = $args['chance'];
        $maxLevel = isset($settings) && $settings instanceof Settings
            ? $settings->getSetting('maxlevel', 15)
            : getsetting('maxlevel', 15);
        if ($session['user']['level'] < $maxLevel && e_rand(1, $gemchances) == 1) {
            output("`&You find A GEM!`n`#");
            $session['user']['gems']++;
            debuglog('found gem when slaying a monster.', false, false, 'forestwingem', 1);
        }
        $instantExp = isset($settings) && $settings instanceof Settings
            ? $settings->getSetting('instantexp', false)
            : getsetting('instantexp', false);
        if ($instantExp == true) {
            $expgained = array_sum($options['experiencegained']);
            $diff = $expgained - $exp;
            $expbonus += $diff;
            if (floor($exp + $expbonus) < 0) {
                $expbonus = -$exp + 1;
            }
            if ($expbonus > 0) {
                $addExp = isset($settings) && $settings instanceof Settings
                    ? $settings->getSetting('addexp', 5)
                    : getsetting('addexp', 5);
                $expbonus = round($expbonus * pow(1 + ($addExp / 100), $count - 1), 0);
                output("`#***Because of the difficult nature of this fight, you are awarded an additional `^%s`# experience! `n", $expbonus);
            } elseif ($expbonus < 0) {
                output("`#***Because of the simplistic nature of this fight, you are penalized `^%s`# experience! `n", abs($expbonus));
            }
            if (count($enemies) > 1) {
                output("During this fight you received `^%s`# total experience!`n`0", $exp + $expbonus);
            }
            $session['user']['experience'] += $expbonus;
        } else {
            if (floor($exp + $expbonus) < 0) {
                $expbonus = -$exp + 1;
            }
            if ($expbonus > 0) {
                $addExp = isset($settings) && $settings instanceof Settings
                    ? $settings->getSetting('addexp', 5)
                    : getsetting('addexp', 5);
                $expbonus = round($expbonus * pow(1 + ($addExp / 100), $count - 1), 0);
                output("`#***Because of the difficult nature of this fight, you are awarded an additional `^%s`# experience! `n(%s + %s = %s) ", $expbonus, $exp, abs($expbonus), $exp + $expbonus);
            } elseif ($expbonus < 0) {
                output("`#***Because of the simplistic nature of this fight, you are penalized `^%s`# experience! `n(%s - %s = %s) ", abs($expbonus), $exp, abs($expbonus), $exp + $expbonus);
            }
            output("You receive `^%s`# total experience!`n`0", $exp + $expbonus);
            $session['user']['experience'] += ($exp + $expbonus);
        }
        $session['user']['gold'] += $gold;
        if (!$creaturelevel) {
            $creaturelevel = $badguy['creaturelevel'];
        } else {
            $creaturelevel += (0.5 * ($count - 1));
        }
        if (!$diddamage) {
            output("`c`b`&~~ Flawless Fight! ~~`0`b`c");
            if ($denyflawless) {
                output("`c`\$%s`0`c", translate_inline($denyflawless));
            } elseif ($session['user']['level'] <= $creaturelevel) {
                output("`c`b`\$You receive an extra turn!`0`b`c`n");
                $session['user']['turns']++;
            } else {
                output("`c`\$A more difficult fight would have yielded an extra turn.`0`c`n");
            }
        }
        if ($session['user']['hitpoints'] <= 0) {
            output("With your dying breath you spy a small stand of mushrooms off to the side.");
            output("You recognize them as some of the ones that the healer had drying in the hut and taking a chance, cram a handful into your mouth.");
            output("Even raw they have some restorative properties.`n");
            $session['user']['hitpoints'] = 1;
        }
    }

    /**
     * Handle the player being defeated in the forest.
     *
     * @param array  $enemies List of enemies that defeated the player
     * @param string $where   Description of where the defeat happened
     */
    public static function defeat(array $enemies, string $where = 'in the forest'): void
    {
        global $session, $settings;
        $percent = isset($settings) && $settings instanceof Settings
            ? $settings->getSetting('forestexploss', 10)
            : getsetting('forestexploss', 10);
        Nav::add('Daily news', 'news.php');
        $names = [];
        $killer = false;
        foreach ($enemies as $badguy) {
            $names[] = $badguy['creaturename'];
            if (isset($badguy['killedplayer']) && $badguy['killedplayer'] == true) {
                $killer = $badguy['creaturename'];
            }
            if (isset($badguy['creaturewin']) && $badguy['creaturewin'] > '') {
                $msg = translate_inline($badguy['creaturewin'], 'battle');
                output_notl("`b`&%s`0`b`n", $msg);
            }
        }
        if (count($names) > 1) {
            $lastname = array_pop($names);
        }
        $enemystring = join(', ', $names);
        $and = translate_inline('and');
        if (isset($lastname) && $lastname > '') {
            $enemystring = "$enemystring $and $lastname";
        }
        $taunt = Battle::selectTauntArray();
        $deathmessage = DeathMessage::selectArray(true, ['{where}'], [$where]);
        if ($deathmessage['taunt'] == 1) {
            AddNews::add("%s`n%s", $deathmessage['deathmessage'], $taunt);
        } else {
            AddNews::add("%s", $deathmessage['deathmessage']);
        }
        $session['user']['alive'] = 0;
        debuglog("lost gold when they were slain $where", false, false, 'forestlose', -$session['user']['gold']);
        $session['user']['gold'] = 0;
        $session['user']['hitpoints'] = 0;
        $session['user']['experience'] = round($session['user']['experience'] * (1 - ($percent / 100)), 0);
        output("`4All gold on hand has been lost!`n");
        output("`4%s %% of experience has been lost!`b`n", $percent);
        output('You may begin fighting again tomorrow.');
        page_footer();
    }

    /**
     * Buff an enemy based on player dragon kills.
     *
     * @param array $badguy Enemy data to adjust
     * @return array Modified enemy data
     */
    public static function buffBadguy(array $badguy): array
    {
        global $session, $settings;
        static $dk = false;
        if ($dk === false) {
            $dk = get_player_dragonkillmod(true);
            $add = ($session['user']['dragonkills'] / 100) * .10;
            $dk = round($dk * (.25 + $add));
        }
        $expflux = round($badguy['creatureexp'] / 10, 0);
        $expflux = e_rand(-$expflux, $expflux);
        $badguy['creatureexp'] += $expflux;
        $atkflux = e_rand(0, $dk);
        $defflux = e_rand(0, ($dk - $atkflux));
        $hpflux = ($dk - ($atkflux + $defflux)) * 5;
        $badguy['creatureattack'] += $atkflux;
        $badguy['creaturedefense'] += $defflux;
        $badguy['creaturehealth'] += $hpflux;
        $disableBonuses = isset($settings) && $settings instanceof Settings
            ? $settings->getSetting('disablebonuses', 1)
            : getsetting('disablebonuses', 1);
        if ($disableBonuses) {
            $base = 30 - min(20, round(sqrt($session['user']['dragonkills']) / 2));
            $base /= 1000;
            $bonus = 1 + $base * ($atkflux + $defflux) + .001 * $hpflux;
            $badguy['creaturegold'] = round($badguy['creaturegold'] * $bonus, 0);
            $badguy['creatureexp'] = round($badguy['creatureexp'] * $bonus, 0);
        }
        $badguy = modulehook('creatureencounter', $badguy);
        debug("DEBUG: $dk modification points total.");
        debug("DEBUG: +$atkflux allocated to attack.");
        debug("DEBUG: +$defflux allocated to defense.");
        debug("DEBUG: +" . ($hpflux / 5) . "*5 to hitpoints.");
        return modulehook('buffbadguy', $badguy);
    }
}
