<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Buffs;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Lotgd\Settings;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\Output;

// addnews ready
// mail ready
// translator ready

// hilarious copy of mounts.php
require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_MOUNTS);

Translator::getInstance()->setSchema("companions");

Header::pageHeader("Companion Editor");

SuperuserNav::render();

Nav::add("Companion Editor");
Nav::add("Add a companion", "companions.php?op=add");

$op = Http::get('op');
$id = Http::get('id');
if ($op == "deactivate") {
    $sql = "UPDATE " . Database::prefix("companions") . " SET companionactive=0 WHERE companionid='$id'";
    Database::query($sql);
    $op = "";
    Http::set("op", "");
    DataCache::getInstance()->invalidatedatacache("companionsdata-$id");
} elseif ($op == "activate") {
    $sql = "UPDATE " . Database::prefix("companions") . " SET companionactive=1 WHERE companionid='$id'";
    Database::query($sql);
    $op = "";
    Http::set("op", "");
    DataCache::getInstance()->invalidatedatacache("companiondata-$id");
} elseif ($op == "del") {
    //drop the companion.
    $sql = "DELETE FROM " . Database::prefix("companions") . " WHERE companionid='$id'";
    Database::query($sql);
    HookHandler::deleteObjPrefs('companions', $id);
    $op = "";
    Http::set("op", "");
    DataCache::getInstance()->invalidatedatacache("companiondata-$id");
} elseif ($op == "take") {
    $sql = "SELECT * FROM " . Database::prefix("companions") . " WHERE companionid='$id'";
    $result = Database::query($sql);
    if ($row = Database::fetchAssoc($result)) {
        $row['attack'] = $row['attack'] + $row['attackperlevel'] * $session['user']['level'];
        $row['defense'] = $row['defense'] + $row['defenseperlevel'] * $session['user']['level'];
        $row['maxhitpoints'] = $row['maxhitpoints'] + $row['maxhitpointsperlevel'] * $session['user']['level'];
        $row['hitpoints'] = $row['maxhitpoints'];
        $row = HookHandler::moduleHook("alter-companion", $row);
        $row['abilities'] = @unserialize($row['abilities']);
        if (Buffs::applyCompanion($row['name'], $row)) {
            $output->output("`\$Successfully taken `^%s`\$ as companion.", $row['name']);
        } else {
            $output->output("`\$Companion not taken due to global limit.`0");
        }
    }
    $op = "";
    Http::set("op", "");
} elseif ($op == "save") {
    $subop = httpget("subop");
    if ($subop == "") {
        $companion = Http::post('companion');
        if ($companion) {
            if (!isset($companion['allowinshades'])) {
                $companion['allowinshades'] = 0;
            }
            if (!isset($companion['allowinpvp'])) {
                $companion['allowinpvp'] = 0;
            }
            if (!isset($companion['allowintrain'])) {
                $companion['allowintrain'] = 0;
            }
            if (!isset($companion['abilities']['fight'])) {
                $companion['abilities']['fight'] = false;
            }
            if (!isset($companion['abilities']['defend'])) {
                $companion['abilities']['defend'] = false;
            }
            if (!isset($companion['cannotdie'])) {
                $companion['cannotdie'] = false;
            }
            if (!isset($companion['cannotbehealed'])) {
                $companion['cannotbehealed'] = false;
            }
            $sql = "";
            $keys = "";
            $vals = "";
            $i = 0;
            foreach ($companion as $key => $val) {
                if (is_array($val)) {
                    $val = addslashes(serialize($val));
                }
                $sql .= (($i > 0) ? ", " : "") . "$key='$val'";
                $keys .= (($i > 0) ? ", " : "") . "$key";
                $vals .= (($i > 0) ? ", " : "") . "'$val'";
                $i++;
            }
            if ($id > "") {
                $sql = "UPDATE " . Database::prefix("companions") .
                    " SET $sql WHERE companionid='$id'";
            } else {
                $sql = "INSERT INTO " . Database::prefix("companions") .
                    " ($keys) VALUES ($vals)";
            }
            Database::query($sql);
            DataCache::getInstance()->invalidatedatacache("companiondata-$id");
            if (Database::affectedRows() > 0) {
                $output->output("`^Companion saved!`0`n`n");
            } else {
//              if (strlen($sql) > 400) $sql = substr($sql,0,200)." ... ".substr($sql,strlen($sql)-200);
                $output->output("`^Companion `\$not`^ saved: `\$%s`0`n`n", $sql);
            }
        }
    } elseif ($subop == "module") {
        // Save modules settings
        $module = Http::get("module");
        $post = Http::allPost();
        reset($post);
        foreach ($post as $key => $val) {
            HookHandler::setObjPref("companions", $id, $key, $val, $module);
        }
        $output->output("`^Saved!`0`n");
    }
    if ($id) {
        $op = "edit";
    } else {
        $op = "";
    }
    Http::set("op", $op);
}

