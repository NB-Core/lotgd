<?php

declare(strict_types=1);

use Lotgd\Forms;
use Lotgd\Translator;
use Lotgd\Modules;
use Lotgd\Modules\Installer;
use Lotgd\Modules\HookHandler;

require_once "lib/arraytourl.php";

// Wrapper functions providing BC for legacy modules

function injectmodule(string $modulename, bool $force = false, bool $with_db = true): bool
{
    return Modules::inject($modulename, $force, $with_db);
}

function module_status(string $modulename, string|false $version = false): int
{
    return Modules::getStatus($modulename, $version);
}

function is_module_active(string $modulename): bool
{
    return Modules::isActive($modulename);
}

function is_module_installed(string $modulename, string|false $version = false): bool
{
    return Modules::isInstalled($modulename, $version);
}

function module_check_requirements(array $reqs, bool $forceinject = false): bool
{
    return Modules::checkRequirements($reqs, $forceinject);
}

function blockmodule(string $modulename): void
{
    HookHandler::block($modulename);
}

function unblockmodule(string $modulename): void
{
    HookHandler::unblock($modulename);
}

function mass_module_prepare(array $hooknames): bool
{
    if ([] === $hooknames) {
        return true;
    }

    return HookHandler::massPrepare($hooknames);
}

function modulehook(string $hookname, array $args = [], bool $allowinactive = false, $only = false): array
{
    return HookHandler::hook($hookname, $args, $allowinactive, $only);
}

function get_all_module_settings(?string $module = null): array
{
    return HookHandler::getAllModuleSettings($module);
}

function get_module_setting(string $name, ?string $module = null): mixed
{
    return HookHandler::getModuleSetting($name, $module);
}

function set_module_setting(string $name, mixed $value, ?string $module = null): void
{
    HookHandler::setModuleSetting($name, $value, $module);
}

function increment_module_setting(string $name, int|float $value = 1, ?string $module = null): void
{
    HookHandler::incrementModuleSetting($name, $value, $module);
}

function clear_module_settings(?string $module = null): void
{
    HookHandler::clearModuleSettings($module);
}

function load_module_settings(string $module): void
{
    HookHandler::loadModuleSettings($module);
}

function module_delete_objprefs(string $objtype, $objid): void
{
    HookHandler::deleteObjPrefs($objtype, $objid);
}

function get_module_objpref(string $type, $objid, string $name, ?string $module = null)
{
    return HookHandler::getObjPref($type, $objid, $name, $module);
}

function set_module_objpref(string $objtype, $objid, string $name, mixed $value, ?string $module = null): void
{
    HookHandler::setObjPref($objtype, $objid, $name, $value, $module);
}

function increment_module_objpref(string $objtype, $objid, string $name, int|float $value = 1, ?string $module = null): void
{
    HookHandler::incrementObjPref($objtype, $objid, $name, $value, $module);
}

function module_delete_userprefs(int $user): void
{
    HookHandler::deleteUserPrefs($user);
}

function get_all_module_prefs(?string $module = null, ?int $user = null): array
{
    return HookHandler::getAllModulePrefs($module, $user);
}

function get_module_pref(string $name, ?string $module = null, ?int $user = null)
{
    // Breaking Possible Change: Old was "false" for $user, now it's null, I'll fix that for you here, but you need to fix it in your module_addeventhook
    if ($user === false) {
        $user = null;
    }
    return HookHandler::getModulePref($name, $module, $user);
}

function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void
{
    // Breaking Possible Change: Old was "false" for $user, now it's null, I'll fix that for you here, but you need to fix it in your module_addeventhook
    if ($user === false) {
        $user = null;
    }
    HookHandler::setModulePref($name, $value, $module, $user);
}

function increment_module_pref(string $name, int|float $value = 1, ?string $module = null, ?int $user = null): void
{
    // Breaking Possible Change: Old was "false" for $user, now it's null, I'll fix that for you here, but you need to fix it in your module_addeventhook
    if ($user === false) {
        $user = null;
    }
    HookHandler::incrementModulePref($name, $value, $module, $user);
}

function clear_module_pref(string $name, ?string $module = null, ?int $user = null): void
{
    HookHandler::clearModulePref($name, $module, $user);
}

function load_module_prefs(string $module, ?int $user = null): void
{
    HookHandler::loadModulePrefs($module, $user);
}

function get_module_info(string $shortname, bool $with_db = true): array
{
    $shortname = modulename_sanitize($shortname);
    return Modules::getModuleInfo($shortname, $with_db);
}

function module_wipehooks(): void
{
    HookHandler::wipeHooks();
}

function module_addeventhook(string $type, string $chance): void
{
    HookHandler::addEventHook($type, $chance);
}

function module_dropeventhook(string $type): void
{
    HookHandler::dropEventHook($type);
}

function module_drophook(string $hookname, $functioncall = false): void
{
    HookHandler::dropHook($hookname, $functioncall);
}

function module_addhook(string $hookname, $functioncall = false, $whenactive = false): void
{
    HookHandler::addHook($hookname, $functioncall, $whenactive);
}

function module_addhook_priority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
{
    HookHandler::addHookPriority($hookname, $priority, $functioncall, $whenactive);
}

function module_sem_acquire(): void
{
    HookHandler::semAcquire();
}

function module_sem_release(): void
{
    HookHandler::semRelease();
}

function module_collect_events(string $type, bool $allowinactive = false): array
{
    return HookHandler::collectEvents($type, $allowinactive);
}

function module_events(string $eventtype, int $basechance, ?string $baseLink = null): int
{
    return HookHandler::moduleEvents($eventtype, $basechance, $baseLink);
}

function module_do_event(string $type, string $module, bool $allowinactive = false, ?string $baseLink = null): void
{
    HookHandler::doEvent($type, $module, $allowinactive, $baseLink);
}

function event_sort($a, $b): int
{
    return HookHandler::eventSort($a, $b);
}

function module_display_events(string $eventtype, $forcescript = false): void
{
    HookHandler::displayEvents($eventtype, $forcescript);
}

function module_editor_navs(string $like, string $linkprefix): void
{
    HookHandler::editorNavs($like, $linkprefix);
}

function module_objpref_edit(string $type, string $module, $id): void
{
    HookHandler::objprefEdit($type, $module, $id);
}

function module_compare_versions($a, $b): int
{
    return HookHandler::compareVersions($a, $b);
}

function activate_module(string $module): bool
{
    return Installer::activate($module);
}

function deactivate_module(string $module): bool
{
    return Installer::deactivate($module);
}

function uninstall_module(string $module): bool
{
    return Installer::uninstall($module);
}

function install_module(string $module, bool $force = true): bool
{
    return Installer::install($module, $force);
}

function module_condition(string $condition): bool
{
    return Installer::condition($condition);
}

function get_module_install_status(bool $with_db = true): array
{
    return Installer::getInstallStatus($with_db);
}

function get_racename($thisuser = true): string
{
    return Modules::getRaceName($thisuser);
}
