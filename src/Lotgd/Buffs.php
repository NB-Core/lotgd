<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Substitute;
use Lotgd\Modules\HookHandler;
use Lotgd\CreateString;
use Lotgd\Output;
use Lotgd\PlayerFunctions;
use Lotgd\Settings;
use Lotgd\Translator;

class Buffs
{
    private static array $buffReplacements = [];
    private static array $debuggedBuffs = [];

    public static function calculateBuffFields(): void
    {
        global $session, $badguy;
        $output = Output::getInstance();
        $settings = Settings::getInstance();

        if (!isset($session['bufflist']) || !$session['bufflist']) {
            return;
        }

        reset($session['bufflist']);
        foreach ($session['bufflist'] as $buffname => $buff) {
            if (!isset($buff['tempstats_calculated'])) {
                foreach ($buff as $property => $value) {
                    if (substr($property, 0, 9) == 'tempstat-') {
                        PlayerFunctions::applyTempStat(substr($property, 9), $value);
                    }
                }
                $session['bufflist'][$buffname]['tempstats_calculated'] = true;
            }
        }

        reset($session['bufflist']);
        if (!is_array(self::$buffReplacements)) {
            self::$buffReplacements = [];
        }
        foreach ($session['bufflist'] as $buffname => $buff) {
            if (!isset($buff['fields_calculated'])) {
                foreach ($buff as $property => $value) {
                    // Sanitize if somebody uses int here
                    if (!is_array($value)) {
                        $value = (string) $value;
                    }
                    $origstring = $value;

                    if (!is_array($value)) {
                        $value = preg_replace('/<([A-Za-z0-9]+)\\|([A-Za-z0-9]+)>/', "get_module_pref('\\2','\\1')", $value);
                        $value = preg_replace('/<([A-Za-z0-9]+)>/', "\$session['user']['\\1']", $value);
                    }

                    if (!defined('OLDSU')) {
                        define('OLDSU', $session['user']['superuser']);
                    }
                    if ($value != $origstring) {
                        if (strtolower(substr($value, 0, 6)) == 'debug:') {
                            $errors = '';
                            $origstring = substr($origstring, 6);
                            $value = substr($value, 6);
                            if (!isset(self::$debuggedBuffs[$buffname])) {
                                self::$debuggedBuffs[$buffname] = [];
                            }

                            ob_start();
                            $val = eval("return $value;");
                            $errors = ob_get_contents();
                            ob_end_clean();

                            if (!isset(self::$debuggedBuffs[$buffname][$property])) {
                                if ($errors == '') {
                                    $output->debug("Buffs[$buffname][$property] evaluates successfully to $val");
                                } else {
                                    $output->debug(
                                        "Buffs[$buffname][$property] has an evaluation error<br>" .
                                        htmlentities($origstring, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) .
                                        ' becomes <br>' .
                                        htmlentities($value, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) .
                                        '<br>' .
                                        $errors
                                    );
                                    $val = '';
                                }
                                self::$debuggedBuffs[$buffname][$property] = true;
                            }

                            $origstring = 'debug:' . $origstring;
                            $value = 'debug' . $value;
                        } else {
                            $val = eval("return $value;");
                        }
                    } else {
                        $val = $value;
                    }

                    $session['user']['superuser'] = OLDSU;

                    if (!isset($evalOutput)) {
                        $evalOutput = '';
                    }
                    if ($evalOutput == '') {
                        $overwrite = true;
                        if (is_string($val) && is_string($origstring)) {
                            if ($val == $origstring) {
                                $overwrite = false;
                            }
                        }
                        if (is_array($val) && is_array($origstring)) {
                            if (array_diff($val, $origstring) == []) {
                                $overwrite = false;
                            }
                        } else {
                            if ((string) $val == (string) $origstring) {
                                $overwrite = false;
                            }
                        }
                        if ($overwrite) {
                            self::$buffReplacements[$buffname][$property] = $origstring;
                            $session['bufflist'][$buffname][$property] = $val;
                        }
                    }
                    unset($val);
                }
                $session['bufflist'][$buffname]['fields_calculated'] = true;
            }
        }
    }

