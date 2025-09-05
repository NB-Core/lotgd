<?php

declare(strict_types=1);

namespace Lotgd\Modules;

use Lotgd\Modules;
use Lotgd\MySQL\Database;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\DataCache;
use Lotgd\Output;

class Installer
{
    public static function activate(string $module): bool
    {
        if (!Modules::isInstalled($module)) {
            if (!self::install($module)) {
                return false;
            }
        }
        $sql = 'UPDATE ' . Database::prefix('modules') . " SET active=1 WHERE modulename='$module'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        return Database::affectedRows() > 0;
    }

    public static function deactivate(string $module): bool
    {
        if (!Modules::isInstalled($module)) {
            if (!self::install($module)) {
                return false;
            }
            return true;
        }
        $sql    = 'UPDATE ' . Database::prefix('modules') . " SET active=0 WHERE modulename='$module'";
        $return = Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        DataCache::getInstance()->massinvalidate('hook');
        if (Database::affectedRows() <= 0 || !$return) {
            return false;
        }
        return true;
    }

    public static function uninstall(string $module): bool
    {
        $output = Output::getInstance();

        if (Modules::inject($module, true)) {
            $fname = $module . '_uninstall';
            $output->output('Running module uninstall script`n');
            Translator::getInstance()->setSchema("module-{$module}");
            $returnvalue = $fname();
            if (!$returnvalue) {
                return false;
            }
            Translator::getInstance()->setSchema();

            $sql = 'DELETE FROM ' . Database::prefix('modules') . " WHERE modulename='$module'";
            Database::query($sql);

            HookHandler::wipeHooks();

            $sql = 'DELETE FROM ' . Database::prefix('module_settings') . " WHERE modulename='$module'";
            Database::query($sql);
            DataCache::getInstance()->invalidatedatacache("modulesettings-$module");

            $sql = 'DELETE FROM ' . Database::prefix('module_userprefs') . " WHERE modulename='$module'";
            Database::query($sql);

            $sql = 'DELETE FROM ' . Database::prefix('module_objprefs') . " WHERE modulename='$module'";
            Database::query($sql);
            DataCache::getInstance()->invalidatedatacache("inject-$module");
            DataCache::getInstance()->massinvalidate('module_prepare');
            return true;
        }
        return false;
    }

    /**
     * Force uninstall even when module file is missing.
     */
    public static function forceUninstall(string $module): bool
    {
        $output = Output::getInstance();

        if (Modules::inject($module, true)) {
            $fname = $module . '_uninstall';
            $output->output('Running module uninstall script`n');
            Translator::getInstance()->setSchema("module-{$module}");
            $returnvalue = $fname();
            if (!$returnvalue) {
                return false;
            }
            Translator::getInstance()->setSchema();
        } else {
            ModuleManager::setMostRecentModule($module);
        }

        $sql = 'DELETE FROM ' . Database::prefix('modules') . " WHERE modulename='$module'";
        Database::query($sql);

        HookHandler::wipeHooks();

        $sql = 'DELETE FROM ' . Database::prefix('module_settings') . " WHERE modulename='$module'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("modulesettings-$module");

        $sql = 'DELETE FROM ' . Database::prefix('module_userprefs') . " WHERE modulename='$module'";
        Database::query($sql);

        $sql = 'DELETE FROM ' . Database::prefix('module_objprefs') . " WHERE modulename='$module'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        return true;
    }

    public static function install(string $module, bool $force = true): bool
    {
        global $session;
        $output = Output::getInstance();

        $name = $session['user']['name'] ?? '`@System`0';

        if (Sanitize::modulenameSanitize($module) != $module) {
            $output->output("Error, module file names can only contain alpha numeric characters and underscores before the trailing .php`n`nGood module names include 'testmodule.php', 'joesmodule2.php', while bad module names include, 'test.module.php' or 'joes module.php'`n");
            return false;
        }

        if ($force) {
            $sql = 'DELETE FROM ' . Database::prefix('modules') . " WHERE modulename='$module'";
            Database::query($sql);
        }

        if (Modules::inject($module, true)) {
            if (!$force && Modules::isInstalled($module)) {
                return true;
            }
            $info = Modules::getModuleInfo($module);
            if (!Modules::checkRequirements($info['requires'])) {
                $output->output("`\$Module could not installed -- it did not meet its prerequisites.`n");
                return false;
            }
            $keys = '|' . implode('|', array_keys($info)) . '|';
            $moduleName = ModuleManager::getMostRecentModule();
            $sql  = 'INSERT INTO ' . Database::prefix('modules') . " (modulename,formalname,moduleauthor,active,filename,installdate,installedby,category,infokeys,version,download,description) VALUES ('$moduleName','" . addslashes($info['name']) . "','" . addslashes($info['author']) . "',0,'{$moduleName}.php','" . date('Y-m-d H:i:s') . "','" . addslashes($name) . "','" . addslashes($info['category']) . "','$keys','" . addslashes($info['version']) . "','" . addslashes($info['download']) . "', '" . addslashes($info['description']) . "')";
            $result = Database::query($sql);
            if (!$result) {
                $output->output('`\$ERROR!`0 The module could not be injected into the database.');
                return false;
            }
            $fname        = $moduleName . '_install';
            $returnvalue  = $fname();
            if (!$returnvalue) {
                return false;
            }
            if (isset($info['settings']) && count($info['settings']) > 0) {
                foreach ($info['settings'] as $key => $val) {
                    if (is_array($val)) {
                        $x = explode('|', $val[0]);
                    } else {
                        $x = explode('|', $val);
                    }
                    if (isset($x[1])) {
                        HookHandler::setModuleSetting($key, $x[1]);
                        $output->debug("Setting $key to default {$x[1]}");
                    }
                }
            }
            $output->output('`^Module installed.  It is not yet active.`n');

            DataCache::getInstance()->invalidatedatacache("inject-$moduleName");

            DataCache::getInstance()->massinvalidate('module_prepare');
            return true;
        }
        $output->output('`\$Module could not be injected.');
        $output->output('Module not installed.');
        $output->output('This is probably due to the module file having a parse error or not existing in the filesystem.`n');
        return false;
    }

    public static function condition(string $condition): bool
    {
        global $session;
        $result = eval($condition);
        return (bool) $result;
    }

    public static function getInstallStatus(bool $withDb = true): array
    {
        $seenmodules = [];
        $seencats    = [];

        if ($withDb) {
            $sql    = 'SELECT modulename,category FROM ' . Database::prefix('modules');
            $result = @Database::query($sql);
            if ($result !== false) {
                while ($row = Database::fetchAssoc($result)) {
                    $seenmodules[$row['modulename'] . '.php'] = true;
                    if (!array_key_exists($row['category'], $seencats)) {
                        $seencats[$row['category']] = 1;
                    } else {
                        $seencats[$row['category']]++;
                    }
                }
            }
        }

        $uninstmodules = [];
        if ($handle = opendir('modules')) {
            $ucount = 0;
            while (false !== ($file = readdir($handle))) {
                if ($file[0] == '.') {
                    continue;
                }
                if (preg_match('/\.php$/', $file) && !isset($seenmodules[$file])) {
                    $ucount++;
                    $uninstmodules[] = substr($file, 0, strlen($file) - 4);
                }
            }
            closedir($handle);
        }

        sort($uninstmodules);
        return [
            'installedcategories' => $seencats,
            'installedmodules'    => $seenmodules,
            'uninstalledmodules'  => $uninstmodules,
            'uninstcount'        => $ucount ?? 0,
        ];
    }
}
