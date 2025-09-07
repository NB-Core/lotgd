<?php

declare(strict_types=1);

/**
 * Collection of module helper functions migrated from legacy modules.php
 */

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\MySQL\Database;
use Lotgd\Backtrace;
use Lotgd\Forms;
use Lotgd\Sanitize;
use Lotgd\Modules\Installer;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Modules\ModuleManager;
use Lotgd\Translator;
use Lotgd\DataCache;
use Lotgd\Output;
use Lotgd\Http;
use Lotgd\Nav as Navigation;
use Lotgd\Random;

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
        $force = $force ? 1 : 0;
        $output = Output::getInstance();
        $result = null;

        if (isset(self::$injectedModules[$force][$moduleName])) {
            ModuleManager::setMostRecentModule($moduleName);
            return self::$injectedModules[$force][$moduleName];
        }

        $moduleName = Sanitize::modulenameSanitize($moduleName);
        $modulefilename = "modules/{$moduleName}.php";
        if (file_exists($modulefilename)) {
            Translator::getInstance()->setSchema("module-{$moduleName}");
            if ($withDb) {
                $sql    = 'SELECT active,filemoddate,infokeys,version FROM ' . Database::prefix('modules') . " WHERE modulename='$moduleName'";
                $result = Database::queryCached($sql, "inject-$moduleName", 3600);
                if (! $force) {
                    if (Database::numRows($result) == 0) {
                        Translator::getInstance()->setSchema();
                        $output->debug(sprintf("`n`3Module `#%s`3 is not installed, but was attempted to be injected.`n", $moduleName));
                        DataCache::getInstance()->massinvalidate();
                        self::$injectedModules[$force][$moduleName] = false;
                        return false;
                    }
                    $row = Database::fetchAssoc($result);
                    if (! $row['active']) {
                        Translator::getInstance()->setSchema();
                        $output->debug(sprintf("`n`3Module `#%s`3 is not active, but was attempted to be injected.`n", $moduleName));
                        self::$injectedModules[$force][$moduleName] = false;
                        return false;
                    }
                }
            }
            require_once $modulefilename;
            ModuleManager::setMostRecentModule($moduleName);

            $installFname   = $moduleName . '_install';
            $uninstallFname = $moduleName . '_uninstall';
            if (! function_exists($installFname) || ! function_exists($uninstallFname)) {
                Translator::getInstance()->setSchema();
                self::$injectedModules[$force][$moduleName] = false;
                return false;
            }

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
                    Translator::getInstance()->setSchema();
                    $output->outputNotl("`n`3Module `#%s`3 does not meet its prerequisites.`n", $moduleName);
                    return false;
                }
            }
            if ($withDb && Database::numRows($result) > 0) {
                if (! isset($row)) {
                    $row = Database::fetchAssoc($result);
                }
                $filemoddate = date('Y-m-d H:i:s', filemtime($modulefilename));
                if ($row['filemoddate'] != $filemoddate || $row['infokeys'] == '' || $row['infokeys'][0] != '|' || $row['version'] == '') {
                    $sql = 'LOCK TABLES ' . Database::prefix('modules') . ' WRITE';
                    Database::query($sql);
                    $sql    = 'SELECT filemoddate FROM ' . Database::prefix('modules') . " WHERE modulename='$moduleName'";
                    $result = Database::query($sql);
                    $row    = Database::fetchAssoc($result);
                    if ($row['filemoddate'] != $filemoddate || ! isset($row['infokeys']) || $row['infokeys'] == '' || $row['infokeys'][0] != '|' || $row['version'] == '') {
                        $output->debug("The module $moduleName was found to have updated, upgrading the module now.");
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
                        $sql  = 'UPDATE ' . Database::prefix('modules') .
                            " SET moduleauthor='" . addslashes((string) ($info['author'] ?? '')) .
                            "', category='" . addslashes((string) ($info['category'] ?? '')) .
                            "', formalname='" . addslashes((string) ($info['name'] ?? '')) .
                            "', description='" . addslashes((string) ($info['description'] ?? '')) .
                            "', filemoddate='$filemoddate', infokeys='$keys',version='" . addslashes((string) ($info['version'] ?? '')) .
                            "',download='" . addslashes((string) ($info['download'] ?? '')) . "' WHERE modulename='$moduleName'";
                        Database::query($sql);
                        $output->debug($sql);
                        $sql = 'UNLOCK TABLES';
                        Database::query($sql);
                        self::wipeHooks();
                        $fname = $moduleName . '_install';
                        $fname();
                        DataCache::getInstance()->invalidatedatacache("inject-$moduleName");
                    } else {
                        $sql = 'UNLOCK TABLES';
                        Database::query($sql);
                    }
                }
            }
            Translator::getInstance()->setSchema();
            self::$injectedModules[$force][$moduleName] = true;
            return true;
        }

        $output->output("`n`\$Module '`^%s`\$' (%s) was not found in the modules directory.`0`n", $moduleName, $modulefilename);
        $output->outputNotl(Backtrace::show(), true);
        self::$injectedModules[$force][$moduleName] = false;
        return false;
    }

    /**
     * Return status bitfield for a module.
     */
    /**
     * Return status bitfield for a module.
     *
     * @param string $moduleName Module short name
     * @param string|false $version Optional version check
     */
    public static function getStatus(string $moduleName, string|false $version = false): int
    {


        $moduleName     = Sanitize::modulenameSanitize($moduleName);
        $modulefilename = "modules/$moduleName.php";
        $status         = MODULE_NO_INFO;
        if (file_exists($modulefilename)) {
            $sql    = 'SELECT active,filemoddate,infokeys,version FROM ' . Database::prefix('modules') . " WHERE modulename='$moduleName'";
            $result = Database::queryCached($sql, "inject-$moduleName", 3600);
            if (Database::numRows($result) > 0) {
                $status = MODULE_INSTALLED;
                $row    = Database::fetchAssoc($result);
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
                    if (self::compareVersions($row['version'], $version) < 0) {
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
    /**
     * Check if a module is active.
     */
    public static function isActive(string $moduleName): bool
    {
        return (bool) (self::getStatus($moduleName) & MODULE_ACTIVE);
    }

    /**
     * Determine if a module is installed optionally checking version.
     */
    /**
     * Determine if a module is installed and optionally verify version.
     *
     * @param string      $moduleName Module short name
     * @param string|false $version    Required version or false
     */
    public static function isInstalled(string $moduleName, string|false $version = false): bool
    {
        $status = self::getStatus($moduleName, $version);
        return (bool) (($status & MODULE_INSTALLED) && ($status & MODULE_VERSION_OK));
    }

    /**
     * Validate module requirements and optionally inject dependencies.
     *
     * @param array $reqs        Module requirements
     * @param bool  $forceinject Inject missing modules if true
     */
    public static function checkRequirements(array $reqs, bool $forceinject = false): bool
    {
        $oldmodule = ModuleManager::getMostRecentModule();
        $result    = true;

        if (! is_array($reqs)) {
            return false;
        }

        foreach ($reqs as $key => $val) {
            if (is_int($key)) {
                [$key, $ver] = array_pad(explode('|', $val), 2, '');
            } else {
                [$ver] = explode('|', $val);
            }
            if (! self::isInstalled($key, $ver)) {
                return false;
            }
            $status = self::getStatus($key);
            if (
                ! (
                    $status & MODULE_INJECTED
                ) && $forceinject
            ) {
                $result = $result && self::inject($key);
            }
        }

        ModuleManager::setMostRecentModule($oldmodule);
        return $result;
    }

    /**
     * Block a module from participating in hooks during the current request.
     *
     * @param string $moduleName Module short name
     */
    public static function block(string $moduleName): void
    {
        self::$blockedModules[$moduleName] = 1;
    }

    /**
     * Allow a previously blocked module to participate in hooks again.
     *
     * @param string $moduleName Module short name
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
     *
     * @param string $moduleName Module short name
     */
    public static function isModuleBlocked(string $moduleName): bool
    {
        return (self::$blockAllModules || (self::$blockedModules[$moduleName] ?? false))
            && !(self::$unblockedModules[$moduleName] ?? false);
    }

    /**
     * Prefetch hook information for a set of hooks.
     *
     * @param array $hookNames List of hook names
     */
    public static function massPrepare(array $hookNames): bool
    {
        sort($hookNames);
        $Pmodules          = Database::prefix('modules');
        $Pmodule_hooks     = Database::prefix('module_hooks');
        $Pmodule_settings  = Database::prefix('module_settings');
        $Pmodule_userprefs = Database::prefix('module_userprefs');

        $module_settings = &ModuleManager::settings();
        $module_prefs    = &ModuleManager::prefs();
        global $session;

        $namesStr = "'" . implode("', '", $hookNames) . "'";
        $sql  = 'SELECT '
            . "$Pmodule_hooks.modulename, $Pmodule_hooks.location, $Pmodule_hooks.hook_callback, $Pmodule_hooks.whenactive"
            . ' FROM ' . $Pmodule_hooks
            . ' INNER JOIN ' . $Pmodules
            . ' ON ' . $Pmodules . '.modulename = ' . $Pmodule_hooks . '.modulename'
            . " WHERE active = 1 AND location IN ($namesStr)"
            . ' ORDER BY '
            . "$Pmodule_hooks.location, $Pmodule_hooks.priority, $Pmodule_hooks.modulename";
        $result = Database::queryCached($sql, 'module_prepare-' . md5(implode('', $hookNames)));

        $moduleNames = [];
        while ($row = Database::fetchAssoc($result)) {
            $moduleNames[$row['modulename']] = $row['modulename'];
            if (!isset(self::$modulePreload[$row['location']])) {
                self::$modulePreload[$row['location']] = [];
                self::$modulehookQueries[$row['location']] = [];
            }
            self::$modulehookQueries[$row['location']][] = $row;
            self::$modulePreload[$row['location']][$row['modulename']] = $row['hook_callback'];
        }

        $moduleList = "'" . implode("', '", $moduleNames) . "'";

        $sql = 'SELECT modulename,setting,value FROM ' . $Pmodule_settings . ' WHERE modulename IN (' . $moduleList . ')';
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $module_settings[$row['modulename']][$row['setting']] = $row['value'];
        }

        if (!isset($session['user']['acctid'])) {
            return true;
        }

        $sql = 'SELECT modulename,setting,userid,value FROM ' . $Pmodule_userprefs
            . ' WHERE modulename IN (' . $moduleList . ')'
            . ' AND userid = ' . (int) $session['user']['acctid'];
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $module_prefs[$row['userid']][$row['modulename']][$row['setting']] = $row['value'];
        }
        return true;
    }

    /**
     * Execute hooks registered for a location.
     */
    public static function hook(string $hookName, array $args = [], bool $allowInactive = false, $only = false)
    {
        global $session;
        $settings = Settings::getInstance();
        $output   = Output::getInstance();
        $nav      = Navigation::getInstance();

        if (defined('IS_INSTALLER') && IS_INSTALLER) {
            return $args;
        }

        $lasthook   = ModuleManager::getCurrentHook();
        ModuleManager::setCurrentHook($hookName);
        static $hookcomment = [];

        if ($args === false) {
            $args = [];
        }
        $active = '';
        if (!$allowInactive) {
            $active = ' ' . Database::prefix('modules') . '.active=1 AND';
        }

        if (!is_array($args)) {
            $where = ModuleManager::getMostRecentModule() ?: ScriptName::current();
            $output->debug("Args parameter to modulehook $hookName from $where is not an array.");
        }

        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT) && !isset($hookcomment[$hookName])) {
            $output->rawOutput("<!--Module Hook: $hookName; allow inactive: " . ($allowInactive ? 'true' : 'false') . '; only this module: ' . ($only !== false ? $only : 'any module'));
            if (!is_array($args)) {
                $arg = $args . ' (NOT AN ARRAY!)';
                $output->rawOutput('  arg: ' . $arg);
            } else {
                foreach ($args as $key => $val) {
                    $arg = $key . ' = ';
                    if (is_array($val)) {
                        $arg .= 'array(' . count($val) . ')';
                    } elseif (is_object($val)) {
                        $arg .= 'object(' . get_class($val) . ')';
                    } else {
                        $arg .= htmlentities(substr((string) $val, 0, 25), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
                    }
                    $output->rawOutput('  arg: ' . $arg);
                }
            }
            $output->rawOutput('  -->');
            $hookcomment[$hookName] = true;
        }

        $result = null;
        if (isset(self::$modulehookQueries[$hookName]) && $allowInactive == false) {
            $result = self::$modulehookQueries[$hookName];
        } else {
            $sql = 'SELECT '
                . Database::prefix('module_hooks') . '.modulename,'
                . Database::prefix('module_hooks') . '.location,'
                . Database::prefix('module_hooks') . '.hook_callback,'
                . Database::prefix('module_hooks') . '.whenactive'
                . ' FROM ' . Database::prefix('module_hooks')
                . ' INNER JOIN ' . Database::prefix('modules')
                . ' ON ' . Database::prefix('modules') . '.modulename = ' . Database::prefix('module_hooks') . '.modulename'
                . " WHERE $active " . Database::prefix('module_hooks') . ".location='$hookName'"
                . ' ORDER BY ' . Database::prefix('module_hooks') . '.priority,'
                . Database::prefix('module_hooks') . '.modulename';
            $result = Database::queryCached($sql, 'hook-' . $hookName);
        }

        if (!is_array($args)) {
            $args = ['bogus_args' => $args];
        }

        $mod = ModuleManager::getMostRecentModule();

        while ($row = Database::fetchAssoc($result)) {
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
                $oldnavsection = $nav->getNavSection();
                Translator::getInstance()->setSchema('module-' . $row['modulename']);

                if (!array_key_exists('whenactive', $row)) {
                    $row['whenactive'] = '';
                }
                $cond = trim($row['whenactive']);
                if ($cond == '' || self::condition($cond) === true) {
                    $starttime = microtime(true);
                    $res = $args;
                    if (function_exists($row['hook_callback'])) {
                        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)) {
                            $output->rawOutput('<!-- Hook: ' . $hookName . ' on module ' . $row['hook_callback'] . ' called... -->');
                        }
                        $res = $row['hook_callback']($hookName, $args);
                    } else {
                        trigger_error('Unknown function ' . $row['hook_callback'] . ' for hookname ' . $hookName . ' in module ' . $row['modulename'] . '.', E_USER_WARNING);
                    }
                    $endtime = microtime(true);
                    if (($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
                        $output->debug('Slow Hook (' . round($endtime - $starttime, 2) . 's): ' . $hookName . ' - ' . $row['modulename'] . '`n');
                    }
                    if ($settings->getSetting('debug', 0)) {
                        $sql = 'INSERT INTO ' . Database::prefix('debug') . " VALUES (0,'hooktime','" . $hookName . "','" . $row['modulename'] . "','" . ($endtime - $starttime) . "');";
                        Database::query($sql);
                    }

                    if (!is_array($res)) {
                        trigger_error($row['hook_callback'] . ' did not return an array in the module ' . $row['modulename'] . ' for hook ' . $hookName . '.', E_USER_WARNING);
                        $res = $args;
                    }

                    $args = $res;
                    $nav->setNavSection($oldnavsection);
                    Translator::getInstance()->setSchema();
                }
            }
        }

        ModuleManager::setMostRecentModule($mod);
        ModuleManager::setCurrentHook($lasthook);
        return $args;
    }

    /**
     * Retrieve all settings for a module.
     */
    public static function getAllModuleSettings(?string $module = null): array
    {
        $module_settings = &ModuleManager::settings();

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        self::loadModuleSettings($module);

        return $module_settings[$module] ?? [];
    }

    /**
     * Fetch a specific module setting value.
     */
    public static function getModuleSetting(string $name, ?string $module = null)
    {
        $module_settings = &ModuleManager::settings();

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        self::loadModuleSettings($module);

        if (isset($module_settings[$module][$name])) {
            return $module_settings[$module][$name];
        }

        $info = self::getModuleInfo($module);
        if (isset($info['settings'][$name])) {
            $val = $info['settings'][$name];
            if (is_array($val)) {
                $val = $val[0];
            }
            $x = explode('|', $val);
            if (isset($x[1])) {
                return $x[1];
            }
        }

        return null;
    }

    /**
     * Persist a module setting value.
     */
    public static function setModuleSetting(string $name, $value, ?string $module = null): void
    {
        $module_settings = &ModuleManager::settings();

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        self::loadModuleSettings($module);

        if (isset($module_settings[$module][$name])) {
            $sql = 'UPDATE ' . Database::prefix('module_settings')
                . " SET value='" . addslashes((string) $value)
                . "' WHERE modulename='$module' AND setting='" . addslashes($name) . "'";
            Database::query($sql);
        } else {
            $sql = 'INSERT INTO ' . Database::prefix('module_settings')
                . " (modulename,setting,value) VALUES ('$module','" . addslashes($name)
                . "','" . addslashes((string) $value) . "')";
            Database::query($sql);
        }

        DataCache::getInstance()->invalidatedatacache("modulesettings-$module");
        $module_settings[$module][$name] = $value;
    }

    /**
     * Increment a numeric module setting.
     */
    public static function incrementModuleSetting(string $name, $value = 1, ?string $module = null): void
    {
        $module_settings = &ModuleManager::settings();

        $value = (float) $value;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        self::loadModuleSettings($module);

        if (isset($module_settings[$module][$name])) {
            $sql = 'UPDATE ' . Database::prefix('module_settings')
                . " SET value=value+$value WHERE modulename='$module' AND setting='" . addslashes($name) . "'";
            Database::query($sql);
        } else {
            $sql = 'INSERT INTO ' . Database::prefix('module_settings')
                . " (modulename,setting,value) VALUES ('$module','" . addslashes($name)
                . "','" . addslashes($value) . "')";
            Database::query($sql);
        }

        DataCache::getInstance()->invalidatedatacache("modulesettings-$module");
        $module_settings[$module][$name] = ($module_settings[$module][$name] ?? 0) + $value;
    }

    /**
     * Remove cached module settings for a module.
     */
    public static function clearModuleSettings(?string $module = null): void
    {
        $module_settings = &ModuleManager::settings();

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        if (isset($module_settings[$module])) {
            unset($module_settings[$module]);
            DataCache::getInstance()->invalidatedatacache("modulesettings-$module");
        }
    }

    /**
     * Load module settings from the database.
     */
    public static function loadModuleSettings(string $module): void
    {
        $module_settings = &ModuleManager::settings();

        if (!isset($module_settings[$module])) {
            $module_settings[$module] = [];
            $sql    = 'SELECT * FROM ' . Database::prefix('module_settings') . " WHERE modulename='$module'";
            $result = Database::queryCached($sql, "modulesettings-$module");
            while ($row = Database::fetchAssoc($result)) {
                $module_settings[$module][$row['setting']] = $row['value'];
            }
        }
    }

    /**
     * Delete all object preferences for a specific type/id.
     */
    public static function deleteObjPrefs(string $objtype, $objid): void
    {
        $sql = 'DELETE FROM ' . Database::prefix('module_objprefs') . " WHERE objtype='$objtype' AND objid='$objid'";
        Database::query($sql);
        DataCache::getInstance()->massinvalidate("objpref-$objtype-$objid");
    }

    /**
     * Retrieve an object preference.
     */
    public static function getObjPref(string $type, $objid, string $name, ?string $module = null)
    {
        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        $sql = 'SELECT value FROM ' . Database::prefix('module_objprefs')
            . " WHERE modulename='$module' AND objtype='$type' AND setting='" . addslashes($name) . "' AND objid='$objid'";
        $result = Database::queryCached($sql, "objpref-$type-$objid-$name-$module", 86400);

        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            return $row['value'];
        }

        $info = self::getModuleInfo($module);
        if (isset($info['prefs-' . $type][$name])) {
            $val = $info['prefs-' . $type][$name];
            if (is_array($val)) {
                $val = $val[0];
            }
            $x = explode('|', $val);
            if (isset($x[1])) {
                self::setObjPref($type, $objid, $name, $x[1], $module);
                return $x[1];
            }
        }

        return null;
    }

    /**
     * Persist an object preference value.
     */
    public static function setObjPref(string $objtype, $objid, string $name, $value, ?string $module = null): void
    {
        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        $sql = 'REPLACE INTO ' . Database::prefix('module_objprefs')
            . "(modulename,objtype,setting,objid,value) VALUES ('$module', '$objtype', '$name', '$objid', '" . addslashes((string)$value) . "')";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("objpref-$objtype-$objid-$name-$module");
    }

    /**
     * Increase an object preference value numerically.
     */
    public static function incrementObjPref(string $objtype, $objid, string $name, $value = 1, ?string $module = null): void
    {
        $value = (float) $value;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }

        $sql = 'UPDATE ' . Database::prefix('module_objprefs')
            . " SET value=value+$value WHERE modulename='$module' AND setting='" . addslashes($name)
            . "' AND objtype='" . addslashes($objtype) . "' AND objid=$objid;";
        $result = Database::query($sql);
        if (Database::affectedRows() == 0) {
            $sql = 'INSERT INTO ' . Database::prefix('module_objprefs')
                . "(modulename,objtype,setting,objid,value) VALUES ('$module', '$objtype', '$name', '$objid', '" . addslashes((string)$value) . "')";
            Database::query($sql);
        }

        DataCache::getInstance()->invalidatedatacache("objpref-$objtype-$objid-$name-$module");
    }

    /**
     * Delete all user preferences for a user.
     */
    public static function deleteUserPrefs(int $user): void
    {
        $module_prefs = &ModuleManager::prefs();

        $sql = 'DELETE FROM ' . Database::prefix('module_userprefs') . " WHERE userid='$user'";
        Database::query($sql);

        unset($module_prefs[$user]);
        DataCache::getInstance()->massinvalidate("module_userprefs-$user");
    }

    /**
     * Retrieve all module preferences for a user.
     */
    public static function getAllModulePrefs(?string $module = null, ?int $user = null): array
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }
        if ($user === null) {
            $user = $session['user']['acctid'] ?? 0;
        }

        // No need to cast $user to (int) as it is already typed as ?int
        // and PHP's type system ensures it is either null or an integer.


        self::loadModulePrefs($module, $user);

        return $module_prefs[$user][$module] ?? [];
    }

    /**
     * Get a specific module preference value for a user.
     */
    public static function getModulePref(string $name, ?string $module = null, ?int $user = null)
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }
        if ($user === null && isset($session['user']['acctid'])) {
            $user = $session['user']['acctid'];
        }

        if ($user !== null) {
            $user = (int) $user;
        }

        if ($user !== null && isset($module_prefs[$user][$module][$name])) {
            return $module_prefs[$user][$module][$name];
        }

        if ($user !== null) {
            self::loadModulePrefs($module, $user);
        }

        if ($user !== null && isset($module_prefs[$user][$module][$name])) {
            return $module_prefs[$user][$module][$name];
        }

        if (!self::isActive($module)) {
            return null;
        }

        $info = self::getModuleInfo($module);
        if (isset($info['prefs'][$name])) {
            $val = $info['prefs'][$name];
            if (is_array($val)) {
                $val = $val[0];
            }
            $x = explode('|', $val);
            if ($user !== null && isset($x[1])) {
                self::setModulePref($name, $x[1], $module, $user);
                return $x[1];
            }
        }

        return null;
    }

    /**
     * Persist a module preference value for a user.
     */
    public static function setModulePref(string $name, $value, ?string $module = null, ?int $user = null): void
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }
        if ($user === null) {
            $uid = $session['user']['acctid'] ?? 0;
        } else {
            $uid = $user;
        }

        if ($uid !== null) {
            $uid = (int) $uid;
        }

        self::loadModulePrefs($module, $uid);

        if (!$user && !$session['user']['loggedin']) {
            $module_prefs[$uid][$module][$name] = $value;
            return;
        }

        if (isset($module_prefs[$uid][$module][$name])) {
            $sql = 'UPDATE ' . Database::prefix('module_userprefs')
                . " SET value='" . addslashes((string) $value)
                . "' WHERE modulename='$module' AND setting='$name' AND userid='$uid'";
            Database::query($sql);
        } else {
            $sql = 'INSERT INTO ' . Database::prefix('module_userprefs')
                . " (modulename,setting,userid,value) VALUES ('$module','$name','$uid','" . addslashes((string) $value) . "')";
            Database::query($sql);
        }

        $module_prefs[$uid][$module][$name] = $value;
    }

    /**
     * Increment a numeric module preference value.
     */
    public static function incrementModulePref(string $name, $value = 1, ?string $module = null, ?int $user = null): void
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        $value = (float) $value;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }
        if ($user === null) {
            $uid = $session['user']['acctid'];
        } else {
            $uid = $user;
        }

        if ($uid !== null) {
            $uid = (int) $uid;
        }

        self::loadModulePrefs($module, $uid);

        if (!$session['user']['loggedin'] && !$user) {
            $module_prefs[$uid][$module][$name] = ($module_prefs[$uid][$module][$name] ?? 0) + $value;
            return;
        }

        if (isset($module_prefs[$uid][$module][$name])) {
            $sql = 'UPDATE ' . Database::prefix('module_userprefs')
                . " SET value=value+$value WHERE modulename='$module' AND setting='$name' AND userid='$uid'";
            Database::query($sql);
        } else {
            $module_prefs[$uid][$module][$name] = $value;
            $sql = 'INSERT INTO ' . Database::prefix('module_userprefs')
                . " (modulename,setting,userid,value) VALUES ('$module','$name','$uid','" . $value . "')";
            Database::query($sql);
        }

        $module_prefs[$uid][$module][$name] = ($module_prefs[$uid][$module][$name] ?? 0) + $value;
    }

    /**
     * Clear a module preference for a user.
     */
    public static function clearModulePref(string $name, ?string $module = null, ?int $user = null): void
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        if ($module === null) {
            $module = ModuleManager::getMostRecentModule();
        }
        if ($user === null) {
            $uid = $session['user']['acctid'];
        } else {
            $uid = $user;
        }
        if ($uid !== null) {
            $uid = (int) $uid;
        }

        self::loadModulePrefs($module, $uid);

        if (!$session['user']['loggedin'] && !$user) {
            unset($module_prefs[$uid][$module][$name]);
            return;
        }

        if (isset($module_prefs[$uid][$module][$name])) {
            $sql = 'DELETE FROM ' . Database::prefix('module_userprefs')
                . " WHERE modulename='$module' AND setting='$name' AND userid='$uid'";
            Database::query($sql);
        }

        unset($module_prefs[$uid][$module][$name]);
    }

    /**
     * Load user preferences for a module.
     */
    public static function loadModulePrefs(string $module, ?int $user = null): void
    {
        $module_prefs = &ModuleManager::prefs();
        global $session;

        if ($user === null) {
            $user = $session['user']['acctid'];
        }

        if ($user !== null) {
            $user = (int) $user;
        }

        if (!isset($module_prefs[$user])) {
            $module_prefs[$user] = [];
        }

        if (!isset($module_prefs[$user][$module])) {
            $module_prefs[$user][$module] = [];
            $sql    = 'SELECT setting,value FROM ' . Database::prefix('module_userprefs') . " WHERE modulename='$module' AND userid='$user'";
            $result = Database::query($sql);
            while ($row = Database::fetchAssoc($result)) {
                $module_prefs[$user][$module][$row['setting']] = $row['value'];
            }
        }
    }

    /**
     * Retrieve module information by executing its info function.
     */
    public static function getModuleInfo(string $shortname, bool $withDb = true): array
    {
        $moduleinfo = [];
        $mod        = ModuleManager::getMostRecentModule();

        if (self::inject($shortname, true, $withDb)) {
            $fname = $shortname . '_getmoduleinfo';
            if (function_exists($fname)) {
                Translator::getInstance()->setSchema("module-$shortname");
                $moduleinfo = $fname();
                Translator::getInstance()->setSchema();
                $ns = Translator::translateInline('Not specified', 'common');
                if (!isset($moduleinfo['name'])) {
                    $moduleinfo['name'] = "$ns ($shortname)";
                }
                if (!isset($moduleinfo['category'])) {
                    $moduleinfo['category'] = "$ns ($shortname)";
                }
                if (!isset($moduleinfo['author'])) {
                    $moduleinfo['author'] = "$ns ($shortname)";
                }
                if (!isset($moduleinfo['version'])) {
                    $moduleinfo['version'] = '0.0';
                }
                if (!isset($moduleinfo['download'])) {
                    $moduleinfo['download'] = '';
                }
                if (!isset($moduleinfo['description'])) {
                    $moduleinfo['description'] = '';
                }
            }
            if (!is_array($moduleinfo) || count($moduleinfo) < 2) {
                $mf         = Translator::translateInline('Missing function', 'common');
                $moduleinfo = [
                    'name'        => "$mf ({$shortname}_getmoduleinfo)",
                    'version'     => '0.0',
                    'author'      => "$mf ({$shortname}_getmoduleinfo)",
                    'category'    => "$mf ({$shortname}_getmoduleinfo)",
                    'download'    => '',
                    'description' => '',
                ];
            }
        } else {
            return [];
        }

        ModuleManager::setMostRecentModule($mod);

        if (!isset($moduleinfo['requires'])) {
            $moduleinfo['requires'] = [];
        }

        return $moduleinfo;
    }

    /**
     * Remove all hooks and event hooks for the current module.
     */
    public static function wipeHooks(): void
    {
        $module = ModuleManager::getMostRecentModule();

        $sql = 'DELETE FROM ' . Database::prefix('module_hooks') . " WHERE modulename='$module'";
        Database::query($sql);
        $sql = 'DELETE FROM ' . Database::prefix('module_event_hooks') . " WHERE modulename='$module'";
        Database::query($sql);

        DataCache::getInstance()->invalidatedatacache('hook-' . $module);

        DataCache::getInstance()->invalidatedatacache('module_prepare');
    }

    /**
     * Register an event hook for a module.
     *
     * Any existing event hook for the same module and event type is removed
     * first to avoid duplicate entries in the database which would lead to
     * duplicated rows in the developer event list.
     */
    public static function addEventHook(string $type, string $chance): void
    {
        $module = ModuleManager::getMostRecentModule();

        self::dropEventHook($type);

        $sql = 'INSERT INTO ' . Database::prefix('module_event_hooks')
            . " (modulename, event_type, event_chance) VALUES ('" . $module . "', '$type', '" . addslashes($chance) . "')";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("event-$type-0");
        DataCache::getInstance()->invalidatedatacache("event-$type-1");
    }

    /**
     * Remove an existing event hook for the current module.
     *
     * This is called from {@see addEventHook()} before inserting a new hook so
     * the table never contains duplicate rows.
     */
    public static function dropEventHook(string $type): void
    {
        $module = ModuleManager::getMostRecentModule();

        $sql = 'DELETE FROM ' . Database::prefix('module_event_hooks')
            . " WHERE modulename='$module' AND event_type='" . addslashes($type) . "'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("event-$type-0");
        DataCache::getInstance()->invalidatedatacache("event-$type-1");
    }

    /**
     * Remove a hook from a module.
     */
    public static function dropHook(string $hookname, $functioncall = false): void
    {
        $module = ModuleManager::getMostRecentModule();

        if ($functioncall === false) {
            $functioncall = $module . '_dohook';
        }

        $sql = 'DELETE FROM ' . Database::prefix('module_hooks')
            . " WHERE modulename='$module' AND location='" . addslashes($hookname)
            . "' AND hook_callback='" . addslashes($functioncall) . "'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("hook-$hookname");
        DataCache::getInstance()->invalidatedatacache('module_prepare');
    }

    /**
     * Register a hook for a module.
     */
    public static function addHook(string $hookname, $functioncall = false, $whenactive = false): void
    {
        self::addHookPriority($hookname, 50, $functioncall, $whenactive);
    }

    /**
     * Register a hook with explicit priority.
     */
    public static function addHookPriority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
    {
        $module = ModuleManager::getMostRecentModule();

        self::dropHook($hookname, $functioncall);

        if ($functioncall === false) {
            $functioncall = $module . '_dohook';
        }
        if ($whenactive === false) {
            $whenactive = '';
        }

        $sql = 'REPLACE INTO ' . Database::prefix('module_hooks')
            . " (modulename,location,hook_callback,whenactive,priority) VALUES ('$module','" . addslashes($hookname)
            . "','" . addslashes($functioncall) . "','" . addslashes($whenactive) . "','" . $priority . "')";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("hook-$hookname");
        DataCache::getInstance()->invalidatedatacache('module_prepare');
    }

    /**
     * Acquire a module semaphore using table locking.
     */
    public static function semAcquire(): void
    {
        $sql = 'LOCK TABLES ' . Database::prefix('module_settings') . ' WRITE';
        Database::query($sql);
    }

    /**
     * Release a previously acquired semaphore lock.
     */
    public static function semRelease(): void
    {
        $sql = 'UNLOCK TABLES';
        Database::query($sql);
    }

    /**
     * Collect available events for a given type.
     */
    public static function collectEvents(string $type, bool $allowinactive = false): array
    {
        global $session;

        $active = '';
        $events = [];
        if (!$allowinactive) {
            $active = ' active=1 AND';
        }

        $sql = 'SELECT ' . Database::prefix('module_event_hooks') . '.* FROM ' . Database::prefix('module_event_hooks')
            . ' INNER JOIN ' . Database::prefix('modules') . ' ON ' . Database::prefix('modules') . '.modulename = ' . Database::prefix('module_event_hooks') . '.modulename'
            . " WHERE $active event_type='$type' ORDER BY RAND(" . Random::e_rand() . ')';
        $result = Database::queryCached($sql, 'event-' . $type . '-' . ((int) $allowinactive));
        while ($row = Database::fetchAssoc($result)) {
            ob_start();
            $chance = eval($row['event_chance'] . ';');
            $err    = ob_get_contents();
            ob_end_clean();
            if ($err > '') {
                Output::getInstance()->debug(['error' => $err, 'Eval code' => $row['event_chance']]);
            }
            if ($chance < 0) {
                $chance = 0;
            }
            if ($chance > 100) {
                $chance = 100;
            }
            if (self::isModuleBlocked($row['modulename'])) {
                $chance = 0;
            }
            $events[] = ['modulename' => $row['modulename'], 'rawchance' => $chance];
        }

        $sum = 0;
        foreach ($events as $event) {
            $sum += $event['rawchance'];
        }
        foreach ($events as $index => $event) {
            if ($sum == 0) {
                $events[$index]['normchance'] = 0;
            } else {
                $events[$index]['normchance'] = round($event['rawchance'] / $sum * 100, 3);
                if ($events[$index]['normchance'] > $event['rawchance']) {
                    $events[$index]['normchance'] = $event['rawchance'];
                }
            }
        }

        return HookHandler::hook('collect-events', $events);
    }

    /**
     * Trigger a module event if the base chance allows.
     */
    public static function moduleEvents(string $eventtype, int $basechance, ?string $baseLink = null): int
    {
        if ($baseLink === null) {
            $baseLink = ScriptName::current() . '.php?';
        }

        $output = Output::getInstance();
        if (Random::e_rand(1, 100) <= $basechance) {
            $events = self::collectEvents($eventtype, false);
            $chance = Random::r_rand(1, 100);
            // debug("C:" . $chance); return 0; // debugging line from original
            $sum = 0;
            foreach ($events as $event) {
                if ($event['rawchance'] == 0) {
                    continue;
                }
                if ($chance > $sum && $chance <= $sum + $event['normchance']) {
                    $_POST['i_am_a_hack'] = 'true';
                    Translator::getInstance()->setSchema('events');
                    $output->output('`^`c`bSomething Special!`c`b`0');
                    Translator::getInstance()->setSchema();
                    $op = Http::get('op');
                    Http::set('op', '');
                    self::doEvent($eventtype, $event['modulename'], false, $baseLink);
                    Http::set('op', $op);
                    return 1;
                }
                $sum += $event['normchance'];
            }
        }

        return 0;
    }

    /**
     * Execute a specific module event.
     */
    public static function doEvent(string $type, string $module, bool $allowinactive = false, ?string $baseLink = null): void
    {
        if ($baseLink === null) {
            $baseLink = ScriptName::current() . '.php?';
        }

        $mod = ModuleManager::getMostRecentModule();
        $nav = Navigation::getInstance();

        $_POST['i_am_a_hack'] = 'true';
        if (self::inject($module, $allowinactive)) {
            $oldnavsection = $nav->getNavSection();
            Translator::getInstance()->setSchema("module-$module");
            $fname = $module . '_runevent';
            $fname($type, $baseLink);
            Translator::getInstance()->setSchema();
            HookHandler::hook("runevent_$module", ['type' => $type, 'baselink' => $baseLink, 'get' => Http::allGet(), 'post' => Http::allPost()]);
            $nav->setNavSection($oldnavsection);
        }

        ModuleManager::setMostRecentModule($mod);
    }

    public static function eventSort($a, $b)
    {
        return strcmp($a['modulename'], $b['modulename']);
    }

    /**
     * Display available events for debugging purposes.
     */
    public static function displayEvents(string $eventtype, $forcescript = false): void
    {
        global $session;

        if (!($session['user']['superuser'] & SU_DEVELOPER)) {
            return;
        }

        if ($forcescript === false) {
            $script = ScriptName::current() . '.php';
        } else {
            $script = $forcescript;
        }

        $events = self::collectEvents($eventtype, true);

        if (!is_array($events) || count($events) == 0) {
            return;
        }

        usort($events, [self::class, 'eventSort']);

        $output = Output::getInstance();
        Translator::getInstance()->setSchema('events');
        $output->output("`n`nSpecial event triggers:`n");
        $name    = Translator::translateInline('Name');
        $rchance = Translator::translateInline('Raw Chance');
        $nchance = Translator::translateInline('Normalized Chance');
        $output->rawOutput("<table cellspacing='1' cellpadding='2' border='0' bgcolor='#999999'>");
        $output->rawOutput("<tr class='trhead'>");
        $output->rawOutput("<td>$name</td><td>$rchance</td><td>$nchance</td><td>Filename</td><td>exists</td>");
        $output->rawOutput('</tr>');
        $i = 0;
        foreach ($events as $event) {
            $output->rawOutput("<tr class='" . ($i % 2 == 0 ? 'trdark' : 'trlight') . "'>");
            $i++;
            $link = '';
            $filename = '';
            $exists = 0;
            $name = $event['modulename'];
            if ($event['modulename']) {
                $link     = 'module-' . $event['modulename'];
                $filename = $event['modulename'] . '.php';
                $fullpath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $filename;
                $exists   = (int) file_exists($fullpath);
            }
            $rlink = "$script?eventhandler=$link";
            $rlink = str_replace('?&', '?', $rlink);
            $first = strpos($rlink, '?');
            $rl1   = substr($rlink, 0, $first + 1);
            $rl2   = substr($rlink, $first + 1);
            $rl2   = str_replace('?', '&', $rl2);
            $rlink = $rl1 . $rl2;
            $output->rawOutput("<td><a href='$rlink'>$name</a></td>");
            Navigation::add('', $rlink);
            $output->rawOutput("<td>{$event['rawchance']}</td>");
            $output->rawOutput("<td>{$event['normchance']}</td>");
            $output->rawOutput("<td>{$filename}</td>");
            $output->rawOutput("<td>{$exists}</td>");
            $output->rawOutput('</tr>');
        }
        $output->rawOutput('</table>');
    }

    /**
     * Build navigation options for the module editor.
     */
    public static function editorNavs(string $like, string $linkprefix): void
    {
        $sql    = 'SELECT formalname,modulename,active,category FROM ' . Database::prefix('modules') . " WHERE infokeys LIKE '%|$like|%' ORDER BY category,formalname";
        $result = Database::query($sql);
        $curcat = '';
        while ($row = Database::fetchAssoc($result)) {
            if ($curcat != $row['category']) {
                $curcat = $row['category'];
                Navigation::add(["%s Modules", $curcat]);
            }
            // Prefix inactive modules with a valid colour code so the name
            // does not start with an unescaped backtick. Without a colour
            // letter the first character of the name would be parsed as one,
            // causing unbalanced HTML tags like `<em>`.
            Navigation::addNotl(($row['active'] ? '' : '`&') . $row['formalname'] . '`0', $linkprefix . $row['modulename']);
        }
    }

    /**
     * Display an editing form for object preferences.
     */
    public static function objprefEdit(string $type, string $module, $id): void
    {
        $info = self::getModuleInfo($module);
        if (count($info['prefs-' . $type]) > 0) {
            $data      = [];
            $msettings = [];
            foreach ($info['prefs-' . $type] as $key => $val) {
                if (is_array($val)) {
                    $v       = $val[0];
                    $x       = explode('|', $v);
                    $val[0]  = $x[0];
                    $x[0]    = $val;
                } else {
                    $x = explode('|', $val);
                }
                $msettings[$key] = $x[0];
                if (isset($x[1])) {
                    $data[$key] = $x[1];
                }
            }
            $sql    = 'SELECT setting, value FROM ' . Database::prefix('module_objprefs') . " WHERE modulename='$module' AND objtype='$type' AND objid='$id'";
            $result = Database::query($sql);
            while ($row = Database::fetchAssoc($result)) {
                $data[$row['setting']] = $row['value'];
            }
            Translator::getInstance()->setSchema("module-$module");
            Forms::showForm($msettings, $data);
            Translator::getInstance()->setSchema();
        }
    }

    /**
     * Compare two module version strings.
     */
    public static function compareVersions($a, $b): int
    {
        return version_compare($a, $b);
    }

    /**
     * Activate a module, installing it if necessary.
     */
    public static function activate(string $module): bool
    {
        return Installer::activate($module);
    }

    /**
     * Deactivate a module.
     */
    public static function deactivate(string $module): bool
    {
        return Installer::deactivate($module);
    }

    /**
     * Uninstall a module completely.
     */
    public static function uninstall(string $module): bool
    {
        return Installer::uninstall($module);
    }

    /**
     * Install a module optionally forcing re-install.
     */
    public static function install(string $module, bool $force = true): bool
    {
        return Installer::install($module, $force);
    }

    /**
     * Evaluate a PHP condition in module context.
     */
    public static function condition(string $condition): bool
    {
        return Installer::condition($condition);
    }

    /**
     * Get information about installed and uninstalled modules.
     */
    public static function getInstallStatus(bool $withDb = true): array
    {
        return Installer::getInstallStatus($withDb);
    }

    /**
     * Translate a race code to its display name.
     */
    public static function getRaceName($thisuser = true)
    {
        if ($thisuser === true) {
            global $session;
            return Translator::translateInline($session['user']['race'], 'race');
        }
        return Translator::translateInline($thisuser, 'race');
    }
}