    public static function restoreBuffFields(): void
    {
        global $session;
        if (isset(self::$buffReplacements) && is_array(self::$buffReplacements)) {
            foreach (self::$buffReplacements as $buffname => $val) {
                foreach ($val as $property => $value) {
                    if (isset($session['bufflist'][$buffname])) {
                        $session['bufflist'][$buffname][$property] = $value;
                        unset($session['bufflist'][$buffname]['fields_calculated']);
                    }
                }
                unset(self::$buffReplacements[$buffname]);
            }
        }

        if (!isset($session['bufflist']) || !is_array($session['bufflist'])) {
            $session['bufflist'] = [];
        }
        foreach ($session['bufflist'] as $buffname => $buff) {
            if (array_key_exists('tempstats_calculated', $buff) && $buff['tempstats_calculated']) {
                foreach ($buff as $property => $value) {
                    if (substr($property, 0, 9) == 'tempstat-') {
                        PlayerFunctions::applyTempStat(substr($property, 9), -$value);
                    }
                }
                unset($session['bufflist'][$buffname]['tempstats_calculated']);
            }
        }
    }


    public static function applyBuff(string $name, array $buff): void
    {
        global $session;

        if (!isset($buff['schema']) || $buff['schema'] == '') {
            $buff['schema'] = Translator::getNamespace();
        }

        if (isset(self::$buffReplacements[$name])) {
            unset(self::$buffReplacements[$name]);
        }
        if (isset($session['bufflist'][$name])) {
            self::restoreBuffFields();
        }
        $buff = HookHandler::hook('modify-buff', ['name' => $name, 'buff' => $buff]);
        $session['bufflist'][$name] = $buff['buff'];
        self::calculateBuffFields();
    }

    /**
     * Remove a single companion by name.
     *
     * @param string $name Companion identifier
     *
     * @return bool True when removed
     */
    public static function stripCompanion(string $name): bool
    {
        global $session, $companions;
        $remove_result = false;
        if (!is_array($companions)) {
            $companions = @unserialize($session['user']['companions']);
        }
        if (in_array($name, array_keys($companions))) {
            unset($companions[$name]);
            $remove_result = true;
        }
        $session['user']['companions'] = CreateString::run($companions);
        return $remove_result;
    }

    /**
     * Remove multiple companions at once.
     *
     * @param string[] $names List of names
     *
     * @return bool True when at least one was removed
     */
    public static function stripCompanions(array $names): bool
    {
        global $session, $companions;
        $removed = false;
        if (!is_array($companions)) {
            $companions = @unserialize($session['user']['companions']);
        }
        foreach ($names as $remove_comp_name) {
            if (in_array($remove_comp_name, array_keys($companions))) {
                unset($companions[$remove_comp_name]);
                $removed = true;
            }
        }
        $session['user']['companions'] = CreateString::run($companions);
        return $removed;
    }

    public static function applyCompanion($name, $companion, $ignorelimit = false)
    {
        global $session, $companions;
        $output = Output::getInstance();
        if (!is_array($companions)) {
            $companions = @unserialize($session['user']['companions']);
        }
        $companionsallowed = Settings::getInstance()->getSetting('companionsallowed', 1);
        $args = HookHandler::hook('companionsallowed', ['maxallowed' => $companionsallowed]);
        $companionsallowed = $args['maxallowed'];
        $current = 0;
        if (!$ignorelimit) {
            foreach ($companions as $thisname => $thiscompanion) {
                if (isset($thiscompanion['ignorelimit']) && $thiscompanion['ignorelimit'] == true) {
                } else {
                    if ($thisname != $name) {
                        ++$current;
                    }
                }
            }
        }
        if ($current < $companionsallowed || $ignorelimit == true) {
            if (isset($companions[$name])) {
                unset($companions[$name]);
            }
            if (!isset($companion['ignorelimit']) && $ignorelimit == true) {
                $companion['ignorelimit'] = true;
            }
            $companions[$name] = $companion;
            $session['user']['companions'] = CreateString::run($companions);
            return true;
        }
        $output->debug('Failed to add companion due to restrictions regarding the maximum amount of companions allowed.');
        return false;
    }

    public static function stripBuff($name): void
    {
        global $session;
        self::restoreBuffFields();
        if (isset($session['bufflist'][$name])) {
            unset($session['bufflist'][$name]);
        }
        if (isset(self::$buffReplacements[$name])) {
            unset(self::$buffReplacements[$name]);
        }
        self::calculateBuffFields();
    }

