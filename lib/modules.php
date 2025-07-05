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

function module_status(string $modulename, $version = false): int
{
    return Modules::getStatus($modulename, $version);
}

function is_module_active(string $modulename): bool
{
    return Modules::isActive($modulename);
}

function is_module_installed(string $modulename, $version = false): bool
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

function mass_module_prepare($hooknames)
{
    return Modules::massPrepare($hooknames);
}

$currenthook = "";
function modulehook(string $hookname, array $args = array(), bool $allowinactive = false, $only = false)
{
    return Modules::hook($hookname, $args, $allowinactive, $only);
}

$module_settings = array();
function get_all_module_settings($module = false)
{
    return Modules::getAllModuleSettings($module ?: null);
}

function get_module_setting($name, $module = false)
{
    return Modules::getModuleSetting($name, $module ?: null);
}

function set_module_setting($name, $value, $module = false)
{
    Modules::setModuleSetting($name, $value, $module ?: null);
}

function increment_module_setting($name, $value = 1, $module = false)
{
    Modules::incrementModuleSetting($name, $value, $module ?: null);
}

function clear_module_settings($module = false)
{
    Modules::clearModuleSettings($module ?: null);
}

function load_module_settings($module)
{
    Modules::loadModuleSettings($module);
}

function module_delete_objprefs($objtype, $objid)
{
    Modules::deleteObjPrefs($objtype, $objid);
}

function get_module_objpref($type, $objid, $name, $module = false)
{
    return Modules::getObjPref($type, $objid, $name, $module ?: null);
}

function set_module_objpref($objtype, $objid, $name, $value, $module = false)
{
    Modules::setObjPref($objtype, $objid, $name, $value, $module ?: null);
}

function increment_module_objpref($objtype, $objid, $name, $value = 1, $module = false)
{
    Modules::incrementObjPref($objtype, $objid, $name, $value, $module ?: null);
}

function module_delete_userprefs($user)
{
    Modules::deleteUserPrefs($user);
}

$module_prefs = array();
function get_all_module_prefs($module = false, $user = false)
{
    return Modules::getAllModulePrefs($module ?: null, $user !== false ? $user : null);
}

function get_module_pref($name, $module = false, $user = false)
{
    return Modules::getModulePref($name, $module ?: null, $user !== false ? $user : null);
}

function set_module_pref($name, $value, $module = false, $user = false)
{
    Modules::setModulePref($name, $value, $module ?: null, $user !== false ? $user : null);
}

function increment_module_pref($name, $value = 1, $module = false, $user = false)
{
    Modules::incrementModulePref($name, $value, $module ?: null, $user !== false ? $user : null);
}

function clear_module_pref($name, $module = false, $user = false)
{
    Modules::clearModulePref($name, $module ?: null, $user !== false ? $user : null);
}

function load_module_prefs($module, $user = false)
{
    Modules::loadModulePrefs($module, $user !== false ? $user : null);
}

function get_module_info($shortname, $with_db = true)
{
    return Modules::getModuleInfo($shortname, $with_db);
}

function module_wipehooks()
{
    Modules::wipeHooks();
}

function module_addeventhook($type, $chance)
{
    Modules::addEventHook($type, $chance);
}

function module_drophook($hookname, $functioncall = false)
{
    Modules::dropHook($hookname, $functioncall);
}

function module_addhook($hookname, $functioncall = false, $whenactive = false)
{
    Modules::addHook($hookname, $functioncall, $whenactive);
}

function module_addhook_priority($hookname, $priority = 50, $functioncall = false, $whenactive = false)
{
    Modules::addHookPriority($hookname, $priority, $functioncall, $whenactive);
}

function module_sem_acquire()
{
    Modules::semAcquire();
}

function module_sem_release()
{
    Modules::semRelease();
}

function module_collect_events($type, $allowinactive = false)
{
    return Modules::collectEvents($type, $allowinactive);
}

function module_events($eventtype, $basechance, $baseLink = false)
{
    return Modules::moduleEvents($eventtype, $basechance, $baseLink);
}

function module_do_event($type, $module, $allowinactive = false, $baseLink = false)
{
    Modules::doEvent($type, $module, $allowinactive, $baseLink);
}

function event_sort($a, $b)
{
    return Modules::eventSort($a, $b);
}

function module_display_events($eventtype, $forcescript = false)
{
    Modules::displayEvents($eventtype, $forcescript);
}

function module_editor_navs($like, $linkprefix)
{
    Modules::editorNavs($like, $linkprefix);
}

function module_objpref_edit($type, $module, $id)
{
    Modules::objprefEdit($type, $module, $id);
}

function module_compare_versions($a, $b)
{
    return Modules::compareVersions($a, $b);
}

function activate_module($module)
{
    return Modules::activate($module);
}

function deactivate_module($module)
{
    return Modules::deactivate($module);
}

function uninstall_module($module)
{
    return Modules::uninstall($module);
}

function install_module($module, $force = true)
{
    return Modules::install($module, $force);
}

function module_condition($condition)
{
    return Modules::condition($condition);
}

function get_module_install_status($with_db = true)
{
    return Modules::getInstallStatus($with_db);
}

function get_racename($thisuser = true)
{
    return Modules::getRaceName($thisuser);
}
