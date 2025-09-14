<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\DateTime;
use Lotgd\Settings;
use Lotgd\Forms;
use Lotgd\Output;
use Lotgd\DataCache;
use Lotgd\Modules\ModuleManager;
use Lotgd\PhpGenericEnvironment;

// translator ready
// addnews ready
// mail ready


require_once __DIR__ . "/common.php";
$previous = Settings::getInstance();
$output = Output::getInstance();
// legacy wrapper removed, instantiate settings directly
$settings_extended = new Settings('settings_extended');
Settings::setInstance($previous);
$GLOBALS['settings'] = $settings = $previous;

SuAccess::check(SU_EDIT_CONFIG);

Translator::getInstance()->setSchema("configuration");

$op = httpget('op');
$module = httpget('module');
$type_setting = httpget('settings');

//standardsettings
switch ($type_setting) {
    case "extended":
        switch ($op) {
            case "save":
                include_once("lib/gamelog.php");
                $post = httpallpost();
                $old = $settings_extended->getArray();
                $current = $settings_extended->getArray();
                foreach ($post as $key => $val) {
                    if ('charset' === $key) {
                        continue;
                    }
                    if (!isset($current[$key]) || (stripslashes($val) != $current[$key])) {
                        if (!isset($old[$key])) {
                            $old[$key] = "";
                        }
                        $hasSaved = $settings_extended->saveSetting($key, stripslashes($val));
                        output("Setting %s to %s (Saved: %s)`n", $key, stripslashes($val), $hasSaved);
                        gamelog("`@Changed core setting (extended)`^$key`@ from `#" . substr($old[$key], 25) . "...`@ to `&" . substr($val, 25) . "...`0", "settings");
                        // Notify every module
                        modulehook(
                            "changesetting",
                            array("module" => "core", "setting" => $key,
                                    "old" => $old[$key],
                            "new" => $val),
                            true
                        );
                    }
                }
                output("`^Extended Settings saved.`0");
                $op = "";
                httpset($op, "");
                break;
        }
        break;
    default:
        switch ($op) {
            case "save":
                include_once("lib/gamelog.php");
                if (
                    (int)httppost('blockdupemail') == 1 &&
                        (int)httppost('requirevalidemail') != 1
                ) {
                    httppostset('requirevalidemail', "1");
                    output("`brequirevalidemail has been set since blockdupemail was set.`b`n");
                }
                if (
                    (int)httppost('requirevalidemail') == 1 &&
                        (int)httppost('requireemail') != 1
                ) {
                    httppostset('requireemail', "1");
                    output("`brequireemail has been set since requirevalidemail was set.`b`n");
                }
                $defsup = httppost("defaultsuperuser");
                if ($defsup != "") {
                    $value = 0;
                    foreach ($defsup as $k => $v) {
                        if ($v) {
                            $value += (int)$k;
                        }
                    }
                    httppostset('defaultsuperuser', $value);
                }
                $tmp = stripslashes(httppost("villagename"));
                if ($tmp && $tmp != getsetting('villagename', LOCATION_FIELDS)) {
                    $output->debug("Updating village name -- moving players");
                    $sql = "UPDATE " . Database::prefix("accounts") . " SET location='" .
                        httppost("villagename") . "' WHERE location='" .
                        addslashes(getsetting('villagename', LOCATION_FIELDS)) . "'";
                    Database::query($sql);
                    if ($session['user']['location'] == getsetting('villagename', LOCATION_FIELDS)) {
                        $session['user']['location'] =
                            stripslashes(httppost('villagename'));
                    }
                }
                $tmp = stripslashes(httppost("innname"));
                if ($tmp && $tmp != getsetting('innname', LOCATION_INN)) {
                    $output->debug("Updating inn name -- moving players");
                    $sql = "UPDATE " . Database::prefix("accounts") . " SET location='" .
                        httppost("innname") . "' WHERE location='" .
                        addslashes(getsetting('innname', LOCATION_INN)) . "'";
                    Database::query($sql);
                    if ($session['user']['location'] == getsetting('innname', LOCATION_INN)) {
                        $session['user']['location'] = stripslashes(httppost('innname'));
                    }
                }
                if (stripslashes(httppost("motditems")) != getsetting('motditems', 5)) {
                    DataCache::getInstance()->invalidatedatacache("motd");
                }
                if (stripslashes(httppost('exp-array')) != getsetting('exp-array', '100,400,1002,1912,3140,4707,6641,8985,11795,15143,19121,23840,29437,36071,43930')) {
                    DataCache::getInstance()->massinvalidate("exp_array_dk");
                }
                $post = httpallpost();

                $old = $settings->getArray();
                $current = $settings->getArray();
                foreach ($post as $key => $val) {
                    if ('charset' === $key) {
                        continue;
                    }
                    if (!isset($current[$key]) || (stripslashes($val) != $current[$key])) {
                        if (!isset($old[$key])) {
                            $old[$key] = "";
                        }
                        // If key and old key have empty content, don't save it
                        if (empty($val) && empty($old[$key])) {
                            continue;
                        }
                        $hasSaved = $settings->saveSetting($key, stripslashes($val));
                        output("Setting %s to %s (Saved: %s)`n", $key, stripslashes($val), $hasSaved ? "`2Yes`0" : "`\$No`0");
                        gamelog("`@Changed core setting `^$key`@ from `#{$old[$key]}`@ to `&$val`0", "settings");
                        // Notify every module
                        modulehook(
                            "changesetting",
                            array("module" => "core", "setting" => $key,
                                    "old" => $old[$key],
                            "new" => $val),
                            true
                        );
                    }
                }
                output("`^Settings saved.`0");
                $op = "";
                httpset($op, "");
                break;

            case "modulesettings":
                include_once("lib/gamelog.php");
                if (injectmodule($module, true)) {
                    $save = httpget('save');
                    if ($save != "") {
                        load_module_settings($module);
                        $module_settings = ModuleManager::settings();
                        $old = $module_settings[$module];
                        $post = httpallpost();
                        $post = modulehook("validatesettings", $post, true, $module);
                        if (isset($post['validation_error'])) {
                            $post['validation_error'] =
                                Translator::translateInline($post['validation_error']);
                            output(
                                "Unable to change settings:`\$%s`0",
                                $post['validation_error']
                            );
                        } else {
                            foreach ($post as $key => $val) {
                                $key = stripslashes($key);
                                $val = stripslashes($val);
                                set_module_setting($key, $val);
                                if (!isset($old[$key]) || $old[$key] != $val) {
                                    output("Setting %s to %s`n", $key, $val);
                                    // Notify modules
                                    $oldval = "";
                                    if (isset($old[$key])) {
                                        $oldval = $old[$key];
                                    }
                                    gamelog("`@Changed module(`5$module`@) setting `^$key`@ from `#$oldval`@ to `&$val`0", "settings");
                                    modulehook(
                                        "changesetting",
                                        array("module" => $module, "setting" => $key,
                                                "old" => $oldval,
                                        "new" => $val),
                                        true
                                    );
                                }
                            }
                            output("`^Module %s settings saved.`0`n", $module);
                        }
                        $save = "";
                        httpset('save', "");
                    }
                    if ($save == "") {
                        $info = get_module_info($module);
                        if (count($info['settings']) > 0) {
                            load_module_settings(ModuleManager::getMostRecentModule());
                            $module_settings = ModuleManager::settings();
                            $msettings = array();
                            foreach ($info['settings'] as $key => $val) {
                                if (is_array($val)) {
                                    $v = $val[0];
                                    $x = explode("|", $v);
                                    $val[0] = $x[0];
                                    $x[0] = $val;
                                } else {
                                    $x = explode("|", $val);
                                }
                                $msettings[$key] = $x[0];
                                if (
                                    !isset($module_settings[ModuleManager::getMostRecentModule()][$key]) &&
                                        isset($x[1])
                                ) {
                                    $module_settings[ModuleManager::getMostRecentModule()][$key] = $x[1];
                                }
                            }
                            $msettings = modulehook("mod-dyn-settings", $msettings);
                            if (is_module_active($module)) {
                                output("This module is currently active: ");
                                $deactivate = Translator::translateInline("Deactivate");
                                rawoutput("<a href='modules.php?op=deactivate&module={$module}&cat={$info['category']}'>");
                                output_notl($deactivate);
                                rawoutput("</a>");
                                addnav("", "modules.php?op=deactivate&module={$module}&cat={$info['category']}");
                            } else {
                                output("This module is currently deactivated: ");
                                $deactivate = Translator::translateInline("Activate");
                                rawoutput("<a href='modules.php?op=activate&module={$module}&cat={$info['category']}'>");
                                output_notl($deactivate);
                                rawoutput("</a>");
                                addnav("", "modules.php?op=activate&module={$module}&cat={$info['category']}");
                            }
                            rawoutput("<form action='configuration.php?op=modulesettings&module=$module&save=1' method='POST'>", true);
                            addnav("", "configuration.php?op=modulesettings&module=$module&save=1");
                            Translator::getInstance()->setSchema("module-$module");
                            Forms::showForm($msettings, $module_settings[ModuleManager::getMostRecentModule()]);
                            Translator::getInstance()->setSchema();
                            rawoutput("</form>", true);
                        } else {
                            output("The %s module does not appear to define any module settings.", $module);
                        }
                    }
                } else {
                    output("I was not able to inject the module %s. Sorry it didn't work out.", htmlentities($module, ENT_COMPAT, getsetting("charset", "UTF-8")));
                }
                break;
        }
}


