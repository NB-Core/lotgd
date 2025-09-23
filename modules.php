<?php

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;
use Lotgd\ModuleManager;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Sanitize;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";

$output = Output::getInstance();

SuAccess::check(SU_MANAGE_MODULES);
Translator::getInstance()->setSchema("modulemanage");

Header::pageHeader("Module Manager");

SuperuserNav::render();


Nav::add("", PhpGenericEnvironment::getRequestUri());
$op = Http::get('op');
$module = Http::get('module');

if ($op == 'mass') {
    if (Http::post("activate")) {
        $op = "activate";
    }
    if (Http::post("deactivate")) {
        $op = "deactivate";
    }
    if (Http::post("uninstall")) {
        $op = "uninstall";
    }
    if (Http::post("reinstall")) {
        $op = "reinstall";
    }
    if (Http::post("remove")) {
        $op = "remove";
    }
    if (Http::post("install")) {
        $op = "install";
    }
    $module = Http::post("module");
}
$theOp = $op;
if (is_array($module)) {
    $modules = $module;
} else {
    if ($module) {
        $modules = array($module);
    } else {
        $modules = array();
    }
}
foreach ($modules as $key => $module) {
        $op = $theOp;
        $output->output("`2Performing `^%s`2 on `%%s`0`n", Translator::translateInline($op), $module);
    if ($op == "install") {
        if (!ModuleManager::install($module)) {
                Http::set('cat', '');
                $output->output("`\$Error, module could not be installed!`n`n");
        }
            $op = "";
            Http::set('op', "");
    } elseif ($op == "uninstall") {
        if (!ModuleManager::uninstall($module)) {
                $output->output("`\$Error, module could not be uninstalled!`n`n");
                $output->output("Unable to inject module.  Module not uninstalled.`n");
        }
            $op = "";
            Http::set('op', "");
    } elseif ($op == "activate") {
            ModuleManager::activate($module);
            $op = "";
            Http::set('op', "");
    } elseif ($op == "deactivate") {
            ModuleManager::deactivate($module);
            $op = "";
            Http::set('op', "");
    } elseif ($op == "reinstall") {
            ModuleManager::reinstall($module);
            $op = "";
            Http::set('op', "");
    } elseif ($op == "remove") {
            ModuleManager::forceUninstall($module);
            $op = "";
            Http::set('op', "");
    }
}

$uninstmodules = ModuleManager::listUninstalled();
$seencats = ModuleManager::getInstalledCategories();
$ucount = count($uninstmodules);

Nav::addHeader("Uninstalled");
Nav::add(array(" ?Uninstalled - (%s modules)", $ucount), "modules.php");

Nav::addHeader("Module Categories");
$currentHeader = "Module Categories";
foreach ($seencats as $cat => $count) {
        $category = $cat;
        $headerName   = "Module Categories";
        $subnav   = '';
    if (strpos($cat, "|") !== false) {
            list($headerName, $subnav) = explode("|", $cat, 2);
            $category = $subnav;
    }
    if ($headerName !== $currentHeader) {
            Nav::addHeader($headerName);
            $currentHeader = $headerName;
    }
    if ($subnav !== '') {
            Nav::addSubHeader($subnav);
    }
        Nav::add(array(" ?%s - (%s modules)", $category, $count), "modules.php?cat=$cat");
}