if ($op == "") {
    $sql = "SELECT * FROM " . Database::prefix("companions") . " ORDER BY category, name";
    $result = Database::query($sql);

    $ops = Translator::translateInline("Ops");
    $name = Translator::translateInline("Name");
    $cost = Translator::translateInline("Cost");

    $edit = Translator::translateInline("Edit");
    $del = Translator::translateInline("Del");
    $take = Translator::translateInline("Take");
    $deac = Translator::translateInline("Deactivate");
    $act = Translator::translateInline("Activate");

    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td nowrap>$ops</td><td>$name</td><td>$cost</td></tr>");
    $cat = "";
    $count = 0;

    while ($row = Database::fetchAssoc($result)) {
        if ($cat != $row['category']) {
            $output->rawOutput("<tr class='trlight'><td colspan='5'>");
            $output->output("Category: %s", $row['category']);
            $output->rawOutput("</td></tr>");
            $cat = $row['category'];
            $count = 0;
        }
        if (isset($companions[$row['companionid']])) {
            $companions[$row['companionid']] = (int)$companions[$row['companionid']];
        } else {
            $companions[$row['companionid']] = 0;
        }
        $output->rawOutput("<tr class='" . ($count % 2 ? "trlight" : "trdark") . "'>");
        $output->rawOutput("<td nowrap>[ <a href='companions.php?op=edit&id={$row['companionid']}'>$edit</a> |");
        Nav::add("", "companions.php?op=edit&id={$row['companionid']}");
        if ($row['companionactive']) {
            $output->rawOutput("$del |");
        } else {
            $mconf = sprintf($conf, $companions[$row['companionid']]);
            $output->rawOutput("<a href='companions.php?op=del&id={$row['companionid']}'>$del</a> |");
            Nav::add("", "companions.php?op=del&id={$row['companionid']}");
        }
        if ($row['companionactive']) {
            $output->rawOutput("<a href='companions.php?op=deactivate&id={$row['companionid']}'>$deac</a> | ");
            Nav::add("", "companions.php?op=deactivate&id={$row['companionid']}");
        } else {
            $output->rawOutput("<a href='companions.php?op=activate&id={$row['companionid']}'>$act</a> | ");
            Nav::add("", "companions.php?op=activate&id={$row['companionid']}");
        }
        $output->rawOutput("<a href='companions.php?op=take&id={$row['companionid']}'>$take</a> ]</td>");
        Nav::add("", "companions.php?op=take&id={$row['companionid']}");
        $output->rawOutput("<td>");
        $output->outputNotl("`&%s`0", $row['name']);
        $output->rawOutput("</td><td>");
        $output->output("`%%s gems`0, `^%s gold`0", $row['companioncostgems'], $row['companioncostgold']);
        $output->rawOutput("</td></tr>");
        $count++;
    }
    $output->rawOutput("</table>");
    $output->output("`nIf you wish to delete a companion, you have to deactivate it first.");
} elseif ($op == "add") {
    $output->output("Add a companion:`n");
    Nav::add("Companion Editor Home", "companions.php");
    companionform(array());
} elseif ($op == "edit") {
    Nav::add("Companion Editor Home", "companions.php");
    $sql = "SELECT * FROM " . Database::prefix("companions") . " WHERE companionid='$id'";
    $result = Database::queryCached($sql, "companiondata-$id", 3600);
    if (Database::numRows($result) <= 0) {
        $output->output("`iThis companion was not found.`i");
    } else {
        Nav::add("Companion properties", "companions.php?op=edit&id=$id");
        HookHandler::editorNavs("prefs-companions", "companions.php?op=edit&subop=module&id=$id&module=");
        $subop = Http::get("subop");
        if ($subop == "module") {
            $module = Http::get("module");
            $output->rawOutput("<form action='companions.php?op=save&subop=module&id=$id&module=$module' method='POST'>");
            HookHandler::objprefEdit("companions", $module, $id);
            $output->rawOutput("</form>");
            Nav::add("", "companions.php?op=save&subop=module&id=$id&module=$module");
        } else {
            $output->output("Companion Editor:`n");
            $row = Database::fetchAssoc($result);
            $row['abilities'] = @unserialize($row['abilities']);
            companionform($row);
        }
    }
}