page_header("Game Settings");
SuperuserNav::render();
addnav("Module Manager", "modules.php");
if ($module) {
    $cat = $info['category'];
    addnav(array("Module Category - `^%s`0", Translator::translateInline($cat)), "modules.php?cat=$cat");
}

addnav("Game Settings");
addnav("Standard settings", "configuration.php");
addnav("Extended settings", "configuration.php?settings=extended");
addnav("", PhpGenericEnvironment::getRequestUri());

//get arrays
require __DIR__ . "/src/Lotgd/Config/configuration.php";
require __DIR__ . "/src/Lotgd/Config/configuration_extended.php";


module_editor_navs('settings', 'configuration.php?op=modulesettings&module=');

switch ($type_setting) {
    case "extended":
        switch ($op) {
            case "":
                $useful_vals = array();

                //this is just a way to check and insert a setting I deem necessary without going through the installer
                foreach ($setup_extended as $key => $val) {
                    $settings_extended->getSetting($key);
                }

                //

                $vals = $settings_extended->getArray() + $useful_vals;

                rawoutput("<form action='configuration.php?settings=extended&op=save' method='POST'>");
                addnav("", "configuration.php?settings=extended&op=save");
                Forms::showForm($setup_extended, $vals);
                rawoutput("</form>");
                break;
        }
        break;
    default:
        switch ($op) {
            case "":
                $enum = "enumpretrans";
                $details = gametimedetails();
                $offset = getsetting("gameoffsetseconds", 0);
                for ($i = 0; $i <= 86400 / getsetting("daysperday", 4); $i += 300) {
                    $off = ($details['realsecstotomorrow'] - ($offset - $i));
                    if ($off < 0) {
                        $off += 86400;
                    }
                    $x = strtotime("+" . $off . " secs");
                    $str = Translator::getInstance()->sprintfTranslate(
                        "In %s at %s (+%s)",
                        reltime($x),
                        date("h:i a", $x),
                        date("H:i", $i)
                    );
                    $enum .= ",$i,$str";
                }
                $output->rawOutput(Translator::clearButton());

                $secstonewday = secondstonextgameday($details);
                $useful_vals = array(
                    "datacachepath" => $DB_DATACACHEPATH,
                    "usedatacache" => $DB_USEDATACACHE,
                    "charset" => getsetting('charset', 'UTF-8'),
                    "defaultsuperuser" => getsetting('defaultsuperuser', 0), // this needs to be there as the showform loads from the database; so a value has to be present if it's not set, and this is a technical field
                    "dayduration" => round(($details['dayduration'] / 60 / 60), 0) . " hours",
                    "databasetype" => "MySQLi",
                    "curgametime" => getgametime(),
                    "curservertime" => date("Y-m-d h:i:s a"),
                    "lastnewday" => date(
                        "h:i:s a",
                        strtotime("-{$details['realsecssofartoday']} seconds")
                    ),
                    "nextnewday" => date(
                        "h:i:s a",
                        strtotime("+{$details['realsecstotomorrow']} seconds")
                    ) . " (" . date("H\\h i\\m s\\s", $secstonewday) . ")"
                );

                //this is just a way to check and insert a setting I deem necessary without going through the installer
                if (getsetting('dpointspercurrencyunit', 100)) {
                }

                //


                $vals = $settings->getArray() + $useful_vals;

                rawoutput("<form action='configuration.php?op=save' method='POST'>");
                addnav("", "configuration.php?op=save");
                Forms::showForm($setup, $vals);
                rawoutput("</form>");
                break;
        }
        break;
}
page_footer();