    public static function stripAllBuffs(): void
    {
        global $session;
        foreach ($session['bufflist'] as $buffname => $buff) {
            self::stripBuff($buffname);
        }
    }

    public static function hasBuff($name): bool
    {
        global $session;
        return isset($session['bufflist'][$name]);
    }

    public static function activateBuffs($tag)
    {
        global $session, $badguy, $count;
        $output = Output::getInstance();

        Translator::getInstance()->setSchema('buffs');
        reset($session['bufflist']);

        $result = [
            'invulnerable' => 0,
            'dmgmod' => 1,
            'compdmgmod' => 1,
            'badguydmgmod' => 1,
            'atkmod' => 1,
            'compatkmod' => 1,
            'badguyatkmod' => 1,
            'defmod' => 1,
            'compdefmod' => 1,
            'badguydefmod' => 1,
            'lifetap' => [],
            'dmgshield' => [],
        ];

        foreach ($session['bufflist'] as $key => $buff) {
            if (array_key_exists('suspended', $buff) && $buff['suspended']) {
                continue;
            }

            if ($buff['schema']) {
                Translator::getInstance()->setSchema($buff['schema']);
            }

            if (isset($buff['startmsg'])) {
                if (is_array($buff['startmsg'])) {
                    $buff['startmsg'] = str_replace('`%', '`%%', $buff['startmsg']);
                    $msg = Translator::sprintfTranslate($buff['startmsg']);
                    $msg = Substitute::apply("`5" . $msg . "`0`n");
                    $output->outputNotl($msg);
                } else {
                    $msg = Substitute::applyArray("`5" . $buff['startmsg'] . "`0`n");
                    $output->output($msg);
                }

                unset($session['bufflist'][$key]['startmsg']);
            }

            $activate = false;
            if ($tag == 'roundstart') {
                if (isset($buff['regen'])) {
                    $activate = true;
                }
                if (isset($buff['minioncount'])) {
                    $activate = true;
                }
            } elseif ($tag == 'offense') {
                if (isset($buff['invulnerable']) && $buff['invulnerable']) {
                    $activate = true;
                }
                if (isset($buff['atkmod'])) {
                    $activate = true;
                }
                if (isset($buff['dmgmod'])) {
                    $activate = true;
                }
                if (isset($buff['badguydefmod'])) {
                    $activate = true;
                }
                if (isset($buff['lifetap'])) {
                    $activate = true;
                }
                if (isset($buff['damageshield'])) {
                    $activate = true;
                }
            } elseif ($tag == 'defense') {
                if (isset($buff['invulnerable']) && $buff['invulnerable']) {
                    $activate = true;
                }
                if (isset($buff['defmod'])) {
                    $activate = true;
                }
                if (isset($buff['badguyatkmod'])) {
                    $activate = true;
                }
                if (isset($buff['badguydmgmod'])) {
                    $activate = true;
                }
                if (isset($buff['lifetap'])) {
                    $activate = true;
                }
                if (isset($buff['damageshield'])) {
                    $activate = true;
                }
            }

            if ($activate && (!array_key_exists('used', $buff) || !$buff['used'])) {
                $session['bufflist'][$key]['used'] = 1;
                if (isset($buff['roundmsg'])) {
                    if (is_array($buff['roundmsg'])) {
                        $buff['roundmsg'] = str_replace('`%', '`%%', $buff['roundmsg']);
                        $msg = Translator::sprintfTranslate($buff['roundmsg']);
                        $msg = Substitute::apply("`5" . $msg . "`0`n");
                        $output->outputNotl($msg);
                    } else {
                        $msg = Substitute::applyArray("`5" . $buff['roundmsg'] . "`0`n");
                        $output->output($msg);
                    }
                }
            }

            if (isset($buff['invulnerable']) && $buff['invulnerable']) {
                $result['invulnerable'] = 1;
            }
            if (isset($buff['atkmod'])) {
                $result['atkmod'] *= $buff['atkmod'];
                if (isset($buff['aura']) && $buff['aura']) {
                    $result['compatkmod'] *= $buff['atkmod'];
                }
            }
            if (isset($buff['badguyatkmod'])) {
                $result['badguyatkmod'] *= $buff['badguyatkmod'];
            }
            if (isset($buff['defmod'])) {
                $result['defmod'] *= $buff['defmod'];
                if (isset($buff['aura']) && $buff['aura']) {
                    $result['compdefmod'] *= $buff['defmod'];
                }
            }
            if (isset($buff['badguydefmod'])) {
                $result['badguydefmod'] *= $buff['badguydefmod'];
            }
            if (isset($buff['dmgmod'])) {
                $result['dmgmod'] *= $buff['dmgmod'];
                if (isset($buff['aura']) && $buff['aura']) {
                    $result['compdmgmod'] *= $buff['dmgmod'];
                }
            }
            if (isset($buff['badguydmgmod'])) {
                $result['badguydmgmod'] *= $buff['badguydmgmod'];
            }
            if (isset($buff['lifetap'])) {
                $result['lifetap'][] = $buff;
            }
            if (isset($buff['damageshield'])) {
                $result['dmgshield'][] = $buff;
            }
            if (isset($buff['regen']) && $tag == 'roundstart' && $badguy['istarget'] == true) {
                $hptoregen = (int) $buff['regen'];
                $hpdiff = $session['user']['maxhitpoints'] - $session['user']['hitpoints'];
                if ($hpdiff < 0) {
                    $hpdiff = 0;
                }
                if ($hpdiff < $hptoregen) {
                    $hptoregen = $hpdiff;
                }
                $session['user']['hitpoints'] += $hptoregen;
                $hptoregen = abs($hptoregen);
                $msg = '';
                if ($hptoregen == 0) {
                    $msg = (isset($buff['effectnodmgmsg']) ? $buff['effectnodmgmsg'] : Translator::translateInline('No damage, hosé'));
                } else {
                    $msg = (isset($buff['effectgmsg']) ? $buff['effectmsg'] : Translator::translateInline('Tons of damage, hosé'));
                }

                if (is_array($msg)) {
                    $msg = Translator::sprintfTranslate($msg);
                    $msg = Substitute::apply('`)' . $msg . '`0`n', ['{damage}'], [$hptoregen]);
                    $output->outputNotl($msg);
                } elseif ($msg != '') {
                    $msg = Substitute::applyArray('`)' . $msg . '`0`n', ['{damage}'], [$hptoregen]);
                    $output->output($msg);
                }
                if (isset($buff['aura']) && $buff['aura'] == true) {
                    global $companions;
                    $auraeffect = (int) round($buff['regen'] / 3);
                    if (is_array($companions) && count($companions) > 0 && $auraeffect != 0) {
                        foreach ($companions as $name => $companion) {
                            $unset = false;
                            if (
                                $companion['hitpoints'] < $companion['maxhitpoints'] &&
                                ($companion['hitpoints'] > 0 || ($companion['cannotdie'] == true && $auraeffect > 0))
                            ) {
                                $hptoregen = min($auraeffect, $companion['maxhitpoints'] - $companion['hitpoints']);
                                $companions[$name]['hitpoints'] += $hptoregen;
                                $msg = Substitute::applyArray('`)' . $buff['auramsg'] . '`0`n', ['{damage}', '{companion}'], [$hptoregen, $companion['name']]);
                                $output->output($msg);
                                if ($hptoregen < 0 && $companion['hitpoints'] <= 0) {
                                    if (isset($companion['dyingtext'])) {
                                        Translator::getInstance()->setSchema('battle');
                                        $output->output($companion['dyingtext']);
                                        Translator::getInstance()->setSchema();
                                    }
                                    if (isset($companion['cannotdie']) && $companion['cannotdie'] == true) {
                                        $companion['hitpoints'] = 0;
                                    } else {
                                        $unset = true;
                                    }
                                }
                            }
                            if (!$unset) {
                                $newcompanions[$name] = $companion;
                            }
                        }
                    }
                }
            }
            if (
                isset($buff['minioncount']) &&
                $tag == 'roundstart' &&
                ((isset($buff['areadamage']) && $buff['areadamage'] == true) || $badguy['istarget'] == true) &&
                $badguy['dead'] == false
            ) {
                $who = -1;
                $min = 0;
                $max = 0;
                if (isset($buff['maxbadguydamage']) && $buff['maxbadguydamage'] != 0) {
                    $max = $buff['maxbadguydamage'];
                    $min = $buff['minbadguydamage'] ?? 0;
                    $who = 0;
                } elseif (isset($buff['maxgoodguydamage']) && $buff['maxgoodguydamage'] != 0) {
                    $max = $buff['maxgoodguydamage'];
                    $min = $buff['mingoodguydamage'] ?? 0;
                    $who = 1;
                }
                $min = (int) $min;
                $max = (int) $max;
                if ($min > $max) {
                    error_log(sprintf('Buff "%s" has min damage (%d) greater than max damage (%d); swapping values.', $key, $min, $max));
                    [$min, $max] = [$max, $min];
                }
                $minioncounter = 1;
                while ($minioncounter <= ((int)$buff['minioncount']) && $who >= 0) {
                    $damage = random_int($min, $max);
                    if ($who == 0) {
                        $badguy['creaturehealth'] -= $damage;
                        if ($badguy['creaturehealth'] <= 0) {
                            $badguy['istarget'] = false;
                            $badguy['dead'] = true;
                            $count = 1;
                        }
                    } elseif ($who == 1) {
                        $session['user']['hitpoints'] -= $damage;
                    }
                    $msg = '';
                    if ($damage < 0) {
                        if (isset($buff['effectfailmsg'])) {
                            $msg = $buff['effectfailmsg'];
                        }
                    } elseif ($damage == 0) {
                        if (isset($buff['effectnodmgmsg'])) {
                            $msg = $buff['effectnodmgmsg'];
                        }
                    } elseif ($damage > 0) {
                        if (isset($buff['effectmsg'])) {
                            $msg = $buff['effectmsg'];
                        }
                    }
                    if (is_array($msg)) {
                        $msg = Translator::sprintfTranslate($msg);
                        $msg = Substitute::apply('`)' . $msg . '`0`n', ['{damage}'], [abs($damage)]);
                        $output->outputNotl($msg);
                    } elseif ($msg > '') {
                        $msg = Substitute::applyArray('`)' . $msg . '`0`n', ['{damage}'], [abs($damage)]);
                        $output->output($msg);
                    }
                    if ($badguy['dead'] == true) {
                        break;
                    }
                    $minioncounter++;
                }
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema();
            }
        }
        Translator::getInstance()->setSchema();

        return $result;
    }

