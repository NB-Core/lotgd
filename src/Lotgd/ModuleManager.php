<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Modules\Installer;
use Lotgd\DataCache;
use Lotgd\Modules;

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
        if (function_exists('get_module_install_status')) {
            $status = get_module_install_status(false);
        } else {
            $status = Installer::getInstallStatus(false);
        }
        return $status['uninstalledmodules'] ?? [];
    }

    /**
     * Get the categories of installed modules.
     *
     * @return array<string,int> Mapping of category name to count
     */
    public static function getInstalledCategories(): array
    {
        if (function_exists('get_module_install_status')) {
            $status = get_module_install_status(false);
        } else {
            $status = Installer::getInstallStatus(false);
        }
        return $status['installedcategories'] ?? [];
    }

    /**
     * Install a module.
     *
     * @return bool True on success
     */
    public static function install(string $module): bool
    {
        if (Installer::install($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
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
        if (Installer::uninstall($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
            DataCache::getInstance()->invalidatedatacache("inject-$module");
            return true;
        }
        return false;
    }

    /**
     * Activate a module.
     */
    public static function activate(string $module): bool
    {
        $res = Installer::activate($module);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('hook');
        DataCache::getInstance()->massinvalidate('module-prepare');
        Modules::inject($module, true);
        return $res;
    }

    /**
     * Deactivate a module.
     */
    public static function deactivate(string $module): bool
    {
        $res = Installer::deactivate($module);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('module-prepare');
        return $res;
    }

    /**
     * Force a module to reinstall.
     */
    public static function reinstall(string $module): bool
    {
        $sql = 'UPDATE ' . Database::prefix('modules') . " SET filemoddate='" . DATETIME_DATEMIN . "' WHERE modulename='" . $module . "'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("inject-$module");
        DataCache::getInstance()->massinvalidate('hook');
        DataCache::getInstance()->massinvalidate('module-prepare');
        Modules::inject($module, true);
        return true;
    }

    /**
     * Force remove a module without requiring the module file.
     */
    public static function forceUninstall(string $module): bool
    {
        if (Installer::forceUninstall($module)) {
            DataCache::getInstance()->massinvalidate('hook');
            DataCache::getInstance()->massinvalidate('module-prepare');
            DataCache::getInstance()->invalidatedatacache("inject-$module");
            return true;
        }

        return false;
    }
}
