<?php
namespace Lotgd;
use Lotgd\Backtrace;
use Lotgd\Translator;


/**
 * Collection of module helper functions migrated from legacy modules.php
 */
class Modules
{
    private static array $injectedModules = [1 => [], 0 => []];
    private static array $modulehookQueries = [];
    private static array $modulePreload = [];
    private static array $blockedModules = [];
    private static array $unblockedModules = [];
    private static bool $blockAllModules = false;

    /**
     * Inject a module into runtime if available.
     */
    public static function inject(string $moduleName, bool $force = false, bool $withDb = true): bool
    {
        global $mostrecentmodule;
        $force = $force ? 1 : 0;

        if (isset(self::$injectedModules[$force][$moduleName])) {
            $mostrecentmodule = $moduleName;
            return self::$injectedModules[$force][$moduleName];
        }

        $moduleName = Sanitize::modulenameSanitize($moduleName);
        $modulefilename = "modules/{$moduleName}.php";
        if (file_exists($modulefilename)) {
           Translator::tlschema("module-{$moduleName}");
            if ($withDb) {
                $sql    = 'SELECT active,filemoddate,infokeys,version FROM ' . db_prefix('modules') . " WHERE modulename='$moduleName'";
                $result = db_query_cached($sql, "inject-$moduleName", 3600);
                if (! $force) {
                    if (db_num_rows($result) == 0) {
                       Translator::tlschema();
                        debug(sprintf("`n`3Module `#%s`3 is not installed, but was attempted to be injected.`n", $moduleName));
                        massinvalidate();
                        self::$injectedModules[$force][$moduleName] = false;
                        return false;
                    }
                    $row = db_fetch_assoc($result);
                    if (! $row['active']) {
                       Translator::tlschema();
                        debug(sprintf("`n`3Module `#%s`3 is not active, but was attempted to be injected.`n", $moduleName));
                        self::$injectedModules[$force][$moduleName] = false;
                        return false;
                    }
                }
            }
            require_once $modulefilename;
            $mostrecentmodule = $moduleName;
            $info            = '';
            if (! $force) {
                $fname = $moduleName . '_getmoduleinfo';
                $info  = $fname();
                if (! isset($info['requires'])) {
                    $info['requires'] = [];
                }
                if (! is_array($info['requires'])) {
                    $info['requires'] = [];
                }
                if (! isset($info['download'])) {
                    $info['download'] = '';
                }
                if (! isset($info['description'])) {
                    $info['description'] = '';
                }
                if (! self::checkRequirements($info['requires'])) {
                    self::$injectedModules[$force][$moduleName] = false;
                   Translator::tlschema();
                    output_notl("`n`3Module `#%s`3 does not meet its prerequisites.`n", $moduleName);
                    return false;
                }
            }
            if ($withDb && db_num_rows($result) > 0) {
                if (! isset($row)) {
                    $row = db_fetch_assoc($result);
                }
                $filemoddate = date('Y-m-d H:i:s', filemtime($modulefilename));
                if ($row['filemoddate'] != $filemoddate || $row['infokeys'] == '' || $row['infokeys'][0] != '|' || $row['version'] == '') {
                    $sql = 'LOCK TABLES ' . db_prefix('modules') . ' WRITE';
                    db_query($sql);
                    $sql    = 'SELECT filemoddate FROM ' . db_prefix('modules') . " WHERE modulename='$moduleName'";
                    $result = db_query($sql);
                    $row    = db_fetch_assoc($result);
                    if ($row['filemoddate'] != $filemoddate || ! isset($row['infokeys']) || $row['infokeys'] == '' || $row['infokeys'][0] != '|' || $row['version'] == '') {
                        debug("The module $moduleName was found to have updated, upgrading the module now.");
                        if (! is_array($info)) {
                            $fname = $moduleName . '_getmoduleinfo';
                            $info  = $fname();
                            if (! isset($info['download'])) {
                                $info['download'] = '';
                            }
                            if (! isset($info['version'])) {
                                $info['version'] = '0.0';
                            }
                            if (! isset($info['description'])) {
                                $info['description'] = '';
                            }
                        }
                        $keys = '|' . implode('|', array_keys($info)) . '|';
                        $sql  = 'UPDATE ' . db_prefix('modules') .
                            " SET moduleauthor='" . addslashes($info['author']) .
                            "', category='" . addslashes($info['category']) .
                            "', formalname='" . addslashes($info['name']) .
                            "', description='" . addslashes($info['description']) .
                            "', filemoddate='$filemoddate', infokeys='$keys',version='" . addslashes($info['version']) .
                            "',download='" . addslashes($info['download']) . "' WHERE modulename='$moduleName'";
                        db_query($sql);
                        debug($sql);
                        $sql = 'UNLOCK TABLES';
                        db_query($sql);
                        module_wipehooks();
                        $fname = $moduleName . '_install';
                        $fname();
                        invalidatedatacache("inject-$moduleName");
                    } else {
                        $sql = 'UNLOCK TABLES';
                        db_query($sql);
                    }
                }
            }
           Translator::tlschema();
            self::$injectedModules[$force][$moduleName] = true;
            return true;
        }

        output("`n`\$Module '`^%s`\$' (%s) was not found in the modules directory.`n", $moduleName, $modulefilename);
        output_notl(Backtrace::show(), true);
        self::$injectedModules[$force][$moduleName] = false;
        return false;
    }

