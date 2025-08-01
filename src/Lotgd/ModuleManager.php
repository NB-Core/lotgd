<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Modules\Installer;

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
        $status = get_module_install_status();
        return $status['uninstalledmodules'] ?? [];
    }

    /**
     * Get the categories of installed modules.
     *
     * @return array<string,int> Mapping of category name to count
     */
    public static function getInstalledCategories(): array
    {
        $status = get_module_install_status();
        return $status['installedcategories'] ?? [];
    }

    /**
     * Install a module.
     *
     * @return bool True on success
     */
    public static function install(string $module): bool
    {
        if (install_module($module)) {
            massinvalidate('hook');
            massinvalidate('module-prepare');
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
        if (uninstall_module($module)) {
            massinvalidate('hook');
            massinvalidate('module-prepare');
            invalidatedatacache("inject-$module");
            return true;
        }
        return false;
    }

    /**
     * Activate a module.
     */
    public static function activate(string $module): bool
    {
        $res = activate_module($module);
        invalidatedatacache("inject-$module");
        massinvalidate('hook');
        massinvalidate('module-prepare');
        injectmodule($module, true);
        return $res;
    }

    /**
     * Deactivate a module.
     */
    public static function deactivate(string $module): bool
    {
        $res = deactivate_module($module);
        invalidatedatacache("inject-$module");
        massinvalidate('module-prepare');
        return $res;
    }

    /**
     * Force a module to reinstall.
     */
    public static function reinstall(string $module): bool
    {
        $sql = 'UPDATE ' . Database::prefix('modules') . " SET filemoddate='" . DATETIME_DATEMIN . "' WHERE modulename='" . $module . "'";
        Database::query($sql);
        invalidatedatacache("inject-$module");
        massinvalidate('hook');
        massinvalidate('module-prepare');
        injectmodule($module, true);
        return true;
    }

    /**
     * Force remove a module without requiring the module file.
     */
    public static function forceUninstall(string $module): bool
    {
        if (Installer::forceUninstall($module)) {
            massinvalidate('hook');
            massinvalidate('module-prepare');
            invalidatedatacache("inject-$module");
            return true;
        }

        return false;
    }
}
