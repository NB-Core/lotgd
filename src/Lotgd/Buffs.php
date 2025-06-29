<?php
namespace Lotgd;

class Buffs
{
    private static array $buffReplacements = [];
    private static array $debuggedBuffs = [];

    public static function calculateBuffFields(): void
    {
        global $session, $badguy;

        if (!isset($session['bufflist']) || !$session['bufflist']) {
            return;
        }

        reset($session['bufflist']);
        foreach ($session['bufflist'] as $buffname => $buff) {
            if (!isset($buff['tempstats_calculated'])) {
                foreach ($buff as $property => $value) {
                    if (substr($property, 0, 9) == 'tempstat-') {
                        apply_temp_stat(substr($property, 9), $value);
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
                    $origstring = $value;
                    $value = preg_replace('/<([A-Za-z0-9]+)\\|([A-Za-z0-9]+)>/', "get_module_pref('\\2','\\1')", $value);
                    $value = preg_replace('/<([A-Za-z0-9]+)>/', "\$session['user']['\\1']", $value);

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
                                    debug("Buffs[$buffname][$property] evaluates successfully to $val");
                                } else {
                                    debug("Buffs[$buffname][$property] has an evaluation error<br>" . htmlentities($origstring, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . ' becomes <br>' . htmlentities($value, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '<br>' . $errors);
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

                    if (!isset($output)) {
                        $output = '';
                    }
                    if ($output == '') {
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
                        apply_temp_stat(substr($property, 9), -$value);
                    }
                }
                unset($session['bufflist'][$buffname]['tempstats_calculated']);
            }
        }
    }


    public static function applyBuff(string $name, array $buff): void
    {
        global $session, $translation_namespace;

        if (!isset($buff['schema']) || $buff['schema'] == '') {
            $buff['schema'] = $translation_namespace;
        }

        if (isset(self::$buffReplacements[$name])) {
            unset(self::$buffReplacements[$name]);
        }
        if (isset($session['bufflist'][$name])) {
            self::restoreBuffFields();
        }
        $buff = modulehook('modify-buff', ['name' => $name, 'buff' => $buff]);
        $session['bufflist'][$name] = $buff['buff'];
        self::calculateBuffFields();
    }

    public static function stripCompanion($name)
    {
        global $session, $companions;
        $remove_result = false;
        if (!is_array($companions)) {
            $companions = @unserialize($session['user']['companions']);
        }
        if (is_array($name)) {
            foreach ($name as $remove_comp_name) {
                if (in_array($remove_comp_name, array_keys($companions))) {
                    unset($companions[$remove_comp_name]);
                    $remove_result = true;
                }
            }
        } else {
            $remove_comp_name = $name;
            if (in_array($remove_comp_name, array_keys($companions))) {
                unset($companions[$remove_comp_name]);
                $remove_result = true;
            }
        }
        $session['user']['companions'] = createstring($companions);
        return $remove_result;
    }

    public static function applyCompanion($name, $companion, $ignorelimit = false)
    {
        global $session, $companions;
        if (!is_array($companions)) {
            $companions = @unserialize($session['user']['companions']);
        }
        $companionsallowed = getsetting('companionsallowed', 1);
        $args = modulehook('companionsallowed', ['maxallowed' => $companionsallowed]);
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
            $session['user']['companions'] = createstring($companions);
            return true;
        }
        debug('Failed to add companion due to restrictions regarding the maximum amount of companions allowed.');
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
}
