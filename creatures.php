<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Forms;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// translator ready


// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_CREATURES);

Translator::getInstance()->setSchema("creatures");

//this is a setup where all the creatures are generated.
$creaturetats = array();
$creatureexp = 14;
$creaturegold = 36;
$creaturedefense = 0;
for ($i = 1; $i <= (getsetting('maxlevel', 15) + 4); $i++) {
    //apply algorithmic creature generation.
    $level = $i;
    $creaturehealth = ($i * 10) + ($i - 1) - round(sqrt($i - 1));
    $creatureattack = 1 + ($i - 1) * 2;
    $creaturedefense += ($i % 2 ? 1 : 2);
    if ($i > 1) {
        $creatureexp += round(10 + 1.5 * log($i));
        $creaturegold += 31 * ($i < 4 ? 2 : 1);
        //give lower levels more gold
    }
    $creaturestats[$i] = array(
        'creaturelevel' => $i,
        'creaturehealth' => $creaturehealth,
        'creatureattack' => $creatureattack,
        'creaturedefense' => $creaturedefense,
        'creatureexp' => $creatureexp,
        'creaturegold' => $creaturegold,
        );
}

Header::pageHeader("Creature Editor");

SuperuserNav::render();

$op = Http::get("op");
$subop = Http::get("subop");
if (Http::post('refresh')) {
    Http::set('op', 'add');
    $op = "add";
    $subop = '';
    $refresh = 1; //let them know this is a refresh
    //had to do this as there is no onchange in a form...
} else {
    ($refresh = 0);
}
if ($op == "save") {
    $forest = (int)(Http::post('forest'));
    $grave = (int)(Http::post('graveyard'));
    $id = Http::post('creatureid');
    if (!$id) {
        $id = Http::get("creatureid");
    }
    if ($subop == "") {
        $post = httpallpost();
        $lev = (int)Http::post('creaturelevel');
        if ($id) {
            $sql = "";
            foreach ($post as $key => $val) {
                if (substr($key, 0, 8) == "creature") {
                    $sql .= "$key = '$val', ";
                }
            }
            foreach ($creaturestats[$lev] as $key => $val) {
                if ($post[$key] != "") {
                    continue;
                }
                if ($key != "creaturelevel" && substr($key, 0, 8) == "creature") {
                    $sql .= "$key = \"" . addslashes($val) . "\", ";
                }
            }
            $sql .= " forest='$forest', ";
            $sql .= " graveyard='$grave', ";
            $sql .= " createdby='" . $session['user']['login'] . "' ";
            $sql = "UPDATE " . Database::prefix("creatures") . " SET " . $sql . " WHERE creatureid='$id'";
            $result = Database::query($sql) or $output->output("`\$" . Database::error(LINK) . "`0`n`#$sql`0`n");
        } else {
            $cols = array();
            $vals = array();

            foreach ($post as $key => $val) {
                if (substr($key, 0, 8) == "creature") {
                    array_push($cols, $key);
                    array_push($vals, $val);
                }
            }
            array_push($cols, "forest");
            array_push($vals, $forest);
            array_push($cols, "graveyard");
            array_push($vals, $grave);
            reset($creaturestats[$lev]);
            foreach ($creaturestats[$lev] as $key => $val) {
                if ($post[$key] != "") {
                    continue;
                }
                if ($key != "creaturelevel" && substr($key, 0, 8) == "creature") {
                    array_push($cols, $key);
                    array_push($vals, $val);
                }
            }
            $sql = "INSERT INTO " . Database::prefix("creatures") . " (" . join(",", $cols) . ",createdby) VALUES (\"" . join("\",\"", $vals) . "\",\"" . addslashes($session['user']['login']) . "\")";
            $result = Database::query($sql);
            $id = Database::insertId();
        }
        if ($result) {
            $output->output("`^Creature saved!`0`n");
        } else {
            $output->output("`^Creature `\$not`^ saved!`0`n");
        }
    } elseif ($subop == "module") {
        // Save module settings
        $module = Http::get("module");
        $post = httpallpost();
        foreach ($post as $key => $val) {
            set_module_objpref("creatures", $id, $key, $val, $module);
        }
        $output->output("`^Saved!`0`n");
    }
    // Set the httpget id so that we can do the editor once we save
    Http::set("creatureid", $id, true);
    // Set the httpget op so we drop back into the editor
    Http::set("op", "edit");
}