    public static function processLifetaps($ltaps, $damage)
    {
        global $session, $badguy;
        $output = Output::getInstance();
        Translator::getInstance()->setSchema('buffs');
        foreach ($ltaps as $buff) {
            if (isset($buff['suspended']) && $buff['suspended']) {
                continue;
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema($buff['schema']);
            }
            $healhp = $session['user']['maxhitpoints'] - $session['user']['hitpoints'];
            if ($healhp < 0) {
                $healhp = 0;
            }
            $msg = '';
            if ($healhp == 0) {
                $msg = (isset($buff['effectnodmgmsg']) ? $buff['effectnodmgmsg'] : '');
            } else {
                if ($healhp > $damage * $buff['lifetap']) {
                    $healhp = round($damage * $buff['lifetap'], 0);
                }
                if ($healhp < 0) {
                    $healhp = 0;
                }
                if ($damage > 0) {
                    $msg = $buff['effectmsg'];
                } elseif ($damage == 0) {
                    $msg = $buff['effectfailmsg'];
                } elseif ($damage < 0) {
                    $msg = $buff['effectfailmsg'];
                }
            }
            $session['user']['hitpoints'] += $healhp;
            if (is_array($msg)) {
                $msg = Translator::sprintfTranslate($msg);
                $msg = Substitute::apply('`)' . $msg . '`0`n', ['{damage}'], [$healhp]);
                $output->outputNotl($msg);
            } elseif ($msg > '') {
                $msg = Substitute::applyArray('`)' . $msg . '`0`n', ['{damage}'], [$healhp]);
                $output->output($msg);
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema();
            }
        }
        Translator::getInstance()->setSchema();
    }