$cat = Http::get('cat');
if ($op == "") {
    if ($cat) {
        $sortby = Http::get('sortby');
        if (!$sortby) {
            $sortby = "installdate";
        }
        $order = Http::get('order');
        $tcat = Translator::translateInline($cat);
        $output->output("`n`b%s Modules`b`n", $tcat);
        $deactivate = Translator::translateInline("Deactivate");
        $activate = Translator::translateInline("Activate");
        $uninstall = Translator::translateInline("Uninstall");
        $reinstall = Translator::translateInline("Reinstall");
        $remove = Translator::translateInline("Remove");
        $removeconfirm = Translator::translateInline("Are you sure you wish to remove this module?  All user preferences and module settings will be lost.");
        $strsettings = Translator::translateInline("Settings");
        $strnosettings = Translator::translateInline("`\$No Settings`0");
        $uninstallconfirm = Translator::translateInline("Are you sure you wish to uninstall this module?  All user preferences and module settings will be lost.  If you wish to temporarily remove access to the module, you may simply deactivate it.");
        $status = Translator::translateInline("Status");
        $mname = Translator::translateInline("Module Name");
        $ops = Translator::translateInline("Ops");
        $mauth = Translator::translateInline("Module Author");
        $inon = Translator::translateInline("Installed On");
        $installstr = Translator::translateInline("by %s");
        $active = Translator::translateInline("`@Active`0");
        $inactive = Translator::translateInline("`\$Inactive`0");
        $output->rawOutput("<form action='modules.php?op=mass&cat=$cat' method='POST'>");
        Nav::add("", "modules.php?op=mass&cat=$cat");
        $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>", true);
        $output->rawOutput("<tr class='trhead'><td>&nbsp;</td><td>$ops</td><td><a href='modules.php?cat=$cat&sortby=active&order=" . ($sortby == "active" ? !$order : 1) . "'>$status</a></td><td><a href='modules.php?cat=$cat&sortby=formalname&order=" . ($sortby == "formalname" ? !$order : 1) . "'>$mname</a></td><td><a href='modules.php?cat=$cat&sortby=moduleauthor&order=" . ($sortby == "moduleauthor" ? !$order : 1) . "'>$mauth</a></td><td><a href='modules.php?cat=$cat&sortby=installdate&order=" . ($sortby == "installdate" ? !$order : 0) . "'>$inon</a></td></tr>");
        Nav::add("", "modules.php?cat=$cat&sortby=active&order=" . ($sortby == "active" ? !$order : 1));
        Nav::add("", "modules.php?cat=$cat&sortby=formalname&order=" . ($sortby == "formalname" ? !$order : 1));
        Nav::add("", "modules.php?cat=$cat&sortby=moduleauthor&order=" . ($sortby == "moduleauthor" ? !$order : 1));
        Nav::add("", "modules.php?cat=$cat&sortby=installdate&order=" . ($sortby == "installdate" ? $order : 0));
                $rows = ModuleManager::listInstalled($cat, $sortby, (bool)$order);
        if (count($rows) == 0) {
                $output->rawOutput("<tr class='trlight'><td colspan='6' align='center'>");
                $output->output("`i-- No Modules Installed--`i");
                $output->rawOutput("</td></tr>");
        }
                $number = count($rows);
        for ($i = 0; $i < $number; $i++) {
                $row = $rows[$i];
            $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>", true);
            $output->rawOutput("<td nowrap valign='top'>");
            $output->rawOutput("<input type='checkbox' name='module[]' value=\"{$row['modulename']}\">");
            $output->rawOutput("</td><td valign='top' nowrap>[ ");
            if ($row['active']) {
                $output->rawOutput("<a href='modules.php?op=deactivate&module={$row['modulename']}&cat=$cat'>");
                $output->outputNotl($deactivate);
                $output->rawOutput("</a>");
                Nav::add("", "modules.php?op=deactivate&module={$row['modulename']}&cat=$cat");
            } else {
                $output->rawOutput("<a href='modules.php?op=activate&module={$row['modulename']}&cat=$cat'>");
                $output->outputNotl($activate);
                $output->rawOutput("</a>");
                Nav::add("", "modules.php?op=activate&module={$row['modulename']}&cat=$cat");
            }
            $output->rawOutput(" |<a href='modules.php?op=uninstall&module={$row['modulename']}&cat=$cat' onClick='return confirm(\"$uninstallconfirm\");'>");
            $output->outputNotl($uninstall);
            $output->rawOutput("</a>");
            Nav::add("", "modules.php?op=uninstall&module={$row['modulename']}&cat=$cat");
            $output->rawOutput(" | <a href='modules.php?op=reinstall&module={$row['modulename']}&cat=$cat'>");
            $output->outputNotl($reinstall);
            $output->rawOutput("</a>");
            Nav::add("", "modules.php?op=reinstall&module={$row['modulename']}&cat=$cat");
            $output->rawOutput(" | <a href='modules.php?op=remove&module={$row['modulename']}&cat=$cat' onClick='return confirm(\"$removeconfirm\");'>");
            $output->outputNotl($remove);
            $output->rawOutput("</a>");
            Nav::add("", "modules.php?op=remove&module={$row['modulename']}&cat=$cat");

            if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
                if (strstr($row['infokeys'], "|settings|")) {
                    $output->rawOutput(" | <a href='configuration.php?op=modulesettings&module={$row['modulename']}'>");
                    $output->outputNotl($strsettings);
                    $output->rawOutput("</a>");
                    Nav::add("", "configuration.php?op=modulesettings&module={$row['modulename']}");
                } else {
                    $output->outputNotl(" | %s", $strnosettings);
                }
            }

            $output->rawOutput(" ]</td><td valign='top'>");
            $output->outputNotl($row['active'] ? $active : $inactive);
            $output->rawOutput("</td><td nowrap valign='top'><span title=\"" .
            (isset($row['description']) && $row['description'] ?
             $row['description'] : Sanitize::sanitize($row['formalname'])) . "\">");
            $output->outputNotl("%s", $row['formalname']);
            $output->rawOutput("<br>");
            $output->outputNotl("(%s) V%s", $row['modulename'], $row['version']);
            $output->rawOutput("</span></td><td valign='top'>");
            $output->outputNotl("`#%s`0", $row['moduleauthor'], true);
            $output->rawOutput("</td><td nowrap valign='top'>");
            $line = sprintf($installstr, $row['installedby']);
            $output->outputNotl("%s", $row['installdate']);
            $output->rawOutput("<br>");
            $output->outputNotl("%s", $line);
            $output->rawOutput("</td></tr>");
        }
        $output->rawOutput("</table><br />");
        $activate = Translator::translateInline("Activate");
        $deactivate = Translator::translateInline("Deactivate");
        $reinstall = Translator::translateInline("Reinstall");
        $uninstall = Translator::translateInline("Uninstall");
        $remove = Translator::translateInline("Remove");
        $output->rawOutput("<input type='submit' name='activate' class='button' value='$activate'>");
        $output->rawOutput("<input type='submit' name='deactivate' class='button' value='$deactivate'>");
        $output->rawOutput("<input type='submit' name='reinstall' class='button' value='$reinstall'>");
        $output->rawOutput("<input type='submit' name='uninstall' class='button' value='$uninstall'>");
        $output->rawOutput("<input type='submit' name='remove' class='button' value='$remove'>");
        $output->rawOutput("</form>");
    } else {
        $sorting = Http::get('sorting');
        if (!$sorting) {
            $sorting = "shortname";
        }
        $order = Http::get('order');
        $output->output("`bUninstalled Modules`b`n");
        $install = Translator::translateInline("Install");
        $mname = Translator::translateInline("Module Name");
        $ops = Translator::translateInline("Ops");
        $mauth = Translator::translateInline("Module Author");
        $categ = Translator::translateInline("Category");
        $fname = Translator::translateInline("Filename");
        $output->rawOutput("<form action='modules.php?op=mass&cat=$cat' method='POST'>");
        Nav::add("", "modules.php?op=mass&cat=$cat");
        $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>", true);
        $output->rawOutput("<tr class='trhead'><td>&nbsp;</td><td>$ops</td><td><a href='modules.php?sorting=name&order=" . ($sorting == "name" ? !$order : 0) . "'>$mname</a></td><td><a href='modules.php?sorting=author&order=" . ($sorting == "author" ? !$order : 0) . "'>$mauth</a></td><td><a href='modules.php?sorting=category&order=" . ($sorting == "category" ? !$order : 0) . "'>$categ</a></td><td><a href='modules.php?sorting=shortname&order=" . ($sorting == "shortname" ? !$order : 0) . "'>$fname</a></td></tr>");
        Nav::add("", "modules.php?sorting=name&order=" . ($sorting == "name" ? !$order : 0));
        Nav::add("", "modules.php?sorting=author&order=" . ($sorting == "author" ? !$order : 0));
        Nav::add("", "modules.php?sorting=category&order=" . ($sorting == "category" ? !$order : 0));
        Nav::add("", "modules.php?sorting=shortname&order=" . ($sorting == "shortname" ? !$order : 0));
        $invalidmodule = array(
            "version" => "",
            "author" => "",
            "category" => "",
            "download" => "",
            "invalid" => true,
        );
        if (count($uninstmodules) > 0) {
            $count = 0;
            $moduleinfo = array();
            $sortby = array();
            $numberarray = array();
            foreach ($uninstmodules as $key => $shortname) {
                //test if the file is a valid module or a lib file/whatever that got in, maybe even malcode that does not have module form
                $file = file_get_contents("modules/$shortname.php");
                if (
                    strpos($file, $shortname . "_getmoduleinfo") === false ||
                    //strpos($file,$shortname."_dohook")===false ||
                    //do_hook is not a necessity
                    strpos($file, $shortname . "_install") === false ||
                    strpos($file, $shortname . "_uninstall") === false
                ) {
                    //here the files has neither do_hook nor getinfo, which means it won't execute as a module here --> block it + notify the admin who is the manage modules section
                    $temp = array_merge($invalidmodule, array("name" => $shortname . ".php " . $output->appoencode(Translator::translateInline("(`\$Invalid Module! Contact Author or check file!`0)"))));
                } else {
                    $temp = get_module_info($shortname);
                }
                //end of testing
                if (!$temp || empty($temp)) {
                    continue;
                }
                $temp['shortname'] = $shortname;
                array_push($moduleinfo, $temp);
                array_push($sortby, full_sanitize($temp[$sorting]));
                array_push($numberarray, $count);
                $count++;
            }
            array_multisort($sortby, ($order ? SORT_DESC : SORT_ASC), $numberarray, ($order ? SORT_DESC : SORT_ASC));
            for ($a = 0; $a < count($moduleinfo); $a++) {
                $i = $numberarray[$a];
                $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
                if (isset($moduleinfo[$i]['invalid']) && $moduleinfo[$i]['invalid'] === true) {
                    $output->rawOutput("<td></td><td nowrap valign='top'>");
                    $output->output("Not installable");
                    $output->rawOutput("</td>");
                } else {
                    $output->rawOutput("<td><input type='checkbox' name='module[]' value='{$moduleinfo[$i]['shortname']}'></td>");
                    $output->rawOutput("<td nowrap valign='top'>");
                    $output->rawOutput("[ <a href='modules.php?op=install&module={$moduleinfo[$i]['shortname']}&cat={$moduleinfo[$i]['category']}'>");
                    $output->outputNotl($install);
                    $output->rawOutput("</a>]</td>");
                    Nav::add("", "modules.php?op=install&module={$moduleinfo[$i]['shortname']}&cat={$moduleinfo[$i]['category']}");
                }
                $output->rawOutput("<td nowrap valign='top'><span title=\"" .
                    (isset($moduleinfo[$i]['description']) &&
                         $moduleinfo[$i]['description'] ?
                     $moduleinfo[$i]['description'] :
                     sanitize($moduleinfo[$i]['name'])) . "\">");
                $output->rawOutput($moduleinfo[$i]['name'] . " " . $moduleinfo[$i]['version']);
                $output->rawOutput("</span></td><td valign='top'>");
                $output->outputNotl("`#%s`0", $moduleinfo[$i]['author'], true);
                $output->rawOutput("</td><td valign='top'>");
                $output->rawOutput($moduleinfo[$i]['category']);
                $output->rawOutput("</td><td valign='top'>");
                $output->rawOutput($moduleinfo[$i]['shortname'] . ".php");
                $output->rawOutput("</td>");
                $output->rawOutput("</tr>");
                if (isset($moduleinfo[$i]['requires']) && is_array($moduleinfo[$i]['requires']) && count($moduleinfo[$i]['requires']) > 0) {
                    $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
                    $output->rawOutput("<td>&nbsp;</td>");
                    $output->rawOutput("<td colspan='6'>");
                    $output->output("`bRequires:`b`n");
                    foreach ($moduleinfo[$i]['requires'] as $key => $val) {
                        $info = explode("|", $val);
                        if (module_check_requirements(array($key => $val))) {
                            $output->outputNotl("`@");
                        } else {
                            $output->outputNotl("`\$");
                        }
                        $output->outputNotl("$key {$info[0]} -- {$info[1]}`n");
                    }
                    $output->rawOutput("</td>");
                    $output->rawOutput("</tr>");
                }
                $count++;
            }
        } else {
            $output->rawOutput("<tr class='trlight'><td colspan='6' align='center'>");
            $output->output("`i--No uninstalled modules were found--`i");
            $output->rawOutput("</td></tr>");
        }
        $output->rawOutput("</table><br />");
        $install = Translator::translateInline("Install");
        $output->rawOutput("<input type='submit' name='install' class='button' value='$install'>");
    }
}

Footer::pageFooter();