    /**
     * Return status bitfield for a module.
     */
    public static function getStatus(string $moduleName, $version = false): int
    {
        

        $moduleName     = modulename_sanitize($moduleName);
        $modulefilename = "modules/$moduleName.php";
        $status         = MODULE_NO_INFO;
        if (file_exists($modulefilename)) {
            $sql    = 'SELECT active,filemoddate,infokeys,version FROM ' . db_prefix('modules') . " WHERE modulename='$moduleName'";
            $result = db_query_cached($sql, "inject-$moduleName", 3600);
            if (db_num_rows($result) > 0) {
                $status = MODULE_INSTALLED;
                $row    = db_fetch_assoc($result);
                if ($row['active']) {
                    $status |= MODULE_ACTIVE;
                    if (array_key_exists($moduleName, self::$injectedModules[0]) && self::$injectedModules[0][$moduleName]) {
                        $status |= MODULE_INJECTED;
                    }
                    if (array_key_exists($moduleName, self::$injectedModules[1]) && self::$injectedModules[1][$moduleName]) {
                        $status |= MODULE_INJECTED;
                    }
                } else {
                    if (array_key_exists($moduleName, self::$injectedModules[1]) && self::$injectedModules[1][$moduleName]) {
                        $status |= MODULE_INJECTED;
                    }
                }
                if ($version === false) {
                    $status |= MODULE_VERSION_OK;
                } else {
                    if (module_compare_versions($row['version'], $version) < 0) {
                        $status |= MODULE_VERSION_TOO_LOW;
                    } else {
                        $status |= MODULE_VERSION_OK;
                    }
                }
            } else {
                $status = MODULE_NOT_INSTALLED;
            }
        } else {
            $status = MODULE_FILE_NOT_PRESENT;
        }
        return $status;
    }

    /**
     * Determine if a module is active.
     */
    public static function isActive(string $moduleName): bool
    {
        return (bool) (self::getStatus($moduleName) & MODULE_ACTIVE);
    }

    /**
     * Determine if a module is installed optionally checking version.
     */
    public static function isInstalled(string $moduleName, $version = false): bool
    {
        return (bool) (self::getStatus($moduleName, $version) & (MODULE_INSTALLED | MODULE_VERSION_OK));
    }

    /**
     * Validate module requirements and optionally inject dependencies.
     */
    public static function checkRequirements(array $reqs, bool $forceinject = false): bool
    {
        global $mostrecentmodule;

        $oldmodule = $mostrecentmodule;
        $result    = true;

        if (! is_array($reqs)) {
            return false;
        }

        foreach ($reqs as $key => $val) {
            $info = explode('|', $val);
            if (! self::isInstalled($key, $info[0])) {
                return false;
            }
            $status = self::getStatus($key);
            if (! (
                $status & MODULE_INJECTED
            ) && $forceinject) {
                $result = $result && self::inject($key);
            }
        }

        $mostrecentmodule = $oldmodule;
        return $result;
    }