$op = Http::get('op');
$id = Http::get('creatureid');
if ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("creatures") . " WHERE creatureid = '$id'";
    Database::query($sql);
    if (Database::affectedRows() > 0) {
        $output->output("Creature deleted`n`n");
        module_delete_objprefs('creatures', $id);
    } else {
        $output->output("Creature not deleted: %s", Database::error(LINK));
    }
    $op = "";
    Http::set('op', "");
}
if ($op == "" || $op == "search") {
    $level = (int)Http::get("level");
    if (!$level) {
        $level = 1;
    }
    $q = Http::post("q");
    if ($q) {
        $where = "creaturename LIKE '%$q%' OR creaturecategory LIKE '%$q%' OR creatureweapon LIKE '%$q%' OR creaturelose LIKE '%$q%' OR createdby LIKE '%$q%'";
    } else {
        $where = "creaturelevel='$level'";
    }
    $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE $where ORDER BY creaturelevel,creaturename";
    $result = Database::query($sql);
    // Search form
    $search = Translator::translate("Search");
    $output->rawOutput("<form action='creatures.php?op=search' method='POST'>");
    $output->output("Search by field: ");
    $output->rawOutput("<input name='q' id='q'>");
    $output->rawOutput("<input type='submit' class='button' value='$search'>");
    $output->rawOutput("</form>");
    $output->rawOutput("<script language='JavaScript'>document.getElementById('q').focus();</script>", true);
    Nav::add("", "creatures.php?op=search");

    Nav::add("Levels");
    $sql1 = "SELECT count(creatureid) AS n,creaturelevel FROM " . Database::prefix("creatures") . " group by creaturelevel order by creaturelevel";
    $result1 = Database::query($sql1);
    while ($row = Database::fetchAssoc($result1)) {
        Nav::add(
            array("Level %s: (%s creatures)", $row['creaturelevel'], $row['n']),
            "creatures.php?level={$row['creaturelevel']}"
        );
    }
    Nav::add("Edit");
    Nav::add("Add a creature", "creatures.php?op=add&level=$level");
    $opshead = Translator::translate("Ops");
    $idhead = Translator::translate("ID");
    $name = Translator::translate("Name");
    $lev = Translator::translate("Level");
    $weapon = Translator::translate("Weapon");
    $winmsg = Translator::translate("Win");
    $diemsg = Translator::translate("Die");
    $cat = Translator::translate("Category");
    $script = Translator::translate("Script?");
    $forest_text = Translator::translate("Forest?");
    $graveyard_text = Translator::translate("Graveyard?");
    $author = Translator::translate("Author");
    $edit = Translator::translate("Edit");
    $yes = Translator::translate("Yes");
    $no = Translator::translate("No");
    $confirm = Translator::translate("Are you sure you wish to delete this creature?");
    $del = Translator::translate("Del");

    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'>");
    $output->rawOutput("<td>$opshead</td><td>$idhead</td><td>$name</td><td>$cat</td><td>$lev</td><td>$weapon</td><td>$script</td><td>$winmsg</td><td>$diemsg</td><td>$forest_text</td><td>$graveyard_text</td><td>$author</td></tr>");
    Nav::add("", "creatures.php");
    $i = true;
    while ($row = Database::fetchAssoc($result)) {
        $i = !$i;
        $output->rawOutput("<tr class='" . ($i ? "trdark" : "trlight") . "'>", true);
        $output->rawOutput("<td>[ <a href='creatures.php?op=edit&creatureid={$row['creatureid']}'>");
        $output->outputNotl("%s", $edit);
        $output->rawOutput("</a> | <a href='creatures.php?op=del&creatureid={$row['creatureid']}&level={$row['creaturelevel']}' onClick='return confirm(\"$confirm\");'>");
        $output->outputNotl("%s", $del);
        $output->rawOutput("</a> ]</td><td>");
        Nav::add("", "creatures.php?op=edit&creatureid={$row['creatureid']}");
        Nav::add("", "creatures.php?op=del&creatureid={$row['creatureid']}&level={$row['creaturelevel']}");
        $output->outputNotl("%s", $row['creatureid']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creaturename']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creaturecategory']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creaturelevel']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creatureweapon']);
        $output->rawOutput("</td><td>");
        if ($row['creatureaiscript'] != '') {
            $output->outputNotl($yes);
        } else {
            $output->outputNotl($no);
        }
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creaturewin']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['creaturelose']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", ($row['forest'] ? "Yes" : "No"));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", ($row['graveyard'] ? "Yes" : "No"));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['createdby']);
        $output->rawOutput("</td></tr>");
    }
    $output->rawOutput("</table>");
} else {
    $level = (int)Http::get('level');
    if (!$level) {
        $level = (int)Http::post('level');
    }
    if (!$level) {
        $level = 1;
    }
    if ($op == "edit" || $op == "add") {
        Nav::add("Edit");
        Nav::add("Creature properties", "creatures.php?op=edit&creatureid=$id");
        Nav::add("Add");
        Nav::add("Add Another Creature", "creatures.php?op=add&level=$level");
        module_editor_navs("prefs-creatures", "creatures.php?op=edit&subop=module&creatureid=$id&module=");
        if ($subop == "module") {
            $module = Http::get("module");
            $output->rawOutput("<form action='creatures.php?op=save&subop=module&creatureid=$id&module=$module' method='POST'>");
            module_objpref_edit("creatures", $module, $id);
            $output->rawOutput("</form>");
            Nav::add("", "creatures.php?op=save&subop=module&creatureid=$id&module=$module");
        } else {
            if ($op == "edit" && $id != "") {
                $sql = "SELECT * FROM " . Database::prefix("creatures") . " WHERE creatureid=$id";
                $result = Database::query($sql);
                if (Database::numRows($result) <> 1) {
                    $output->output("`4Error`0, that creature was not found!");
                } else {
                    $row = Database::fetchAssoc($result);
                }
                $level = $row['creaturelevel'];
            } else {
                //check what was posted if this is a refresh, always fill in the base values
                if ($refresh) {
                    $level = (int)Http::post('creaturelevel');
                }
                $row = $creaturestats[$level];
                $posted = array('level','category','weapon','name','win','lose','aiscript','id');
                foreach ($posted as $field) {
                    $row['creature' . $field] = stripslashes(Http::post('creature' . $field));
                }
                if (!$row['creatureid']) {
                    $row['creatureid'] = 0;
                }
                if ($row['creaturelevel'] == "") {
                    $row['creaturelevel'] = $level;
                }
                $row['forest'] = (int)Http::post('forest');
                $row['graveyard'] = (int)Http::post('graveyard');
            }
            //get available scripts
            //(uncached, won't hit there very often
            $dir = "scripts";
            if (is_dir($dir)) {
                if ($opendir = opendir($dir)) {
                    $sort = array();
                    while (($file = readdir($opendir)) !== false) {
                        $names = explode(".", $file);
                        if (isset($names[1]) && $names[1] == "php") {
                            //sorting
                            $sort[] = "," . $names[0] . "," . $names[0];
                        }
                    }
                    sort($sort);
                    $scriptenum = implode("", $sort);
                }
            }
            $scriptenum = ",,none" . $scriptenum;
            $form = array(
                "Creature Properties,title",
                "creatureid" => "Creature id,hidden",
                "creaturelevel" => "Level,range,1," . (getsetting('maxlevel', 15) + 4) . ",1",
                "Note: After changing the level causes please refresh the form to put the new preset stats for that level in,note",
                "creaturecategory" => "Creature Category",
                "creaturename" => "Creature Name",
                "creaturehealth" => "Creature Health",
                "creatureweapon" => "Weapon",
                "creatureexp" => "Creature Experience",
                "Note: Health and Experience of the creature are base values and get modified according to the hook buffbadguy,note",
                "creatureattack" => "Creature Attack",
                "creaturedefense" => "Creature Defense",
                "Note: Both are base values and will be buffed up. Try to make the creature beatable for a 0 DK person too,note",
                "creaturegold" => "Creature Gold carried",
                "Note: Gold will be more or less when fighting suicidally or slumbering,note",
                "creaturewin" => "Win Message",
                "creaturelose" => "Death Message",
                "forest" => "Creature is in forest?,bool",
                "graveyard" => "Creature is in graveyard?,bool",
                "creatureaiscript" => "Creature's A.I.,enum" . $scriptenum,
            );
            $output->rawOutput("<form action='creatures.php?op=save' method='POST'>");
            Forms::showForm($form, $row);
            $refresh = Translator::translate("Refresh");
            $output->rawOutput("<input type='submit' class='button' name='refresh' value='$refresh'>");
            $output->rawOutput("</form>");
            Nav::add("", "creatures.php?op=save");
            if ($row['creatureaiscript'] != '') {
                $scriptfile = "scripts/" . $row['creatureaiscript'] . ".php";
                if (file_exists($scriptfile)) {
                    $output->output("Current Script File Content:`n`n");
                    $output->outputNotl(implode("`n", str_replace(array("`n"), array("``n"), color_sanitize(file($scriptfile)))));
                }
            }
        }
    } else {
        $module = Http::get("module");
        $output->rawOutput("<form action='mounts.php?op=save&subop=module&creatureid=$id&module=$module' method='POST'>");
        module_objpref_edit("creatures", $module, $id);
        $output->rawOutput("</form>");
        Nav::add("", "creatures.php?op=save&subop=module&creatureid=$id&module=$module");
    }
    Nav::add("Navigation");
    Nav::add("Return to the creature editor", "creatures.php?level=$level");
}
Footer::pageFooter();
