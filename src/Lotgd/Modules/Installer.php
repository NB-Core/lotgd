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
        $normalizedModule = Sanitize::modulenameSanitize($module);
        if ($normalizedModule !== $module) {
            return false;
        }
        $module = $normalizedModule;

        if (!Modules::isInstalled($module)) {
            if (!self::install($module)) {
                return false;
            }
        }
        $table    = Database::prefix('modules');
        $conn     = Database::getDoctrineConnection();
        $affected = $conn->executeStatement(
            "UPDATE {$table} SET active = :active WHERE modulename = :module",
            [
                'active' => 1,
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        return $affected > 0;
    }

    public static function deactivate(string $module): bool
    {
        $normalizedModule = Sanitize::modulenameSanitize($module);
        if ($normalizedModule !== $module) {
            return false;
        }
        $module = $normalizedModule;

        if (!Modules::isInstalled($module)) {
            if (!self::install($module)) {
                return false;
            }
            return true;
        }
        $table    = Database::prefix('modules');
        $conn     = Database::getDoctrineConnection();
        $affected = $conn->executeStatement(
            "UPDATE {$table} SET active = :active WHERE modulename = :module",
            [
                'active' => 0,
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        DataCache::getInstance()->massinvalidate('hook');
        return $affected > 0;
    }

    public static function uninstall(string $module): bool
    {
        $normalizedModule = Sanitize::modulenameSanitize($module);
        if ($normalizedModule !== $module) {
            return false;
        }
        $module = $normalizedModule;

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

            $conn  = Database::getDoctrineConnection();
            $table = Database::prefix('modules');
            $affected = $conn->executeStatement(
                "DELETE FROM {$table} WHERE modulename = :module",
                [
                    'module' => $module,
                ]
            );
            Database::setAffectedRows($affected);

            HookHandler::wipeHooks();

            $settingsTable = Database::prefix('module_settings');
            $affected = $conn->executeStatement(
                "DELETE FROM {$settingsTable} WHERE modulename = :module",
                [
                    'module' => $module,
                ]
            );
            Database::setAffectedRows($affected);
            DataCache::getInstance()->invalidatedatacache("modulesettings-$module");

            $userPrefsTable = Database::prefix('module_userprefs');
            $affected = $conn->executeStatement(
                "DELETE FROM {$userPrefsTable} WHERE modulename = :module",
                [
                    'module' => $module,
                ]
            );
            Database::setAffectedRows($affected);

            $objPrefsTable = Database::prefix('module_objprefs');
            $affected = $conn->executeStatement(
                "DELETE FROM {$objPrefsTable} WHERE modulename = :module",
                [
                    'module' => $module,
                ]
            );
            Database::setAffectedRows($affected);
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
        $normalizedModule = Sanitize::modulenameSanitize($module);
        if ($normalizedModule !== $module) {
            return false;
        }
        $module = $normalizedModule;

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

        $conn  = Database::getDoctrineConnection();
        $table = Database::prefix('modules');
        $affected = $conn->executeStatement(
            "DELETE FROM {$table} WHERE modulename = :module",
            [
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);

        HookHandler::wipeHooks();

        $settingsTable = Database::prefix('module_settings');
        $affected = $conn->executeStatement(
            "DELETE FROM {$settingsTable} WHERE modulename = :module",
            [
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);
        DataCache::getInstance()->invalidatedatacache("modulesettings-$module");

        $userPrefsTable = Database::prefix('module_userprefs');
        $affected = $conn->executeStatement(
            "DELETE FROM {$userPrefsTable} WHERE modulename = :module",
            [
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);

        $objPrefsTable = Database::prefix('module_objprefs');
        $affected = $conn->executeStatement(
            "DELETE FROM {$objPrefsTable} WHERE modulename = :module",
            [
                'module' => $module,
            ]
        );
        Database::setAffectedRows($affected);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module_prepare');
        return true;
    }

    public static function install(string $module, bool $force = true): bool
    {
        global $session;
        $output = Output::getInstance();

        $normalizedModule = Sanitize::modulenameSanitize($module);
        if ($normalizedModule !== $module) {
            $output->output("Error, module file names can only contain alpha numeric characters and underscores before the trailing .php`n`nGood module names include 'testmodule.php', 'joesmodule2.php', while bad module names include, 'test.module.php' or 'joes module.php'`n");
            return false;
        }
        $module = $normalizedModule;

        $name = $session['user']['name'] ?? '`@System`0';

        $conn = Database::getDoctrineConnection();
        if ($force) {
            $deleted = $conn->executeStatement(
                'DELETE FROM ' . Database::prefix('modules') . ' WHERE modulename = :module',
                [
                    'module' => $module,
                ]
            );
            Database::setAffectedRows($deleted);
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
            $rawModuleName = ModuleManager::getMostRecentModule();
            $moduleName    = Sanitize::modulenameSanitize($rawModuleName);
            if ($moduleName !== $rawModuleName) {
                return false;
            }
            $sql     = 'INSERT INTO ' . Database::prefix('modules') . ' '
                . '(modulename,formalname,moduleauthor,active,filename,installdate,installedby,category,infokeys,version,download,description) '
                . 'VALUES (:modulename,:formalname,:moduleauthor,:active,:filename,:installdate,:installedby,:category,:infokeys,:version,:download,:description)';
            $params = [
                'modulename'  => $moduleName,
                'formalname'  => (string) ($info['name'] ?? ''),
                'moduleauthor'=> (string) ($info['author'] ?? ''),
                'active'      => 0,
                'filename'    => "{$moduleName}.php",
                'installdate' => date('Y-m-d H:i:s'),
                'installedby' => (string) $name,
                'category'    => (string) ($info['category'] ?? ''),
                'infokeys'    => $keys,
                'version'     => (string) ($info['version'] ?? ''),
                'download'    => (string) ($info['download'] ?? ''),
                'description' => (string) ($info['description'] ?? ''),
            ];
            $affected = $conn->executeStatement($sql, $params);
            Database::setAffectedRows($affected);
            if ($affected <= 0) {
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
