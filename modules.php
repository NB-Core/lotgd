<?php

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;

// addnews ready
// translator ready
// mail ready
require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/sanitize.php";
use Lotgd\ModuleManager;
SuAccess::check(SU_MANAGE_MODULES);
Translator::getInstance()->setSchema("modulemanage");

page_header("Module Manager");

SuperuserNav::render();


addnav("", PhpGenericEnvironment::getRequestUri());
$op = httpget('op');
$module = httpget('module');

if ($op == 'mass') {
    if (httppost("activate")) {
        $op = "activate";
    }
    if (httppost("deactivate")) {
        $op = "deactivate";
    }
    if (httppost("uninstall")) {
        $op = "uninstall";
    }
    if (httppost("reinstall")) {
        $op = "reinstall";
    }
    if (httppost("remove")) {
        $op = "remove";
    }
    if (httppost("install")) {
        $op = "install";
    }
    $module = httppost("module");
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
        output("`2Performing `^%s`2 on `%%s`0`n", translate_inline($op), $module);
    if ($op == "install") {
        if (!ModuleManager::install($module)) {
                httpset('cat', '');
                output("`\$Error, module could not be installed!`n`n");
        }
            $op = "";
            httpset('op', "");
    } elseif ($op == "uninstall") {
        if (!ModuleManager::uninstall($module)) {
                output("`\$Error, module could not be uninstalled!`n`n");
                output("Unable to inject module.  Module not uninstalled.`n");
        }
            $op = "";
            httpset('op', "");
    } elseif ($op == "activate") {
            ModuleManager::activate($module);
            $op = "";
            httpset('op', "");
    } elseif ($op == "deactivate") {
            ModuleManager::deactivate($module);
            $op = "";
            httpset('op', "");
    } elseif ($op == "reinstall") {
            ModuleManager::reinstall($module);
            $op = "";
            httpset('op', "");
    } elseif ($op == "remove") {
            ModuleManager::forceUninstall($module);
            $op = "";
            httpset('op', "");
    }
}

$uninstmodules = ModuleManager::listUninstalled();
$seencats = ModuleManager::getInstalledCategories();
$ucount = count($uninstmodules);

addnavheader("Uninstalled");
addnav(array(" ?Uninstalled - (%s modules)", $ucount), "modules.php");

addnavheader("Module Categories");
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
            addnavheader($headerName);
            $currentHeader = $headerName;
    }
    if ($subnav !== '') {
            addnavsubheader($subnav);
    }
        addnav(array(" ?%s - (%s modules)", $category, $count), "modules.php?cat=$cat");
}

