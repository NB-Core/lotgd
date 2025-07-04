<?php
namespace Lotgd;
use Lotgd\Backtrace;

// ensure global tracking array exists when the class loads
if (!isset($GLOBALS['injected_modules'])) {
    $GLOBALS['injected_modules'] = [1 => [], 0 => []];
}

/**
 * Collection of module helper functions migrated from legacy modules.php
 */
class Modules
{
    /**
     * Inject a module into runtime if available.
     */
    public static function inject(string $moduleName, bool $force = false, bool $withDb = true): bool
    {
        global $mostrecentmodule, $injected_modules;
        $force = $force ? 1 : 0;

        if (isset($injected_modules[$force][$moduleName])) {
            $mostrecentmodule = $moduleName;
            return $injected_modules[$force][$moduleName];
        }

        $moduleName     = modulename_sanitize($moduleName);
        $modulefilename = "modules/{$moduleName}.php";
        if (file_exists($modulefilename)) {
            tlschema("module-{$moduleName}");
            if ($withDb) {
                $sql    = 'SELECT active,filemoddate,infokeys,version FROM ' . db_prefix('modules') . " WHERE modulename='$moduleName'";
                $result = db_query_cached($sql, "inject-$moduleName", 3600);
                if (! $force) {
                    if (db_num_rows($result) == 0) {
                        tlschema();
                        debug(sprintf("`n`3Module `#%s`3 is not installed, but was attempted to be injected.`n", $moduleName));
                        massinvalidate();
                        $injected_modules[$force][$moduleName] = false;
                        return false;
                    }
                    $row = db_fetch_assoc($result);
                    if (! $row['active']) {
                        tlschema();
                        debug(sprintf("`n`3Module `#%s`3 is not active, but was attempted to be injected.`n", $moduleName));
                        $injected_modules[$force][$moduleName] = false;
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
                    $injected_modules[$force][$moduleName] = false;
                    tlschema();
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
            tlschema();
            $injected_modules[$force][$moduleName] = true;
            return true;
        }

        output("`n`\$Module '`^%s`\$' (%s) was not found in the modules directory.`n", $moduleName, $modulefilename);
        output_notl(Backtrace::show(), true);
        $injected_modules[$force][$moduleName] = false;
        return false;
    }

    /**
     * Return status bitfield for a module.
     */
    public static function getStatus(string $moduleName, $version = false): int
    {
        global $injected_modules;

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
                    if (array_key_exists($moduleName, $injected_modules[0]) && $injected_modules[0][$moduleName]) {
                        $status |= MODULE_INJECTED;
                    }
                    if (array_key_exists($moduleName, $injected_modules[1]) && $injected_modules[1][$moduleName]) {
                        $status |= MODULE_INJECTED;
                    }
                } else {
                    if (array_key_exists($moduleName, $injected_modules[1]) && $injected_modules[1][$moduleName]) {
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
}

