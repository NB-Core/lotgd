<?php

declare(strict_types=1);

use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);

use Lotgd\Http;
use Lotgd\Modules;
use Lotgd\Modules\ModuleManager;
use Lotgd\Nav\VillageNav;
use Lotgd\ForcedNavigation;
use Lotgd\DateTime;
use Lotgd\Output;

require_once __DIR__ . "/common.php";
$output = Output::getInstance();

// Legacy Wrappers for Modules
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/modules.php";
require_once __DIR__ . "/lib/villagenav.php";

DateTime::getMicroTime();

// Determine which module should be executed
$module = (string) Http::get('module');

// Load and execute the requested module if it exists and is active
if (Modules::inject($module, Http::get('admin') ? true : false)) {
        $info = Modules::getModuleInfo($module);
    if (!isset($info['allowanonymous'])) {
        $allowanonymous = false;
    } else {
        $allowanonymous = (bool)$info['allowanonymous'];
    }
    if (!isset($info['override_forced_nav'])) {
        $override_forced_nav = false;
    } else {
        $override_forced_nav = (bool)$info['override_forced_nav'];
    }
        // Check for any navigation overrides or login requirements
        ForcedNavigation::doForcedNav($allowanonymous, $override_forced_nav);

        // Execute the module run function and measure execution time
        $starttime = DateTime::getMicroTime();
    $moduleName = ModuleManager::getMostRecentModule();
    $fname = $moduleName . "_run";
    Translator::getInstance()->setSchema("module-$moduleName");
    $fname();
        $endtime = DateTime::getMicroTime();
    if (($endtime - $starttime >= 1.00 && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
        //On a side note, you won't ever see this text. A normal module calls page_footer(), which ends execution here....
        $output->debug("Slow Module (" . round($endtime - $starttime, 2) . "s): $moduleName`n");
        $stats = array (
            "modulename" => $moduleName,
            "date" => date("Y-m-d H:i:s"),
            "duration" => round($endtime - $starttime, 5),
            );
        //remember, this gets only called if you're a user with debug output triggering this!
                Modules::hook("modules_slowmodule", $stats);
    }
    Translator::getInstance()->setSchema();
} else {
        // The requested module was not found; redirect appropriately
        ForcedNavigation::doForcedNav(false, false);

        Translator::getInstance()->setSchema("badnav");

        page_header("Error");
    if ($session['user']['loggedin']) {
            VillageNav::render();
    } else {
        addnav("L?Return to the Login", "index.php");
    }
    $output->output("You are attempting to use a module which is no longer active, or has been uninstalled.");
        page_footer();
}