function companionform($companion)
{
    $output = Output::getInstance();
    // Let's sanitize the data
    if (!isset($companion['companionactive'])) {
        $companion['companionactive'] = "";
    }
    if (!isset($companion['name'])) {
        $companion['name'] = "";
    }
    if (!isset($companion['companionid'])) {
        $companion['companionid'] = "";
    }
    if (!isset($companion['description'])) {
        $companion['description'] = "";
    }
    if (!isset($companion['dyingtext'])) {
        $companion['dyingtext'] = "";
    }
    if (!isset($companion['jointext'])) {
        $companion['jointext'] = "";
    }
    if (!isset($companion['category'])) {
        $companion['category'] = "";
    }
    if (!isset($companion['companionlocation'])) {
        $companion['companionlocation']  = 'all';
    }
    if (!isset($companion['companioncostdks'])) {
        $companion['companioncostdks']  = 0;
    }

    if (!isset($companion['companioncostgems'])) {
        $companion['companioncostgems']  = 0;
    }
    if (!isset($companion['companioncostgold'])) {
        $companion['companioncostgold']  = 0;
    }

    if (!isset($companion['attack'])) {
        $companion['attack'] = "";
    }
    if (!isset($companion['attackperlevel'])) {
        $companion['attackperlevel'] = "";
    }
    if (!isset($companion['defense'])) {
        $companion['defense'] = "";
    }
    if (!isset($companion['defenseperlevel'])) {
        $companion['defenseperlevel'] = "";
    }
    if (!isset($companion['hitpoints'])) {
        $companion['hitpoints'] = "";
    }
    if (!isset($companion['maxhitpoints'])) {
        $companion['maxhitpoints'] = "";
    }
    if (!isset($companion['maxhitpointsperlevel'])) {
        $companion['maxhitpointsperlevel'] = "";
    }

    if (!isset($companion['abilities']['fight'])) {
        $companion['abilities']['fight'] = 0;
    }
    if (!isset($companion['abilities']['defend'])) {
        $companion['abilities']['defend'] =  0;
    }
    if (!isset($companion['abilities']['heal'])) {
        $companion['abilities']['heal'] =  0;
    }
    if (!isset($companion['abilities']['magic'])) {
        $companion['abilities']['magic'] =  0;
    }

    if (!isset($companion['cannotdie'])) {
        $companion['cannotdie'] = 0;
    }
    if (!isset($companion['cannotbehealed'])) {
        $companion['cannotbehealed'] = 1;
    }
    if (!isset($companion['allowinshades'])) {
        $companion['allowinshades'] = 0;
    }
    if (!isset($companion['allowinpvp'])) {
        $companion['allowinpvp'] = 0;
    }
    if (!isset($companion['allowintrain'])) {
        $companion['allowintrain'] = 0;
    }

    $output->rawOutput("<form action='companions.php?op=save&id={$companion['companionid']}' method='POST'>");
    $output->rawOutput("<input type='hidden' name='companion[companionactive]' value=\"" . $companion['companionactive'] . "\">");
    addnav("", "companions.php?op=save&id={$companion['companionid']}");
    $output->rawOutput("<table width='100%'>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Name:");
    $output->rawOutput("</td><td><input name='companion[name]' value=\"" . htmlentities($companion['name'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\" maxlength='50'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Dyingtext:");
    $output->rawOutput("</td><td><input name='companion[dyingtext]' value=\"" . htmlentities($companion['dyingtext'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Description:");
    $output->rawOutput("</td><td><textarea cols='25' rows='5' name='companion[description]'>" . htmlentities($companion['description'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "</textarea></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion join text:");
    $output->rawOutput("</td><td><textarea cols='25' rows='5' name='companion[jointext]'>" . htmlentities($companion['jointext'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "</textarea></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Category:");
    $output->rawOutput("</td><td><input name='companion[category]' value=\"" . htmlentities($companion['category'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\" maxlength='50'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Availability:");
    $output->rawOutput("</td><td nowrap>");
    // Run a modulehook to find out where camps are located.  By default
    // they are located in 'Degolburg' (ie, getgamesetting('villagename'));
    // Some later module can remove them however.
    $vname = Settings::getsetting('villagename', LOCATION_FIELDS);
    $locs = array($vname => Translator::sprintfTranslate("The Village of %s", $vname));
    $locs = HookHandler::hook("camplocs", $locs);
    $locs['all'] = Translator::translateInline("Everywhere");
    ksort($locs);
    reset($locs);
    $output->rawOutput("<select name='companion[companionlocation]'>");
    foreach ($locs as $loc => $name) {
        $output->rawOutput("<option value='$loc'" . ($companion['companionlocation'] == $loc ? " selected" : "") . ">$name</option>");
    }

    $output->rawOutput("<tr><td nowrap>");
    $output->output("Maxhitpoints / Bonus per level:");
    $output->rawOutput("</td><td><input name='companion[maxhitpoints]' value=\"" . htmlentities($companion['maxhitpoints'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"> / <input name='companion[maxhitpointsperlevel]' value=\"" . htmlentities($companion['maxhitpointsperlevel'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Attack / Bonus per level:");
    $output->rawOutput("</td><td><input name='companion[attack]' value=\"" . htmlentities($companion['attack'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"> / <input name='companion[attackperlevel]' value=\"" . htmlentities($companion['attackperlevel'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Defense / Bonus per level:");
    $output->rawOutput("</td><td><input name='companion[defense]' value=\"" . htmlentities($companion['defense'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"> / <input name='companion[defenseperlevel]' value=\"" . htmlentities($companion['defenseperlevel'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");

    $output->rawOutput("<tr><td nowrap>");
    $output->output("Fighter?:");
    $output->rawOutput("</td><td><input id='fighter' type='checkbox' name='companion[abilities][fight]' value='1'" . ($companion['abilities']['fight'] == true ? " checked" : "") . " onClick='document.getElementById(\"defender\").disabled=document.getElementById(\"fighter\").checked; if(document.getElementById(\"defender\").disabled==true) document.getElementById(\"defender\").checked=false;'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Defender?:");
    $output->rawOutput("</td><td><input id='defender' type='checkbox' name='companion[abilities][defend]' value='1'" . ($companion['abilities']['defend'] == true ? " checked" : "") . " onClick='document.getElementById(\"fighter\").disabled=document.getElementById(\"defender\").checked; if(document.getElementById(\"fighter\").disabled==true) document.getElementById(\"fighter\").checked=false;'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Healer level:");
    $output->rawOutput("</td><td valign='top'><select name='companion[abilities][heal]'>");
    for ($i = 0; $i <= 30; $i++) {
        $output->rawOutput("<option value='$i'" . ($companion['abilities']['heal'] == $i ? " selected" : "") . ">$i</option>");
    }
    $output->rawOutput("</select></td></tr>");
    $output->rawOutput("<tr><td colspan='2'>");
    $output->output("`iThis value determines the maximum amount of HP healed per round`i");
    $output->rawOutput("</td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Magician?:");
    $output->rawOutput("</td><td valign='top'><select name='companion[abilities][magic]'>");
    for ($i = 0; $i <= 30; $i++) {
        $output->rawOutput("<option value='$i'" . ($companion['abilities']['magic'] == $i ? " selected" : "") . ">$i</option>");
    }
    $output->rawOutput("</select></td></tr>");
    $output->rawOutput("<tr><td colspan='2'>");
    $output->output("`iThis value determines the maximum amount of damage caused per round`i");
    $output->rawOutput("</td></tr>");

    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion cannot die:");
    $output->rawOutput("</td><td><input type='checkbox' name='companion[cannotdie]' value='1'" . ($companion['cannotdie'] == true ? " checked" : "") . "></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion cannot be healed:");
    $output->rawOutput("</td><td><input type='checkbox' name='companion[cannotbehealed]' value='1'" . ($companion['cannotbehealed'] == true ? " checked" : "") . "></td></tr>");
    $output->rawOutput("<tr><td nowrap>");

    $output->output("Companion Cost (DKs):");
    $output->rawOutput("</td><td><input name='companion[companioncostdks]' value=\"" . htmlentities((int)$companion['companioncostdks'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Cost (Gems):");
    $output->rawOutput("</td><td><input name='companion[companioncostgems]' value=\"" . htmlentities((int)$companion['companioncostgems'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Companion Cost (Gold):");
    $output->rawOutput("</td><td><input name='companion[companioncostgold]' value=\"" . htmlentities((int)$companion['companioncostgold'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Allow in shades:");
    $output->rawOutput("</td><td><input type='checkbox' name='companion[allowinshades]' value='1'" . ($companion['allowinshades'] == true ? " checked" : "") . "></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Allow in PvP:");
    $output->rawOutput("</td><td><input type='checkbox' name='companion[allowinpvp]' value='1'" . ($companion['allowinpvp'] == true ? " checked" : "") . "></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Allow in train:");
    $output->rawOutput("</td><td><input type='checkbox' name='companion[allowintrain]' value='1'" . ($companion['allowintrain'] == true ? " checked" : "") . "></td></tr>");
    $output->rawOutput("</table>");
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'></form>");
}

Footer::pageFooter();
