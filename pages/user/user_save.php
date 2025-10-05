<?php

declare(strict_types=1);

use Lotgd\Names;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\GameLog;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Http;

$fieldUpdates = [];
$updates = 0;
$output = Output::getInstance();
$settings = Settings::getInstance();
$post = Http::allPost();
$oldvalues = Http::post('oldvalues');
$oldvalues = html_entity_decode(
    (string) $oldvalues,
    ENT_COMPAT,
    $settings->getSetting('charset', 'UTF-8')
);
/** @var array|string $oldvalues */
$oldvalues = unserialize($oldvalues);
if (!\is_array($oldvalues)) {
    $oldvalues = [];
}
// Handle recombining the old name
$otitle = $oldvalues['title'] ?? '';
if (isset($oldvalues['ctitle']) && $oldvalues['ctitle']) {
    $otitle = $oldvalues['ctitle'];
}
// now the $ctitle is the real title
//$oldvalues['name'] = $otitle . ' ' . $oldvalues['name'];
if (!isset($oldvalues['playername']) || $oldvalues['playername'] == '') {
    //you need a name, this is normal after an update from <1.1.1+nb
    if (!isset($post['playername']) || $post['playername'] == '') {
        $post['playername'] = Names::getPlayerBasename($oldvalues);
    }
}
// End Naming
$output->outputNotl("`n");
foreach ($post as $key => $val) {
    if (isset($userinfo[$key])) {
        if ($key == "newpassword") {
            if ($val > "") {
                $fieldUpdates['password'] = md5(md5($val));
                $updates++;
                $output->output("`\$Password value has been updated.`0`n");
                debuglog($session['user']['name'] . "`0 changed password to $val", $userid);
                if ($session['user']['acctid'] == $userid) {
                    $session['user']['password'] = md5(md5($val));
                }
            }
        } elseif ($key == "superuser") {
            $value = 0;
            foreach ($val as $k => $v) {
                if ($v) {
                    $value += (int)$k;
                }
            }
                //strip off an attempt to set privs that the user doesn't
            //have authority to set.
            $oldsup = (int)($oldvalues['superuser'] ?? 0);
            $stripfield = ($oldsup | $session['user']['superuser'] | SU_ANYONE_CAN_SET | ($session['user']['superuser'] & SU_MEGAUSER ? 0xFFFFFFFF : 0));
            $value = $value & $stripfield;
                //put back on privs that the user used to have but the
            //current user can't set.
            $unremovable = ~ ((int)$session['user']['superuser'] | SU_ANYONE_CAN_SET | ($session['user']['superuser'] & SU_MEGAUSER ? 0xFFFFFFFF : 0));
            $filteredunremovable = $oldsup & $unremovable;
            $value = $value | $filteredunremovable;
            if ((int)$value != $oldsup) {
                $fieldUpdates[$key] = (int) $value;
                $updates++;
                $output->output("`\$Superuser values have changed.`0`n");
                if ($session['user']['acctid'] == $userid) {
                    $session['user']['superuser'] = $value;
                }
                debuglog($session['user']['name'] . "`0 changed superuser to " . show_bitfield($value), $userid) . "`n";
                debug("superuser has changed to $value");
            }
        } elseif ($key == "name33" && stripslashes($val) != ($oldvalues[$key] ?? '')) {
            $updates++;
            $tmp = sanitize_colorname(
                $settings->getSetting('spaceinname', 0),
                stripslashes($val),
                true
            );
            $tmp = preg_replace("/[`][cHw]/", "", $tmp);
            $tmp = sanitize_html($tmp);
            if ($tmp != stripslashes($val)) {
                $output->output("`\$Illegal characters removed from player name!`0`n");
            }
            if (soap($tmp) != ($tmp)) {
                $output->output("`^The new name doesn't pass the bad word filter!`0");
            }
            debug($tmp);
            $newname = Names::changePlayerName($tmp, $oldvalues);
            debug($newname);
            $fieldUpdates[$key] = $newname;
            $output->output("`2Changed player name to %s`0`n", $newname);
            debuglog($session['user']['name'] . "`0 changed player name to $newname`0", $userid);
            $oldvalues['name'] = $newname;
            if ($session['user']['acctid'] == $userid) {
                $session['user']['name'] = $newname;
            }
        } elseif ($key == "title" && stripslashes($val) != ($oldvalues[$key] ?? '')) {
            $updates++;
            $tmp = sanitize_colorname(true, stripslashes($val), true);
            $tmp = preg_replace("/[`][cHw]/", "", $tmp);
            $tmp = sanitize_html($tmp);
            if ($tmp != stripslashes($val)) {
                $output->output("`\$Illegal characters removed from player title!`0`n");
            }
            if (soap($tmp) != ($tmp)) {
                $output->output("`^The new title doesn't pass the bad word filter!`0");
            }
            $newname = Names::changePlayerTitle($tmp, $oldvalues);
            $fieldUpdates[$key] = $tmp;
            $output->output("Changed player title from %s`0 to %s`0`n", $oldvalues['title'] ?? '', $tmp);
            $oldvalues[$key] = $tmp;
            if (!isset($oldvalues['name']) || $newname != $oldvalues['name']) {
                $fieldUpdates['name'] = $newname;
                $output->output("`2Changed player name to %s`2 due to changed dragonkill title`n", $newname);
                debuglog($session['user']['name'] . "`0 changed player name to $newname`0 due to changed dragonkill title", $userid);
                $oldvalues['name'] = $newname;
                if ($session['user']['acctid'] == $userid) {
                    $session['user']['name'] = $newname;
                }
            }
            if ($session['user']['acctid'] == $userid) {
                $session['user']['title'] = $tmp;
            }
        } elseif ($key == "ctitle" && stripslashes($val) != ($oldvalues[$key] ?? '')) {
            $updates++;
            $tmp = sanitize_colorname(true, stripslashes($val), true);
            $tmp = preg_replace("/[`][cHw]/", "", $tmp);
            $tmp = sanitize_html($tmp);
            if ($tmp != stripslashes($val)) {
                $output->output("`\$Illegal characters removed from custom title!`0`n");
            }
            if (soap($tmp) != ($tmp)) {
                $output->output("`^The new custom title doesn't pass the bad word filter!`0");
            }
            $newname = Names::changePlayerCtitle($tmp, $oldvalues);
            $fieldUpdates[$key] = $tmp;
            $output->output("`2Changed player ctitle from `\$%s`2 to `\$%s`2`n", $oldvalues['ctitle'] ?? '', $tmp);
            $oldvalues[$key] = $tmp;
            if (!isset($oldvalues['name']) || $newname != $oldvalues['name']) {
                $fieldUpdates['name'] = $newname;
                if ((!isset($oldvalues['playername']) || $oldvalues['playername'] == '') && !isset($post['playername'])) {
                    //no valid title currently, add update
                    $post['playername'] = Names::getPlayerBasename($tmp);
                }
                $output->output("`2Changed player name to `\$%s`2 due to changed custom title`n", $newname);
                debuglog($session['user']['name'] . "`0 changed player name to $newname`0 due to changed custom title", $userid);
                $oldvalues['name'] = $newname;
                if ($session['user']['acctid'] == $userid) {
                    $session['user']['name'] = $newname;
                }
            }
            if ($session['user']['acctid'] == $userid) {
                $session['user']['ctitle'] = $tmp;
            }
        } elseif (($key == "playername") && stripslashes($val) != ($oldvalues[$key] ?? '')) {
            $updates++;
            $tmp = sanitize_colorname(true, stripslashes($val), true);
            $tmp = preg_replace("/[`][cHw]/", "", $tmp);
            $tmp = sanitize_html($tmp);
            if ($tmp != stripslashes($val)) {
                $output->output("`\$Illegal characters removed from playername!`0`n");
            }
            if (soap($tmp) != ($tmp)) {
                $output->output("`^The new playername doesn't pass the bad word filter!`0");
            }
            debug($tmp);
            $newname = Names::changePlayerName($tmp, $oldvalues);
            debug($newname);
            $fieldUpdates[$key] = $tmp;
            $output->output("`2Changed player name from `\$%s`2 to `\$%s`2`n", $oldvalues['playername'] ?? '', $tmp);
            $oldvalues[$key] = $tmp;
            if (!isset($oldvalues['name']) || $newname != $oldvalues['name']) {
                $fieldUpdates['name'] = $newname;
                debuglog($session['user']['name'] . "`0 changed player name to $newname`0 due to changed custom title", $userid);
                $oldvalues['name'] = $newname;
                if ($session['user']['acctid'] == $userid) {
                    $session['user']['name'] = $newname;
                }
            }
            if ($session['user']['acctid'] == $userid) {
                $session['user']['playername'] = $tmp;
            }
        } elseif ($key == "oldvalues") {
            //donothing.
        } elseif (!array_key_exists($key, $oldvalues) || $oldvalues[$key] != stripslashes($val)) {
            if ($key == 'name') {
                continue; //well, name is composed now
            }
            $fieldUpdates[$key] = $val;
            $updates++;
            $output->output("`2 Value `\$'%s`2' has changed to '`\$%s`2'.`n", $key, stripslashes($val));
            debuglog($session['user']['name'] . "`0 changed $key from " . ($oldvalues[$key] ?? '') . " to $val", $userid);
            if ($session['user']['acctid'] == $userid) {
                $session['user'][$key] = stripslashes($val);
            }
        }
    }
}
$petition = httpget("returnpetition");
if ($petition != "") {
    Nav::add("", "viewpetition.php?op=view&id=$petition");
}
Nav::add("", "user.php");
if ($updates > 0 && $fieldUpdates !== []) {
    $conn = Database::getDoctrineConnection();

    $sets = [];
    foreach (array_keys($fieldUpdates) as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            continue;
        }

        $sets[] = sprintf('%s = :%s', $conn->quoteIdentifier($column), $column);
    }

    if ($sets === []) {
        $output->output("No fields were changed in the user's record.");
    } else {
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :acctid',
            Database::prefix('accounts'),
            implode(', ', $sets),
            $conn->quoteIdentifier('acctid')
        );

        $params = $fieldUpdates;
        $params['acctid'] = $userid;

        $conn->executeStatement($sql, $params);

        debug(sprintf(
            "Updated %d fields in the user record with:\n%s\nParameters: %s",
            $updates,
            $sql,
            json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
        $output->output("%s fields in the user's record were updated.", $updates);
        GameLog::log(
            'User ' . $session['user']['acctid'] . ' edited ' . $updates . ' fields for user ' . $userid,
            'user management'
        );
    }
} else {
    $output->output("No fields were changed in the user's record.");
}
$op = "edit";
httpset($op, "edit");