    public static function processDmgshield($dshield, $damage)
    {
        global $session, $badguy;
        $output = Output::getInstance();
        Translator::getInstance()->setSchema('buffs');
        foreach ($dshield as $buff) {
            if (isset($buff['suspended']) && $buff['suspended']) {
                continue;
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema($buff['schema']);
            }
            $realdamage = round($damage * $buff['damageshield'], 0);
            if ($realdamage < 0) {
                $realdamage = 0;
            }
            $msg = '';
            if ($realdamage > 0) {
                if (isset($buff['effectmsg'])) {
                    $msg = $buff['effectmsg'];
                }
            } elseif ($realdamage == 0) {
                if (isset($buff['effectnodmgmsg'])) {
                    $msg = $buff['effectnodmgmsg'];
                }
            } elseif ($realdamage < 0) {
                if (isset($buff['effectfailmsg'])) {
                    $msg = $buff['effectfailmsg'];
                }
            }
            $badguy['creaturehealth'] -= $realdamage;
            if ($badguy['creaturehealth'] <= 0) {
                $badguy['istarget'] = false;
                $badguy['dead'] = true;
                $count = 1;
            }
            if (is_array($msg)) {
                $msg = Translator::sprintfTranslate($msg);
                $msg = Substitute::apply('`)' . $msg . '`0`n', ['{damage}'], [$realdamage]);
                $output->outputNotl($msg);
            } elseif ($msg > '') {
                $msg = Substitute::applyArray('`)' . $msg . '`0`n', ['{damage}'], [$realdamage]);
                $output->output($msg);
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema();
            }
        }
        Translator::getInstance()->setSchema();
    }