$cat = httpget('cat');
if ($op == "") {
    if ($cat) {
        $sortby = httpget('sortby');
        if (!$sortby) {
            $sortby = "installdate";
        }
        $order = httpget('order');
        $tcat = translate_inline($cat);
        output("`n`b%s Modules`b`n", $tcat);
        $deactivate = translate_inline("Deactivate");
        $activate = translate_inline("Activate");
        $uninstall = translate_inline("Uninstall");
        $reinstall = translate_inline("Reinstall");
        $remove = translate_inline("Remove");
        $removeconfirm = translate_inline("Are you sure you wish to remove this module?  All user preferences and module settings will be lost.");
        $strsettings = translate_inline("Settings");
        $strnosettings = translate_inline("`\$No Settings`0");
        $uninstallconfirm = translate_inline("Are you sure you wish to uninstall this module?  All user preferences and module settings will be lost.  If you wish to temporarily remove access to the module, you may simply deactivate it.");
        $status = translate_inline("Status");
        $mname = translate_inline("Module Name");
        $ops = translate_inline("Ops");
        $mauth = translate_inline("Module Author");
        $inon = translate_inline("Installed On");
        $installstr = translate_inline("by %s");
        $active = translate_inline("`@Active`0");
        $inactive = translate_inline("`\$Inactive`0");
        rawoutput("<form action='modules.php?op=mass&cat=$cat' method='POST'>");
        addnav("", "modules.php?op=mass&cat=$cat");
        rawoutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>", true);
        rawoutput("<tr class='trhead'><td>&nbsp;</td><td>$ops</td><td><a href='modules.php?cat=$cat&sortby=active&order=" . ($sortby == "active" ? !$order : 1) . "'>$status</a></td><td><a href='modules.php?cat=$cat&sortby=formalname&order=" . ($sortby == "formalname" ? !$order : 1) . "'>$mname</a></td><td><a href='modules.php?cat=$cat&sortby=moduleauthor&order=" . ($sortby == "moduleauthor" ? !$order : 1) . "'>$mauth</a></td><td><a href='modules.php?cat=$cat&sortby=installdate&order=" . ($sortby == "installdate" ? !$order : 0) . "'>$inon</a></td></tr>");
        addnav("", "modules.php?cat=$cat&sortby=active&order=" . ($sortby == "active" ? !$order : 1));
        addnav("", "modules.php?cat=$cat&sortby=formalname&order=" . ($sortby == "formalname" ? !$order : 1));
        addnav("", "modules.php?cat=$cat&sortby=moduleauthor&order=" . ($sortby == "moduleauthor" ? !$order : 1));
        addnav("", "modules.php?cat=$cat&sortby=installdate&order=" . ($sortby == "installdate" ? $order : 0));
                $rows = ModuleManager::listInstalled($cat, $sortby, (bool)$order);
        if (count($rows) == 0) {
                rawoutput("<tr class='trlight'><td colspan='6' align='center'>");
                output("`i-- No Modules Installed--`i");
                rawoutput("</td></tr>");
        }
                $number = count($rows);
        for ($i = 0; $i < $number; $i++) {
                $row = $rows[$i];
            rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>", true);
            rawoutput("<td nowrap valign='top'>");
            rawoutput("<input type='checkbox' name='module[]' value=\"{$row['modulename']}\">");
            rawoutput("</td><td valign='top' nowrap>[ ");
            if ($row['active']) {
                rawoutput("<a href='modules.php?op=deactivate&module={$row['modulename']}&cat=$cat'>");
                output_notl($deactivate);
                rawoutput("</a>");
                addnav("", "modules.php?op=deactivate&module={$row['modulename']}&cat=$cat");
            } else {
                rawoutput("<a href='modules.php?op=activate&module={$row['modulename']}&cat=$cat'>");
                output_notl($activate);
                rawoutput("</a>");
                addnav("", "modules.php?op=activate&module={$row['modulename']}&cat=$cat");
            }
            rawoutput(" |<a href='modules.php?op=uninstall&module={$row['modulename']}&cat=$cat' onClick='return confirm(\"$uninstallconfirm\");'>");
            output_notl($uninstall);
            rawoutput("</a>");
            addnav("", "modules.php?op=uninstall&module={$row['modulename']}&cat=$cat");
            rawoutput(" | <a href='modules.php?op=reinstall&module={$row['modulename']}&cat=$cat'>");
            output_notl($reinstall);
            rawoutput("</a>");
            addnav("", "modules.php?op=reinstall&module={$row['modulename']}&cat=$cat");
            rawoutput(" | <a href='modules.php?op=remove&module={$row['modulename']}&cat=$cat' onClick='return confirm(\"$removeconfirm\");'>");
            output_notl($remove);
            rawoutput("</a>");
            addnav("", "modules.php?op=remove&module={$row['modulename']}&cat=$cat");

            if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
                if (strstr($row['infokeys'], "|settings|")) {
                    rawoutput(" | <a href='configuration.php?op=modulesettings&module={$row['modulename']}'>");
                    output_notl($strsettings);
                    rawoutput("</a>");
                    addnav("", "configuration.php?op=modulesettings&module={$row['modulename']}");
                } else {
                    output_notl(" | %s", $strnosettings);
                }
            }

            rawoutput(" ]</td><td valign='top'>");
            output_notl($row['active'] ? $active : $inactive);
            require_once __DIR__ . "/lib/sanitize.php";
            rawoutput("</td><td nowrap valign='top'><span title=\"" .
            (isset($row['description']) && $row['description'] ?
             $row['description'] : sanitize($row['formalname'])) . "\">");
            output_notl("%s", $row['formalname']);
            rawoutput("<br>");
            output_notl("(%s) V%s", $row['modulename'], $row['version']);
            rawoutput("</span></td><td valign='top'>");
            output_notl("`#%s`0", $row['moduleauthor'], true);
            rawoutput("</td><td nowrap valign='top'>");
            $line = sprintf($installstr, $row['installedby']);
            output_notl("%s", $row['installdate']);
            rawoutput("<br>");
            output_notl("%s", $line);
            rawoutput("</td></tr>");
        }
        rawoutput("</table><br />");
        $activate = translate_inline("Activate");
        $deactivate = translate_inline("Deactivate");
        $reinstall = translate_inline("Reinstall");
        $uninstall = translate_inline("Uninstall");
        $remove = translate_inline("Remove");
        rawoutput("<input type='submit' name='activate' class='button' value='$activate'>");
        rawoutput("<input type='submit' name='deactivate' class='button' value='$deactivate'>");
        rawoutput("<input type='submit' name='reinstall' class='button' value='$reinstall'>");
        rawoutput("<input type='submit' name='uninstall' class='button' value='$uninstall'>");
        rawoutput("<input type='submit' name='remove' class='button' value='$remove'>");
        rawoutput("</form>");
    } else {
        $sorting = httpget('sorting');
        if (!$sorting) {
            $sorting = "shortname";
        }
        $order = httpget('order');
        output("`bUninstalled Modules`b`n");
        $install = translate_inline("Install");
        $mname = translate_inline("Module Name");
        $ops = translate_inline("Ops");
        $mauth = translate_inline("Module Author");
        $categ = translate_inline("Category");
        $fname = translate_inline("Filename");
        rawoutput("<form action='modules.php?op=mass&cat=$cat' method='POST'>");
        addnav("", "modules.php?op=mass&cat=$cat");
        rawoutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>", true);
        rawoutput("<tr class='trhead'><td>&nbsp;</td><td>$ops</td><td><a href='modules.php?sorting=name&order=" . ($sorting == "name" ? !$order : 0) . "'>$mname</a></td><td><a href='modules.php?sorting=author&order=" . ($sorting == "author" ? !$order : 0) . "'>$mauth</a></td><td><a href='modules.php?sorting=category&order=" . ($sorting == "category" ? !$order : 0) . "'>$categ</a></td><td><a href='modules.php?sorting=shortname&order=" . ($sorting == "shortname" ? !$order : 0) . "'>$fname</a></td></tr>");
        addnav("", "modules.php?sorting=name&order=" . ($sorting == "name" ? !$order : 0));
        addnav("", "modules.php?sorting=author&order=" . ($sorting == "author" ? !$order : 0));
        addnav("", "modules.php?sorting=category&order=" . ($sorting == "category" ? !$order : 0));
        addnav("", "modules.php?sorting=shortname&order=" . ($sorting == "shortname" ? !$order : 0));
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
                    $temp = array_merge($invalidmodule, array("name" => $shortname . ".php " . appoencode(translate_inline("(`\$Invalid Module! Contact Author or check file!`0)"))));
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
                rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
                if (isset($moduleinfo[$i]['invalid']) && $moduleinfo[$i]['invalid'] === true) {
                    rawoutput("<td></td><td nowrap valign='top'>");
                    output("Not installable");
                    rawoutput("</td>");
                } else {
                    rawoutput("<td><input type='checkbox' name='module[]' value='{$moduleinfo[$i]['shortname']}'></td>");
                    rawoutput("<td nowrap valign='top'>");
                    rawoutput("[ <a href='modules.php?op=install&module={$moduleinfo[$i]['shortname']}&cat={$moduleinfo[$i]['category']}'>");
                    output_notl($install);
                    rawoutput("</a>]</td>");
                    addnav("", "modules.php?op=install&module={$moduleinfo[$i]['shortname']}&cat={$moduleinfo[$i]['category']}");
                }
                rawoutput("<td nowrap valign='top'><span title=\"" .
                    (isset($moduleinfo[$i]['description']) &&
                         $moduleinfo[$i]['description'] ?
                     $moduleinfo[$i]['description'] :
                     sanitize($moduleinfo[$i]['name'])) . "\">");
                rawoutput($moduleinfo[$i]['name'] . " " . $moduleinfo[$i]['version']);
                rawoutput("</span></td><td valign='top'>");
                output_notl("`#%s`0", $moduleinfo[$i]['author'], true);
                rawoutput("</td><td valign='top'>");
                rawoutput($moduleinfo[$i]['category']);
                rawoutput("</td><td valign='top'>");
                rawoutput($moduleinfo[$i]['shortname'] . ".php");
                rawoutput("</td>");
                rawoutput("</tr>");
                if (isset($moduleinfo[$i]['requires']) && is_array($moduleinfo[$i]['requires']) && count($moduleinfo[$i]['requires']) > 0) {
                    rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
                    rawoutput("<td>&nbsp;</td>");
                    rawoutput("<td colspan='6'>");
                    output("`bRequires:`b`n");
                    foreach ($moduleinfo[$i]['requires'] as $key => $val) {
                        $info = explode("|", $val);
                        if (module_check_requirements(array($key => $val))) {
                            output_notl("`@");
                        } else {
                            output_notl("`\$");
                        }
                        output_notl("$key {$info[0]} -- {$info[1]}`n");
                    }
                    rawoutput("</td>");
                    rawoutput("</tr>");
                }
                $count++;
            }
        } else {
            rawoutput("<tr class='trlight'><td colspan='6' align='center'>");
            output("`i--No uninstalled modules were found--`i");
            rawoutput("</td></tr>");
        }
        rawoutput("</table><br />");
        $install = translate_inline("Install");
        rawoutput("<input type='submit' name='install' class='button' value='$install'>");
    }
}

page_footer();
