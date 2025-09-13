<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Modules\Installer;
use Lotgd\DataCache;
use Lotgd\Modules;
use Lotgd\GameLog;

class ModuleManager
{
    /**
     * Return list of installed modules optionally filtered by category.
     *
     * @param string|null $category  Filter results by category
     * @param string      $sortBy    Column to sort by
     * @param bool        $ascending Sort order
     *
     * @return array<string,mixed>[] List of installed modules
     */
    public static function listInstalled(?string $category = null, string $sortBy = 'installdate', bool $ascending = false): array
    {
        $sql = 'SELECT * FROM ' . Database::prefix('modules');
        if ($category !== null) {
            $sql .= " WHERE category='" . Database::escape($category) . "'";
        }
        $sql .= ' ORDER BY ' . $sortBy . ' ' . ($ascending ? 'ASC' : 'DESC');
        $result = Database::query($sql);
        $modules = [];
        if ($result !== false) {
            while ($row = Database::fetchAssoc($result)) {
                $modules[] = $row;
            }
        }
        return $modules;
    }

    /**
     * Retrieve all uninstalled modules.
     *
     * @return array<string> List of module names
     */
    public static function listUninstalled(): array
    {
        $status = Installer::getInstallStatus(true);
        return $status['uninstalledmodules'] ?? [];
    }

    /**
     * Get the categories of installed modules.
     *
     * @return array<string,int> Mapping of category name to count
     */
    public static function getInstalledCategories(): array
    {
        $status = Installer::getInstallStatus(true);
        return $status['installedcategories'] ?? [];
    }

    /**
     * Install a module.
     *
     * @return bool True on success
     */
    public static function install(string $module): bool
    {
        global $session;
        if (Installer::install($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
            GameLog::log(
                "Module {$module} installed",
                'modules',
                false,
                $session['user']['acctid'] ?? 0
            );

            return true;
        }

        return false;
    }

    /**
     * Uninstall a module.
     *
     * @return bool True on success
     */
    public static function uninstall(string $module): bool
    {
        global $session;
        if (Installer::uninstall($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
            DataCache::getInstance()->invalidatedatacache("inject-$module");
            GameLog::log(
                "Module {$module} uninstalled",
                'modules',
                false,
                $session['user']['acctid'] ?? 0
            );

            return true;
        }

        return false;
    }

    /**
     * Activate a module.
     */
    public static function activate(string $module): bool
    {
        global $session;
        $res = Installer::activate($module);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('hook');
        DataCache::getInstance()->massinvalidate('module-prepare');
        Modules::inject($module, true);

        if ($res) {
            GameLog::log(
                "Module {$module} activated",
                'modules',
                false,
                $session['user']['acctid'] ?? 0
            );
        }

        return $res;
    }

    /**
     * Deactivate a module.
     */
    public static function deactivate(string $module): bool
    {
        global $session;
        $res = Installer::deactivate($module);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module-prepare');

        if ($res) {
            GameLog::log(
                "Module {$module} deactivated",
                'modules',
                false,
                $session['user']['acctid'] ?? 0
            );
        }

        return $res;
    }

    /**
     * Force a module to reinstall.
     */
    public static function reinstall(string $module): bool
    {
        global $session;
        $sql = 'UPDATE ' . Database::prefix('modules') . " SET filemoddate='" . DATETIME_DATEMIN . "' WHERE modulename='" . $module . "'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('hook');
        DataCache::getInstance()->massinvalidate('module-prepare');
        Modules::inject($module, true);
        GameLog::log(
            "Module {$module} reinstalled",
            'modules',
            false,
            $session['user']['acctid'] ?? 0
        );

        return true;
    }

    /**
     * Force remove a module without requiring the module file.
     */
    public static function forceUninstall(string $module): bool
    {
        global $session;
        if (Installer::forceUninstall($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
            DataCache::getInstance()->invalidatedatacache("inject-$module");
            GameLog::log(
                "Module {$module} force-uninstalled",
                'modules',
                false,
                $session['user']['acctid'] ?? 0
            );

            return true;
        }

        return false;
    }
}