    /**
     * Block a module from participating in hooks during the current request.
     */
    public static function block(string $moduleName): void
    {
        self::$blockedModules[$moduleName] = 1;
    }

    /**
     * Allow a previously blocked module to participate in hooks again.
     */
    public static function unblock(string $moduleName): void
    {
        if ($moduleName === true) {
            self::$blockAllModules = false;
            return;
        }
        self::$unblockedModules[$moduleName] = 1;
    }

    /**
     * Check if a module is currently blocked.
     */
    public static function isModuleBlocked(string $moduleName): bool
    {
        return (self::$blockAllModules || (self::$blockedModules[$moduleName] ?? false))
            && !(self::$unblockedModules[$moduleName] ?? false);
    }

    /**
     * Prefetch hook information for a set of hooks.
     */
    public static function massPrepare(array $hookNames): bool
    {
        sort($hookNames);
        $Pmodules          = db_prefix('modules');
        $Pmodule_hooks     = db_prefix('module_hooks');
        $Pmodule_settings  = db_prefix('module_settings');
        $Pmodule_userprefs = db_prefix('module_userprefs');

        global $module_settings, $module_prefs, $session;

        $namesStr = "'" . implode("', '", $hookNames) . "'";
        $sql  = 'SELECT '
            . "$Pmodule_hooks.modulename, $Pmodule_hooks.location, $Pmodule_hooks.`function`, $Pmodule_hooks.whenactive"
            . ' FROM ' . $Pmodule_hooks
            . ' INNER JOIN ' . $Pmodules
            . ' ON ' . $Pmodules . '.modulename = ' . $Pmodule_hooks . '.modulename'
            . " WHERE active = 1 AND location IN ($namesStr)"
            . ' ORDER BY '
            . "$Pmodule_hooks.location, $Pmodule_hooks.priority, $Pmodule_hooks.modulename";
        $result = db_query_cached($sql, 'module_prepare-' . md5(implode('', $hookNames)));

        $moduleNames = [];
        while ($row = db_fetch_assoc($result)) {
            $moduleNames[$row['modulename']] = $row['modulename'];
            if (!isset(self::$modulePreload[$row['location']])) {
                self::$modulePreload[$row['location']] = [];
                self::$modulehookQueries[$row['location']] = [];
            }
            self::$modulehookQueries[$row['location']][] = $row;
            self::$modulePreload[$row['location']][$row['modulename']] = $row['function'];
        }

        $moduleList = "'" . implode("', '", $moduleNames) . "'";

        $sql = 'SELECT modulename,setting,value FROM ' . $Pmodule_settings . ' WHERE modulename IN (' . $moduleList . ')';
        $result = db_query($sql);
        while ($row = db_fetch_assoc($result)) {
            $module_settings[$row['modulename']][$row['setting']] = $row['value'];
        }

        if (!isset($session['user']['acctid'])) {
            return true;
        }

        $sql = 'SELECT modulename,setting,userid,value FROM ' . $Pmodule_userprefs
            . ' WHERE modulename IN (' . $moduleList . ')'
            . ' AND userid = ' . (int) $session['user']['acctid'];
        $result = db_query($sql);
        while ($row = db_fetch_assoc($result)) {
            $module_prefs[$row['userid']][$row['modulename']][$row['setting']] = $row['value'];
        }
        return true;
    }

