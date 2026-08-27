<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
// addnews ready
// mail ready
// translator ready
use Lotgd\Output;
use Doctrine\DBAL\ParameterType;

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();

$connection = Database::getDoctrineConnection();
$opValue = Http::get('op');
$op = is_string($opValue) ? $opValue : '';
$id = mountEditorPositiveInteger(Http::get('id'));

if ($op == "xml") {
    SuAccess::check(SU_EDIT_MOUNTS);
    header("Content-Type: text/xml");
    if ($id === null) {
        echo '<xml><name name="' . Translator::translate('NONE') . '"/></xml>';
        exit();
    }
    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE hashorse = :mountId";
    $rows = $connection->executeQuery(
        $sql,
        ['mountId' => $id],
        ['mountId' => ParameterType::INTEGER]
    )->fetchAllAssociative();
    echo("<xml>");
    foreach ($rows as $row) {
        echo("<name name=\"");
        echo(urlencode(appoencode("`0{$row['name']}")));
        echo("\"/>");
    }
    if ($rows === []) {
        echo("<name name=\"" . Translator::translate("NONE") . "\"/>");
    }
    echo("</xml>");
    exit();
}


SuAccess::check(SU_EDIT_MOUNTS);

if (!isset($session['mount_editor_csrf']) || !is_string($session['mount_editor_csrf'])) {
    $session['mount_editor_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $session['mount_editor_csrf'];

if (in_array($op, ['activate', 'deactivate', 'del', 'give', 'save'], true)) {
    $postedToken = Http::post('csrf_token');
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {
        error_log('Denied mount editor state change: invalid method or CSRF token');
        $op = '';
        Http::set('op', '');
    } else {
        $postedId = Http::post('id');
        $id = mountEditorPositiveInteger($postedId);
        $isNewRecord = $op === 'save' && ($postedId === 0 || $postedId === '0');
        if ($id === null && !$isNewRecord) {
            error_log('Denied mount editor state change: invalid record identifier');
            $op = '';
            Http::set('op', '');
        }
    }
}

Translator::getInstance()->setSchema("mounts");

Header::pageHeader("Mount Editor");

SuperuserNav::render();

Nav::add("Mount Editor");
Nav::add("Add a mount", "mounts.php?op=add");

if ($op == "deactivate") {
    $connection->executeStatement(
        "UPDATE " . Database::prefix("mounts") . " SET mountactive = :active WHERE mountid = :mountId",
        ['active' => 0, 'mountId' => $id],
        ['active' => ParameterType::INTEGER, 'mountId' => ParameterType::INTEGER]
    );
    $op = "";
    Http::set("op", "");
    invalidatedatacache("mountdata-$id");
} elseif ($op == "activate") {
    $connection->executeStatement(
        "UPDATE " . Database::prefix("mounts") . " SET mountactive = :active WHERE mountid = :mountId",
        ['active' => 1, 'mountId' => $id],
        ['active' => ParameterType::INTEGER, 'mountId' => ParameterType::INTEGER]
    );
    $op = "";
    Http::set("op", "");
    invalidatedatacache("mountdata-$id");
} elseif ($op == "del") {
    //refund for anyone who has a mount of this type.
    $sql = "SELECT mountid, mountcostgems, mountcostgold FROM " . Database::prefix("mounts") . " WHERE mountid = :mountId";
    $row = $connection->executeQuery($sql, ['mountId' => $id], ['mountId' => ParameterType::INTEGER])->fetchAssociative();
    if ($row === false) {
        $op = '';
    } else {
        $ownedMountId = mountEditorPositiveInteger($row['mountid']);
        $refundGems = mountEditorNonNegativeInteger($row['mountcostgems']);
        $refundGold = mountEditorNonNegativeInteger($row['mountcostgold']);
        if ($ownedMountId === null) {
            error_log('Denied mount refund: stored mount identifier was invalid');
        } else {
            $connection->executeStatement(
                "UPDATE " . Database::prefix("accounts") . " SET gems = gems + :gems, goldinbank = goldinbank + :gold, hashorse = :none WHERE hashorse = :mountId",
                ['gems' => $refundGems, 'gold' => $refundGold, 'none' => 0, 'mountId' => $ownedMountId],
                ['gems' => ParameterType::INTEGER, 'gold' => ParameterType::INTEGER, 'none' => ParameterType::INTEGER, 'mountId' => ParameterType::INTEGER]
            );
        }
    }
    //drop the mount.
    $connection->executeStatement(
        "DELETE FROM " . Database::prefix("mounts") . " WHERE mountid = :mountId",
        ['mountId' => $id],
        ['mountId' => ParameterType::INTEGER]
    );
    module_delete_objprefs('mounts', $id);
    $op = "";
    Http::set("op", "");
    invalidatedatacache("mountdata-$id");
} elseif ($op == "give") {
    if ($id === null) {
        $op = '';
    } else {
        $session['user']['hashorse'] = $id;
    }
    // changed to make use of the cached query
    $sql = "SELECT * FROM " . Database::prefix("mounts") . " WHERE mountid = :mountId";
    $row = $connection->executeQuery($sql, ['mountId' => $id], ['mountId' => ParameterType::INTEGER])->fetchAssociative();
    if ($row === false) {
        error_log('Denied mount give action: mount record was not found');
        $op = '';
        Http::set('op', '');
    } else {
        $buff = unserialize($row['mountbuff']);
    }
    if (!isset($buff) || !is_array($buff)) {
        $buff = [];
    }
    if (($buff['schema'] ?? '') == "") {
        $buff['schema'] = "mounts";
    }
    Buffs::applyBuff("mount", $buff);
    $op = "";
    Http::set("op", "");
} elseif ($op == "save") {
    $subop = Http::get("subop");
    if ($subop == "") {
        $buff = array();
        $mount = Http::post('mount');
        if (is_array($mount) && isset($mount['mountbuff']) && is_array($mount['mountbuff'])) {
            reset($mount['mountbuff']);
            foreach ($mount['mountbuff'] as $key => $val) {
                if (!is_string($key) || !is_string($val)) {
                    error_log('Denied mount save: malformed structured buff value');
                    goto mount_save_finished;
                }
                if ($val > "") {
                    $buff[$key] = stripslashes($val);
                }
            }
            $buff['schema'] = "mounts";
            httppostset('mount', $buff, 'mountbuff');

            [$columns, $placeholders, $parameters] = postparse(false, 'mount');
            if ($columns !== []) {
                $boundParameters = [];
                $boundTypes = [];
                $namedPlaceholders = [];
                $numericColumns = ['mountactive', 'mountdkcost', 'mountcostgems', 'mountcostgold', 'mountfeedcost', 'mountforestfights'];
                $validParameters = true;
                foreach (array_values($parameters) as $index => $parameter) {
                    $name = 'field' . $index;
                    $namedPlaceholders[] = ':' . $name;
                    if (in_array($columns[$index], $numericColumns, true)) {
                        $numericValue = mountEditorNullableNonNegativeInteger($parameter);
                        if ($numericValue === null) {
                            $validParameters = false;
                            break;
                        }
                        $boundParameters[$name] = $numericValue;
                        $boundTypes[$name] = ParameterType::INTEGER;
                    } elseif (is_string($parameter)) {
                        $boundParameters[$name] = $parameter;
                        $boundTypes[$name] = ParameterType::STRING;
                    } else {
                        $validParameters = false;
                        break;
                    }
                }
                if (!$validParameters) {
                    error_log('Denied mount save: malformed field value');
                    $op = $id === null ? '' : 'edit';
                    Http::set('op', $op);
                }
                if ($validParameters && $id !== null) {
                    $assignments = [];
                    foreach ($columns as $index => $column) {
                        $assignments[] = "$column = " . $namedPlaceholders[$index];
                    }
                    $sql = "UPDATE " . Database::prefix("mounts") .
                        " SET " . implode(',', $assignments) . " WHERE mountid = :mountId";
                    $boundParameters['mountId'] = $id;
                    $boundTypes['mountId'] = ParameterType::INTEGER;
                } elseif ($validParameters) {
                    $sql = "INSERT INTO " . Database::prefix("mounts") .
                        " (" . implode(',', $columns) . ") VALUES (" . implode(',', $namedPlaceholders) . ")";
                }
                if ($validParameters) {
                    $affected = $connection->executeStatement($sql, $boundParameters, $boundTypes);
                    invalidatedatacache("mountdata-$id");
                    if ($affected > 0) {
                        $output->output("`^Mount saved!`0`n");
                    } else {
                        $output->output("`^Mount `\$not`^ saved: `\$%s`0`n", $sql);
                    }
                }
            }
        }
        mount_save_finished:
    } elseif ($subop == "module") {
        // Save modules settings
        $module = Http::get("module");
        $post = httpallpost();
        unset($post['showFormTabIndex']);
        foreach ($post as $key => $val) {
            set_module_objpref("mounts", $id, $key, $val, $module);
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
    $sql = "SELECT count(acctid) AS c, hashorse FROM " . Database::prefix("accounts") . " GROUP BY hashorse";
    $result = Database::query($sql);
    $mounts = array();
    while ($row = Database::fetchAssoc($result)) {
        $mounts[$row['hashorse']] = $row['c'];
    }
    $output->rawOutput("<script language='JavaScript'>
	function getUserInfo(id,divid){
		var filename='mounts.php?op=xml&id='+id;
		var xmldom;
		if (document.implementation && document.implementation.createDocument){
			// Mozilla
			xmldom = document.implementation.createDocument('','',null);
		} else if (window.ActiveXObject) {
			// IE
			xmldom = new ActiveXObject('Microsoft.XMLDOM');
		}
		xmldom.async=false;
		xmldom.load(filename);
		var output='';
		for (var x=0; x<xmldom.documentElement.childNodes.length; x++) {
			output = output + unescape(xmldom.documentElement.childNodes[x].getAttribute('name').replace(/\\+/g, ' ')) + '<br />';
		}
		document.getElementById('mountusers'+divid).innerHTML=output;
	}
	</script>");

    $sql = "SELECT * FROM " . Database::prefix("mounts") . " ORDER BY mountcategory, mountcostgems, mountcostgold";
    $ops = Translator::translateInline("Ops");
    $name = Translator::translateInline("Name");
    $cost = Translator::translateInline("Cost");
    $feat = Translator::translateInline("Features");
    $owners = Translator::translateInline("Owners");

    $edit = Translator::translateInline("Edit");
    $give = Translator::translateInline("Give");
    $del = Translator::translateInline("Del");
    $deac = Translator::translateInline("Deactivate");
    $act = Translator::translateInline("Activate");

    $conf = Translator::translateInline("There are %s user(s) who own this mount, are you sure you wish to delete it?");

    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td nowrap>$ops</td><td>$name</td><td>$cost</td><td>$feat</td><td nowrap>$owners</td></tr>");
    $result = Database::query($sql);
    $cat = "";
    $count = 0;

    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        if ($cat != $row['mountcategory']) {
            $output->rawOutput("<tr class='trlight'><td colspan='5'>");
            $output->output("Category: %s", $row['mountcategory']);
            $output->rawOutput("</td></tr>");
            $cat = $row['mountcategory'];
            $count = 0;
        }
        if (isset($mounts[$row['mountid']])) {
            $mounts[$row['mountid']] = (int)$mounts[$row['mountid']];
        } else {
            $mounts[$row['mountid']] = 0;
        }
        $output->rawOutput("<tr class='" . ($count % 2 ? "trlight" : "trdark") . "'>");
        $output->rawOutput("<td nowrap>[ <a href='mounts.php?op=edit&id={$row['mountid']}'>$edit</a> |");
        Nav::add("", "mounts.php?op=edit&id={$row['mountid']}");
        $output->rawOutput(mountEditorActionForm('give', (int) $row['mountid'], $give, $csrfToken) . ' |', true);
        if ($row['mountactive']) {
            $output->rawOutput("$del |");
        } else {
            $mconf = sprintf($conf, $mounts[$row['mountid']]);
            $mconfJs = json_encode($mconf, JSON_HEX_APOS | JSON_HEX_QUOT);
            $output->rawOutput(mountEditorActionForm('del', (int) $row['mountid'], $del, $csrfToken, $mconfJs) . ' |');
        }
        if ($row['mountactive']) {
            $output->rawOutput(mountEditorActionForm('deactivate', (int) $row['mountid'], $deac, $csrfToken) . ' ]</td>');
        } else {
            $output->rawOutput(mountEditorActionForm('activate', (int) $row['mountid'], $act, $csrfToken) . ' ]</td>');
        }
        $output->rawOutput("<td>");
        $output->outputNotl("`&%s`0", $row['mountname']);
        $output->rawOutput("</td><td>");
        $output->output("`%%s gems`0, `^%s gold`0", $row['mountcostgems'], $row['mountcostgold']);
        $output->rawOutput("</td><td>");
        $features = array("FF" => $row['mountforestfights'],"DKs" => $row['mountdkcost']);
        $args = array("id" => $row['mountid'],"features" => &$features);
        $args = HookHandler::hook("mountfeatures", $args);
        reset($features);
        $mcount = 1;
        $max = count($features);
        foreach ($features as $fname => $fval) {
            $fname = Translator::translateInline($fname);
            $output->outputNotl("%s: %s%s", $fname, $fval, ($mcount == $max ? "" : ", "));
            $mcount++;
        }
        $output->rawOutput("</td><td nowrap>");
        $file = "mounts.php?op=xml&id=" . $row['mountid'];
        $output->rawOutput("<div id='mountusers$i'><a href='$file' target='_blank' onClick=\"getUserInfo('" . $row['mountid'] . "', $i); return false\">");
        $output->outputNotl("`#%s`0", $mounts[$row['mountid']]);
        Nav::add("", $file);
        $output->rawOutput("</a></div>");
        $output->rawOutput("</td></tr>");
        $count++;
    }
    $output->rawOutput("</table>");
    $output->output("`nIf you wish to delete a mount, you have to deactivate it first.");
    $output->output("If there are any owners of the mount when it is deleted, they will no longer have a mount, but they will get a FULL refund of the price of the mount at the time of deletion.");
} elseif ($op == "add") {
    $output->output("Add a mount:`n");
    Nav::add("Mount Editor Home", "mounts.php");
    mountform(array());
} elseif ($op == "edit") {
    Nav::add("Mount Editor Home", "mounts.php");
    $sql = "SELECT * FROM " . Database::prefix("mounts") . " WHERE mountid = :mountId";
    $row = $id === null ? false : $connection->executeQuery(
        $sql,
        ['mountId' => $id],
        ['mountId' => ParameterType::INTEGER]
    )->fetchAssociative();
    if ($row === false) {
        $output->output("`iThis mount was not found.`i");
    } else {
        Nav::add("Mount properties", "mounts.php?op=edit&id=$id");
        module_editor_navs("prefs-mounts", "mounts.php?op=edit&subop=module&id=$id&module=");
        $subop = Http::get("subop");
        if ($subop == "module") {
            $module = Http::get("module");
            $output->rawOutput("<form action='mounts.php?op=save&subop=module&module=" . rawurlencode((string) $module) . "' method='POST'>");
            $output->rawOutput("<input type='hidden' name='id' value='$id'><input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . "'>");
            module_objpref_edit("mounts", $module, $id);
            $output->rawOutput("</form>");
            Nav::add("", "mounts.php?op=save&subop=module&id=$id&module=$module");
        } else {
            $output->output("Mount Editor:`n");
            $row['mountbuff'] = unserialize($row['mountbuff']);
            mountform($row);
        }
    }
}

function mountform($mount)
{
    global $session;

    $output = Output::getInstance();
    $settings = Settings::getInstance();

    // Let's sanitize the data
    if (!isset($mount['mountname'])) {
        $mount['mountname'] = "";
    }
    if (!isset($mount['mountid'])) {
        $mount['mountid'] = "";
    }
    if (!isset($mount['mountdesc'])) {
        $mount['mountdesc'] = "";
    }
    if (!isset($mount['mountcategory'])) {
        $mount['mountcategory'] = "";
    }
    if (!isset($mount['mountlocation'])) {
        $mount['mountlocation']  = 'all';
    }
    if (!isset($mount['mountdkcost'])) {
        $mount['mountdkcost']  = 0;
    }
    if (!isset($mount['mountcostgems'])) {
        $mount['mountcostgems']  = 0;
    }
    if (!isset($mount['mountcostgold'])) {
        $mount['mountcostgold']  = 0;
    }
    if (!isset($mount['mountfeedcost'])) {
        $mount['mountfeedcost']  = 0;
    }
    if (!isset($mount['mountforestfights'])) {
        $mount['mountforestfights']  = 0;
    }
    if (!isset($mount['newday'])) {
        $mount['newday']  = "";
    }
    if (!isset($mount['recharge'])) {
        $mount['recharge']  = "";
    }
    if (!isset($mount['partrecharge'])) {
        $mount['partrecharge']  = "";
    }
    if (!isset($mount['mountbuff'])) {
        $mount['mountbuff'] = array();
    }
    if (!isset($mount['mountactive'])) {
        $mount['mountactive'] = 0;
    }
    if (!isset($mount['mountbuff']['name'])) {
        $mount['mountbuff']['name'] = "";
    }
    if (!isset($mount['mountbuff']['roundmsg'])) {
        $mount['mountbuff']['roundmsg'] = "";
    }
    if (!isset($mount['mountbuff']['wearoff'])) {
        $mount['mountbuff']['wearoff'] = "";
    }
    if (!isset($mount['mountbuff']['effectmsg'])) {
        $mount['mountbuff']['effectmsg'] = "";
    }
    if (!isset($mount['mountbuff']['effectnodmgmsg'])) {
        $mount['mountbuff']['effectnodmgmsg'] = "";
    }
    if (!isset($mount['mountbuff']['effectfailmsg'])) {
        $mount['mountbuff']['effectfailmsg'] = "";
    }
    if (!isset($mount['mountbuff']['rounds'])) {
        $mount['mountbuff']['rounds'] = 0;
    }
    if (!isset($mount['mountbuff']['atkmod'])) {
        $mount['mountbuff']['atkmod'] = "";
    }
    if (!isset($mount['mountbuff']['defmod'])) {
        $mount['mountbuff']['defmod'] = "";
    }
    if (!isset($mount['mountbuff']['invulnerable'])) {
        $mount['mountbuff']['invulnerable'] = "";
    }
    if (!isset($mount['mountbuff']['regen'])) {
        $mount['mountbuff']['regen'] = "";
    }
    if (!isset($mount['mountbuff']['minioncount'])) {
        $mount['mountbuff']['minioncount'] = "";
    }
    if (!isset($mount['mountbuff']['minbadguydamage'])) {
        $mount['mountbuff']['minbadguydamage'] = "";
    }
    if (!isset($mount['mountbuff']['maxbadguydamage'])) {
        $mount['mountbuff']['maxbadguydamage'] = "";
    }

    if (!isset($mount['mountbuff']['mingoodguydamage'])) {
        $mount['mountbuff']['mingoodguydamage'] = "";
    }
    if (!isset($mount['mountbuff']['maxgoodguydamage'])) {
        $mount['mountbuff']['maxgoodguydamage'] = "";
    }
    if (!isset($mount['mountbuff']['lifetap'])) {
        $mount['mountbuff']['lifetap'] = "";
    }
    if (!isset($mount['mountbuff']['damageshield'])) {
        $mount['mountbuff']['damageshield'] = "";
    }
    if (!isset($mount['mountbuff']['badguydmgmod'])) {
        $mount['mountbuff']['badguydmgmod'] = "";
    }
    if (!isset($mount['mountbuff']['badguyatkmod'])) {
        $mount['mountbuff']['badguyatkmod'] = "";
    }
    if (!isset($mount['mountbuff']['badguydefmod'])) {
        $mount['mountbuff']['badguydefmod'] = "";
    }

    $output->rawOutput("<form action='mounts.php?op=save' method='POST'>");
    $output->rawOutput("<input type='hidden' name='id' value='" . (int) $mount['mountid'] . "'>");
    $output->rawOutput("<input type='hidden' name='csrf_token' value='" . htmlspecialchars($session['mount_editor_csrf'], ENT_QUOTES, 'UTF-8') . "'>");
    $output->rawOutput("<input type='hidden' name='mount[mountactive]' value=\"" . $mount['mountactive'] . "\">");
    Nav::add("", "mounts.php?op=save&id={$mount['mountid']}");
    $output->rawOutput("<table>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Name:");
    $output->rawOutput("</td><td><input name='mount[mountname]' value=\"" . htmlentities($mount['mountname'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Description:");
    $output->rawOutput("</td><td><input name='mount[mountdesc]' value=\"" . htmlentities($mount['mountdesc'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Category:");
    $output->rawOutput("</td><td><input name='mount[mountcategory]' value=\"" . htmlentities($mount['mountcategory'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
        $output->rawOutput("<label for='mount_location'>");
        $output->output("Mount Availability:");
        $output->rawOutput("</label></td><td nowrap>");
    // Run a modulehook to find out where stables are located.  By default
    // they are located in 'Degolburg' (ie, getgamesetting('villagename'));
    // Some later module can remove them however.
    $vname = $settings->getSetting('villagename', LOCATION_FIELDS);
    $locs = array($vname => Translator::getInstance()->sprintfTranslate("The Village of %s", $vname));
    $locs = HookHandler::hook("stablelocs", $locs);
    $locs['all'] = Translator::translate("Everywhere");
    ksort($locs);
    reset($locs);
        $output->rawOutput("<select name='mount[mountlocation]' id='mount_location'>");
    foreach ($locs as $loc => $name) {
        $output->rawOutput("<option value='$loc'" . ($mount['mountlocation'] == $loc ? " selected" : "") . ">$name</option>");
    }

    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Cost (DKs):");
    $output->rawOutput("</td><td><input name='mount[mountdkcost]' value=\"" . htmlentities((string)($mount['mountdkcost'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Cost (Gems):");
    $output->rawOutput("</td><td><input name='mount[mountcostgems]' value=\"" . htmlentities((string)($mount['mountcostgems'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Cost (Gold):");
    $output->rawOutput("</td><td><input name='mount[mountcostgold]' value=\"" . htmlentities((string)($mount['mountcostgold'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Mount Feed Cost`n(Gold per level):");
    $output->rawOutput("</td><td><input name='mount[mountfeedcost]' value=\"" . htmlentities((string)($mount['mountfeedcost'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\"></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Delta Forest Fights:");
    $output->rawOutput("</td><td><input name='mount[mountforestfights]' value=\"" . htmlentities((string)($mount['mountforestfights'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='5'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("`bMount Messages:`b");
    $output->rawOutput("</td><td></td></tr><tr><td nowrap>");
    $output->output("New Day:");
    $output->rawOutput("</td><td><input name='mount[newday]' value=\"" . htmlentities($mount['newday'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='40'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Full Recharge:");
    $output->rawOutput("</td><td><input name='mount[recharge]' value=\"" . htmlentities($mount['recharge'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='40'></td></tr>");
    $output->rawOutput("<tr><td nowrap>");
    $output->output("Partial Recharge:");
    $output->rawOutput("</td><td><input name='mount[partrecharge]' value=\"" . htmlentities($mount['partrecharge'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='40'></td></tr>");
    $output->rawOutput("<tr><td valign='top' nowrap>");
    $output->output("Mount Buff:");
    $output->rawOutput("</td><td>");
    $output->output("Buff name:");
    $output->rawOutput("<input name='mount[mountbuff][name]' value=\"" . htmlentities($mount['mountbuff']['name'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("`bBuff Messages:`b`n");
    $output->output("Each round:");
    $output->rawOutput("<input name='mount[mountbuff][roundmsg]' value=\"" . htmlentities($mount['mountbuff']['roundmsg'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Wear off:");
    $output->rawOutput("<input name='mount[mountbuff][wearoff]' value=\"" . htmlentities($mount['mountbuff']['wearoff'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Effect:");
    $output->rawOutput("<input name='mount[mountbuff][effectmsg]' value=\"" . htmlentities($mount['mountbuff']['effectmsg'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Effect No Damage:");
    $output->rawOutput("<input name='mount[mountbuff][effectnodmgmsg]' value=\"" . htmlentities($mount['mountbuff']['effectnodmgmsg'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Effect Fail:");
    $output->rawOutput("<input name='mount[mountbuff][effectfailmsg]' value=\"" . htmlentities($mount['mountbuff']['effectfailmsg'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("(message replacements: {badguy}, {goodguy}, {weapon}, {armor}, {creatureweapon}, and where applicable {damage}.)`n");
    $output->output("`n`bEffects:`b`n");
    $output->output("Rounds to last (from new day):");
    $output->rawOutput("<input name='mount[mountbuff][rounds]' value=\"" . htmlentities((string)($mount['mountbuff']['rounds'] ?? ''), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Player Atk mod:");
    $output->rawOutput("<input name='mount[mountbuff][atkmod]' value=\"" . htmlentities($mount['mountbuff']['atkmod'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Player Def mod:");
    $output->rawOutput("<input name='mount[mountbuff][defmod]' value=\"" . htmlentities($mount['mountbuff']['defmod'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Player is invulnerable (1 = yes, 0 = no):");
    $output->rawOutput("<input name='mount[mountbuff][invulnerable]' value=\"" . htmlentities($mount['mountbuff']['invulnerable'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size=50><br/>");
    $output->output("Regen:");
    $output->rawOutput("<input name='mount[mountbuff][regen]' value=\"" . htmlentities($mount['mountbuff']['regen'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Minion Count:");
    $output->rawOutput("<input name='mount[mountbuff][minioncount]' value=\"" . htmlentities($mount['mountbuff']['minioncount'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");

    $output->output("Min Badguy Damage:");
    $output->rawOutput("<input name='mount[mountbuff][minbadguydamage]' value=\"" . htmlentities($mount['mountbuff']['minbadguydamage'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Max Badguy Damage:");
    $output->rawOutput("<input name='mount[mountbuff][maxbadguydamage]' value=\"" . htmlentities($mount['mountbuff']['maxbadguydamage'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Min Goodguy Damage:");
    $output->rawOutput("<input name='mount[mountbuff][mingoodguydamage]' value=\"" . htmlentities($mount['mountbuff']['mingoodguydamage'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");
    $output->output("Max Goodguy Damage:");
    $output->rawOutput("<input name='mount[mountbuff][maxgoodguydamage]' value=\"" . htmlentities($mount['mountbuff']['maxgoodguydamage'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'><br/>");

    $output->output("Lifetap:");
    $output->rawOutput("<input name='mount[mountbuff][lifetap]' value=\"" . htmlentities($mount['mountbuff']['lifetap'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Damage shield:");
    $output->rawOutput("<input name='mount[mountbuff][damageshield]' value=\"" . htmlentities($mount['mountbuff']['damageshield'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Badguy Damage mod:");
    $output->rawOutput("<input name='mount[mountbuff][badguydmgmod]' value=\"" . htmlentities($mount['mountbuff']['badguydmgmod'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Badguy Atk mod:");
    $output->rawOutput("<input name='mount[mountbuff][badguyatkmod]' value=\"" . htmlentities($mount['mountbuff']['badguyatkmod'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("Badguy Def mod:");
    $output->rawOutput("<input name='mount[mountbuff][badguydefmod]' value=\"" . htmlentities($mount['mountbuff']['badguydefmod'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" size='50'>");
    $output->output("(multiplier)`n");
    $output->output("`bOn Dynamic Buffs`b`n");
    $output->output("`@In the above, for most fields, you can choose to enter valid PHP code, substituting <fieldname> for fields in the user's account table.`n");
    $output->output("Examples of code you might enter:`n");
    $output->output("`^<charm>`n");
    $output->output("round(<maxhitpoints>/10)`n");
    $output->output("round(<level>/max(<gems>,1))`n");
    $output->output("`@Fields you might be interested in for this: `n");
    $output->outputNotl("`3name, sex `7(0=male 1=female)`3, specialty `7(DA=darkarts MP=mystical TS=thief)`3,`n");
    $output->outputNotl("experience, gold, weapon `7(name)`3, armor `7(name)`3, level,`n");
    $output->outputNotl("defense, attack, alive, goldinbank,`n");
    $output->outputNotl("spirits `7(-2 to +2 or -6 for resurrection)`3, hitpoints, maxhitpoints, gems,`n");
    $output->outputNotl("weaponvalue `7(gold value)`3, armorvalue `7(gold value)`3, turns, title, weapondmg, armordef,`n");
    $output->outputNotl("age `7(days since last DK)`3, charm, playerfights, dragonkills, resurrections `7(times died since last DK)`3,`n");
    $output->outputNotl("soulpoints, gravefights, deathpower `7(%s favor)`3,`n", $settings->getSetting('deathoverlord', '`$Ramius'));
    $output->outputNotl("race, dragonage, bestdragonage`n`n");
    $output->output("You can also use module preferences by using <modulename|preference> (for instance '<specialtymystic|uses>' or '<drinks|drunkeness>'`n`n");
    $output->output("`@Finally, starting a field with 'debug:' will enable debug output for that field to help you locate errors in your implementation.");
    $output->output("While testing new buffs, you should be sure to debug fields before you release them on the world, as the PHP script will otherwise throw errors to the user if you have any, and this can break the site at various spots (as in places that redirects should happen).");
    $output->rawOutput("</td></tr></table>");
    $save = Translator::translate("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'></form>");
}

Footer::pageFooter();

/**
 * Normalize a scalar decimal request value to a positive integer, or null when invalid.
 */
function mountEditorPositiveInteger(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);

    return $validated === false ? null : $validated;
}

/**
 * Normalize a database scalar to a non-negative integer, returning zero when invalid.
 */
function mountEditorNonNegativeInteger(mixed $value): int
{
    if (!is_int($value) && !is_string($value)) {
        return 0;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);

    return $validated === false ? 0 : $validated;
}

/**
 * Normalize a scalar decimal request value to a non-negative integer, or null when invalid.
 */
function mountEditorNullableNonNegativeInteger(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);

    return $validated === false ? null : $validated;
}

/**
 * Render a POST-only state-change control containing the editor CSRF token.
 */
function mountEditorActionForm(string $operation, int $id, string $label, string $csrfToken, string|false $confirmation = false): string
{
    $operation = htmlspecialchars($operation, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $csrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $confirm = $confirmation === false ? '' : ' onclick="return confirm(' . $confirmation . ');"';

    return "<form action='mounts.php?op={$operation}' method='POST' style='display:inline'>"
        . "<input type='hidden' name='id' value='{$id}'>"
        . "<input type='hidden' name='csrf_token' value='{$csrfToken}'>"
        . "<button type='submit' class='button'{$confirm}>{$label}</button></form>";
}
