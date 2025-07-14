<?php
declare(strict_types=1);

use Lotgd\Forms;
use Lotgd\Translator;
use Lotgd\Modules;

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
    Modules::block($modulename);
}

function unblockmodule(string $modulename): void
{
    Modules::unblock($modulename);
}

function mass_module_prepare(array $hooknames): bool
{
    return Modules::massPrepare($hooknames);
}

$currenthook = "";
function modulehook(string $hookname, array $args = [], bool $allowinactive = false, $only = false): array
{
    return Modules::hook($hookname, $args, $allowinactive, $only);
}

$module_settings = array();
function get_all_module_settings(?string $module = null): array
{
    return Modules::getAllModuleSettings($module);
}

function get_module_setting(string $name, ?string $module = null): mixed
{
    return Modules::getModuleSetting($name, $module);
}

function set_module_setting(string $name, mixed $value, ?string $module = null): void
{
    Modules::setModuleSetting($name, $value, $module);
}

function increment_module_setting(string $name, int|float $value = 1, ?string $module = null): void
{
    Modules::incrementModuleSetting($name, $value, $module);
}

function clear_module_settings(?string $module = null): void
{
    Modules::clearModuleSettings($module);
}

function load_module_settings(string $module): void
{
    Modules::loadModuleSettings($module);
}

function module_delete_objprefs(string $objtype, $objid): void
{
    Modules::deleteObjPrefs($objtype, $objid);
}

function get_module_objpref(string $type, $objid, string $name, ?string $module = null)
{
    return Modules::getObjPref($type, $objid, $name, $module);
}

function set_module_objpref(string $objtype, $objid, string $name, mixed $value, ?string $module = null): void
{
    Modules::setObjPref($objtype, $objid, $name, $value, $module);
}

function increment_module_objpref(string $objtype, $objid, string $name, int|float $value = 1, ?string $module = null): void
{
    Modules::incrementObjPref($objtype, $objid, $name, $value, $module);
}

function module_delete_userprefs(int $user): void
{
    Modules::deleteUserPrefs($user);
}

$module_prefs = array();
function get_all_module_prefs(?string $module = null, $user = null): array
{
    return Modules::getAllModulePrefs($module, $user);
}

function get_module_pref(string $name, ?string $module = null, $user = null)
{
    return Modules::getModulePref($name, $module, $user);
}

function set_module_pref(string $name, mixed $value, ?string $module = null, $user = null): void
{
    Modules::setModulePref($name, $value, $module, $user);
}

function increment_module_pref(string $name, int|float $value = 1, ?string $module = null, $user = null): void
{
    Modules::incrementModulePref($name, $value, $module, $user);
}

function clear_module_pref(string $name, ?string $module = null, $user = null): void
{
    Modules::clearModulePref($name, $module, $user);
}

function load_module_prefs(string $module, $user = null): void
{
    Modules::loadModulePrefs($module, $user);
}

function get_module_info(string $shortname, bool $with_db = true): array
{
    return Modules::getModuleInfo($shortname, $with_db);
}

function module_wipehooks(): void
{
    Modules::wipeHooks();
}

function module_addeventhook(string $type, string $chance): void
{
    Modules::addEventHook($type, $chance);
}

function module_drophook(string $hookname, $functioncall = false): void
{
    Modules::dropHook($hookname, $functioncall);
}

function module_addhook(string $hookname, $functioncall = false, $whenactive = false): void
{
    Modules::addHook($hookname, $functioncall, $whenactive);
}

function module_addhook_priority(string $hookname, int $priority = 50, $functioncall = false, $whenactive = false): void
{
    Modules::addHookPriority($hookname, $priority, $functioncall, $whenactive);
}

function module_sem_acquire(): void
{
    Modules::semAcquire();
}

function module_sem_release(): void
{
    Modules::semRelease();
}

function module_collect_events(string $type, bool $allowinactive = false): array
{
    return Modules::collectEvents($type, $allowinactive);
}

function module_events(string $eventtype, int $basechance, ?string $baseLink = null): int
{
    return Modules::moduleEvents($eventtype, $basechance, $baseLink);
}

function module_do_event(string $type, string $module, bool $allowinactive = false, ?string $baseLink = null): void
{
    Modules::doEvent($type, $module, $allowinactive, $baseLink);
}

function event_sort($a, $b): int
{
    return Modules::eventSort($a, $b);
}

function module_display_events(string $eventtype, $forcescript = false): void
{
    Modules::displayEvents($eventtype, $forcescript);
}

function module_editor_navs(string $like, string $linkprefix): void
{
    Modules::editorNavs($like, $linkprefix);
}

function module_objpref_edit(string $type, string $module, $id): void
{
    Modules::objprefEdit($type, $module, $id);
}

function module_compare_versions($a, $b): int
{
    return Modules::compareVersions($a, $b);
}

function activate_module(string $module): bool
{
    return Modules::activate($module);
}

function deactivate_module(string $module): bool
{
    return Modules::deactivate($module);
}

function uninstall_module(string $module): bool
{
    return Modules::uninstall($module);
}

function install_module(string $module, bool $force = true): bool
{
    return Modules::install($module, $force);
}

function module_condition(string $condition): bool
{
    return Modules::condition($condition);
}

function get_module_install_status(bool $with_db = true): array
{
    return Modules::getInstallStatus($with_db);
}

function get_racename($thisuser = true): string
{
    return Modules::getRaceName($thisuser);
}
