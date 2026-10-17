<?php

declare(strict_types=1);

namespace Lotgd\Modules;

use Lotgd\Modules;

class HookHandler
{
    public static function block(string $moduleName): void
    {
        Modules::block($moduleName);
    }

    public static function unblock(string $moduleName): void
    {
        Modules::unblock($moduleName);
    }

    public static function massPrepare(array $hookNames): bool
    {
        return Modules::massPrepare($hookNames);
    }

    public static function hook(string $hookName, array $args = [], bool $allowInactive = false, $only = false)
    {
        return Modules::hook($hookName, $args, $allowInactive, $only);
    }

    public static function getAllModuleSettings(?string $module = null): array
    {
        return Modules::getAllModuleSettings($module);
    }

    public static function getModuleSetting(string $name, ?string $module = null)
    {
        return Modules::getModuleSetting($name, $module);
    }

    public static function setModuleSetting(string $name, $value, ?string $module = null): void
    {
        Modules::setModuleSetting($name, $value, $module);
    }

    public static function incrementModuleSetting(string $name, $value = 1, ?string $module = null): void
    {
        Modules::incrementModuleSetting($name, $value, $module);
    }

    public static function clearModuleSettings(?string $module = null): void
    {
        Modules::clearModuleSettings($module);
    }

    public static function loadModuleSettings(string $module): void
    {
        Modules::loadModuleSettings($module);
    }

    public static function deleteObjPrefs(string $objtype, $objid): void
    {
        Modules::deleteObjPrefs($objtype, $objid);
    }

    public static function getObjPref(string $type, $objid, string $name, ?string $module = null)
    {
        return Modules::getObjPref($type, $objid, $name, $module);
    }

    public static function setObjPref(string $objtype, $objid, string $name, $value, ?string $module = null): void
    {
        Modules::setObjPref($objtype, $objid, $name, $value, $module);
    }

    public static function incrementObjPref(string $objtype, $objid, string $name, $value = 1, ?string $module = null): void
    {
        Modules::incrementObjPref($objtype, $objid, $name, $value, $module);
    }

    public static function deleteUserPrefs(int $user): void
    {
        Modules::deleteUserPrefs($user);
    }

    public static function getAllModulePrefs(?string $module = null, ?int $user = null): array
    {
        return Modules::getAllModulePrefs($module, $user);
    }

    public static function getModulePref(string $name, ?string $module = null, ?int $user = null)
    {
        return Modules::getModulePref($name, $module, $user);
    }

    public static function setModulePref(string $name, $value, ?string $module = null, ?int $user = null): void
    {
        Modules::setModulePref($name, $value, $module, $user);
    }

    public static function incrementModulePref(string $name, $value = 1, ?string $module = null, ?int $user = null): void
    {
        Modules::incrementModulePref($name, $value, $module, $user);
    }

    public static function clearModulePref(string $name, ?string $module = null, ?int $user = null): void
    {
        Modules::clearModulePref($name, $module, $user);
    }

    public static function loadModulePrefs(string $module, ?int $user = null): void
    {
        Modules::loadModulePrefs($module, $user);
    }

    /**
     * Lifecycle helper: remove all hooks/event hooks for the current module.
     *
     * Automatic upgrades call this before the module's _install() callback;
     * uninstall paths also perform this cleanup. Module installers should
     * declare their desired registrations, not invoke cleanup themselves.
     *
     * @see Modules::wipeHooks()
     */
    public static function wipeHooks(): void
    {
        Modules::wipeHooks();
    }

    /** Register an event hook in _install(); replaces the same module/type. */
    public static function addEventHook(string $type, string $chance): void
    {
        Modules::addEventHook($type, $chance);
    }

    /**
     * Low-level removal used by event registration; not a display control.
     *
     * Uses ModuleManager's current module, not an explicit module argument.
     * Module code should declare desired hooks in _install() and let the
     * automatic upgrade rebuild them, rather than call this directly.
     *
     * @see Modules::dropEventHook()
     */
    public static function dropEventHook(string $type): void
    {
        Modules::dropEventHook($type);
    }

    /**
     * Low-level removal used by registration; not a display control.
     *
     * Permanently deletes a registration for ModuleManager's current module.
     * Module code should register desired hooks in _install(), omitting removed
     * ones for the automatic upgrade to clean up. To suppress output on one
     * page, guard that output in the callback instead of changing registrations.
     * Kept callable for legacy compatibility; no runtime access restriction.
     *
     * @see Modules::dropHook()
     */
    public static function dropHook(string $hookname, $functioncall = false): void
    {
        Modules::dropHook($hookname, $functioncall);
    }

    /** Register a hook in _install() with default priority 50; replaces its match. */
    public static function addHook(string $hookname, $functioncall = false, $whenactive = false): void
    {
        Modules::addHook($hookname, $functioncall, $whenactive);
    }

    /** Register a hook in _install(); replaces the same module/location/callback. */
    public static function addHookPriority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
    {
        Modules::addHookPriority($hookname, $priority, $functioncall, $whenactive);
    }

    public static function semAcquire(): void
    {
        Modules::semAcquire();
    }

    public static function semRelease(): void
    {
        Modules::semRelease();
    }

    public static function collectEvents(string $type, bool $allowinactive = false): array
    {
        return Modules::collectEvents($type, $allowinactive);
    }

    public static function moduleEvents(string $eventtype, int $basechance, ?string $baseLink = null): int
    {
        return Modules::moduleEvents($eventtype, $basechance, $baseLink);
    }

    public static function doEvent(string $type, string $module, bool $allowinactive = false, ?string $baseLink = null): void
    {
        Modules::doEvent($type, $module, $allowinactive, $baseLink);
    }

    public static function eventSort($a, $b)
    {
        return Modules::eventSort($a, $b);
    }

    public static function displayEvents(string $eventtype, $forcescript = false): void
    {
        Modules::displayEvents($eventtype, $forcescript);
    }

    public static function editorNavs(string $like, string $linkprefix): void
    {
        Modules::editorNavs($like, $linkprefix);
    }

    public static function objprefEdit(string $type, string $module, $id): void
    {
        Modules::objprefEdit($type, $module, $id);
    }

    public static function compareVersions($a, $b): int
    {
        return Modules::compareVersions($a, $b);
    }

    public static function getModuleInfo(string $shortname, bool $withDb = true): array
    {
        return Modules::getModuleInfo($shortname, $withDb);
    }
}