    public static function expireBuffs()
    {
        global $session, $badguy;
        $output = Output::getInstance();
        Translator::getInstance()->setSchema('buffs');
        foreach ($session['bufflist'] as $key => $buff) {
            if (array_key_exists('suspended', $buff) && $buff['suspended']) {
                continue;
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema($buff['schema']);
            }
            if (array_key_exists('used', $buff) && $buff['used']) {
                $session['bufflist'][$key]['used'] = 0;
                if ($session['bufflist'][$key]['rounds'] > 0) {
                    $session['bufflist'][$key]['rounds']--;
                }
                if ((int) $session['bufflist'][$key]['rounds'] == 0) {
                    if (isset($buff['wearoff']) && $buff['wearoff']) {
                        if (is_array($buff['wearoff'])) {
                            $buff['wearoff'] = str_replace('`%', '`%%', $buff['wearoff']);
                            $msg = Translator::sprintfTranslate($buff['wearoff']);
                            $msg = Substitute::apply('`5' . $msg . '`0`n');
                            $output->outputNotl($msg);
                        } else {
                            $msg = Substitute::applyArray('`5' . $buff['wearoff'] . '`0`n');
                            $output->output($msg);
                        }
                    }
                    self::stripBuff($key);
                }
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema();
            }
        }
        Translator::getInstance()->setSchema();
    }

    public static function expireBuffsAfterbattle()
    {
        global $session, $badguy;
        $output = Output::getInstance();
        Translator::getInstance()->setSchema('buffs');
        reset($session['bufflist']);
        foreach ($session['bufflist'] as $key => $buff) {
            if (array_key_exists('suspended', $buff) && $buff['suspended']) {
                continue;
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema($buff['schema']);
            }
            if (array_key_exists('used', $buff) && $buff['used']) {
                if (array_key_exists('expireafterfight', $buff) && (int) $buff['expireafterfight'] == 1) {
                    if (isset($buff['wearoff']) && $buff['wearoff']) {
                        if (is_array($buff['wearoff'])) {
                            $buff['wearoff'] = str_replace('`%', '`%%', $buff['wearoff']);
                            $msg = Translator::sprintfTranslate($buff['wearoff']);
                            $msg = Substitute::apply('`5' . $msg . '`0`n');
                            $output->outputNotl($msg);
                        } else {
                            $msg = Substitute::applyArray('`5' . $buff['wearoff'] . '`0`n');
                            $output->output($msg);
                        }
                    }
                    self::stripBuff($key);
                }
            }
            if ($buff['schema']) {
                Translator::getInstance()->setSchema();
            }
        }
        Translator::getInstance()->setSchema();
    }
}