    /**
     * Execute hooks registered for a location.
     */
    public static function hook(string $hookName, array $args = [], bool $allowInactive = false, $only = false)
    {
        global $navsection, $mostrecentmodule;
        global $output, $session, $currenthook;

        if (defined('IS_INSTALLER')) {
            return $args;
        }

        $lasthook   = $currenthook;
        $currenthook = $hookName;
        static $hookcomment = [];

        if ($args === false) {
            $args = [];
        }
        $active = '';
        if (!$allowInactive) {
            $active = ' ' . db_prefix('modules') . '.active=1 AND';
        }

        if (!is_array($args)) {
            $where = $mostrecentmodule ?: ($_SERVER['SCRIPT_NAME'] ?? '');
            debug("Args parameter to modulehook $hookName from $where is not an array.");
        }

        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT) && !isset($hookcomment[$hookName])) {
            rawoutput("<!--Module Hook: $hookName; allow inactive: " . ($allowInactive ? 'true' : 'false') . '; only this module: ' . ($only !== false ? $only : 'any module'));
            if (!is_array($args)) {
                $arg = $args . ' (NOT AN ARRAY!)';
                rawoutput('  arg: ' . $arg);
            } else {
                foreach ($args as $key => $val) {
                    $arg = $key . ' = ';
                    if (is_array($val)) {
                        $arg .= 'array(' . count($val) . ')';
                    } elseif (is_object($val)) {
                        $arg .= 'object(' . get_class($val) . ')';
                    } else {
                        $arg .= htmlentities(substr((string) $val, 0, 25), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
                    }
                    rawoutput('  arg: ' . $arg);
                }
            }
            rawoutput('  -->');
            $hookcomment[$hookName] = true;
        }

        if (isset(self::$modulehookQueries[$hookName]) && $allowInactive == false) {
            $result = self::$modulehookQueries[$hookName];
        } else {
            $sql = 'SELECT '
                . db_prefix('module_hooks') . '.modulename,'
                . db_prefix('module_hooks') . '.location,'
                . db_prefix('module_hooks') . '.`function`,'
                . db_prefix('module_hooks') . '.whenactive'
                . ' FROM ' . db_prefix('module_hooks')
                . ' INNER JOIN ' . db_prefix('modules')
                . ' ON ' . db_prefix('modules') . '.modulename = ' . db_prefix('module_hooks') . '.modulename'
                . " WHERE $active" . db_prefix('module_hooks') . ".location='$hookName'"
                . ' ORDER BY ' . db_prefix('module_hooks') . '.priority,'
                . db_prefix('module_hooks') . '.modulename';
            $result = db_query_cached($sql, 'hook-' . $hookName);
        }

        if (!is_array($args)) {
            $args = ['bogus_args' => $args];
        }

        $mod = $mostrecentmodule;

        while ($row = db_fetch_assoc($result)) {
            if ($only !== false && $row['modulename'] != $only) {
                continue;
            }

            if (!array_key_exists($row['modulename'], self::$blockedModules)) {
                self::$blockedModules[$row['modulename']] = false;
            }
            if (!array_key_exists($row['modulename'], self::$unblockedModules)) {
                self::$unblockedModules[$row['modulename']] = false;
            }
            if ((self::$blockAllModules || self::$blockedModules[$row['modulename']]) && !self::$unblockedModules[$row['modulename']]) {
                continue;
            }

            if (self::inject($row['modulename'], $allowInactive)) {
                $oldnavsection = $navsection;
                Translator::tlschema('module-' . $row['modulename']);

                if (!array_key_exists('whenactive', $row)) {
                    $row['whenactive'] = '';
                }
                $cond = trim($row['whenactive']);
                if ($cond == '' || module_condition($cond) == true) {
                    $starttime = getmicrotime();
                    if (function_exists($row['function'])) {
                        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)) {
                            rawoutput('<!-- Hook: ' . $hookName . ' on module ' . $row['function'] . ' called... -->');
                        }
                        $res = $row['function']($hookName, $args);
                    } else {
                        trigger_error('Unknown function ' . $row['function'] . ' for hookname ' . $hookName . ' in module ' . $row['modulename'] . '.', E_USER_WARNING);
                    }
                    $endtime = getmicrotime();
                    if (($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
                        debug('Slow Hook (' . round($endtime - $starttime, 2) . 's): ' . $hookName . ' - ' . $row['modulename'] . '`n');
                    }
                    if (getsetting('debug', 0)) {
                        $sql = 'INSERT INTO ' . db_prefix('debug') . " VALUES (0,'hooktime','" . $hookName . "','" . $row['modulename'] . "','" . ($endtime - $starttime) . "');";
                        db_query($sql);
                    }

                    if (!is_array($res)) {
                        trigger_error($row['function'] . ' did not return an array in the module ' . $row['modulename'] . ' for hook ' . $hookName . '.', E_USER_WARNING);
                        $res = $args;
                    }

                    $args       = $res;
                    $navsection = $oldnavsection;
                    Translator::tlschema();
                }
            }
        }

        $mostrecentmodule = $mod;
        $currenthook      = $lasthook;
        return $args;
    }
}

