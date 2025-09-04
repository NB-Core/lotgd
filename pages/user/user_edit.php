<?php

declare(strict_types=1);

use Lotgd\PlayerFunctions;
use Lotgd\Forms;
use Lotgd\Nav;
use Lotgd\Modules;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

$result = Database::query("SELECT * FROM " . Database::prefix("accounts") . " WHERE acctid=" . (int)$userid);
$row = Database::fetchAssoc($result);
$petition = httpget("returnpetition");
if ($petition != "") {
    $returnpetition = "&returnpetition=$petition";
}
if ($petition != "") {
    Nav::add("Navigation");
    Nav::add("Return to the petition", "viewpetition.php?op=view&id=$petition");
}
    Nav::add("Operations");
Nav::add("View last page hit", "user.php?op=lasthit&userid=$userid", false, true);
Nav::add("Display debug log", "user.php?op=debuglog&userid=$userid$returnpetition");
Nav::add("View user bio", "bio.php?char=" . $row['acctid'] . "&ret=" . urlencode($_SERVER['REQUEST_URI']));
if ($session['user']['superuser'] & SU_EDIT_DONATIONS) {
    Nav::add("Add donation points", "donators.php?op=add1&name=" . rawurlencode($row['login']) . "&ret=" . urlencode($_SERVER['REQUEST_URI']));
}
    Nav::add("", "user.php?op=edit&userid=$userid$returnpetition");
Nav::add("Bans");
Nav::add("Set up ban", "bans.php?op=setupban&userid={$row['acctid']}");
if (httpget("subop") == "") {
    $output->rawOutput("<form action='user.php?op=special&userid=$userid$returnpetition' method='POST'>");
    Nav::add("", "user.php?op=special&userid=$userid$returnpetition");
    $grant = Translator::translateInline("Grant New Day");
    $output->rawOutput("<input type='submit' class='button' name='newday' value='$grant'>");
    $fix = Translator::translateInline("Fix Broken Navs");
    $output->rawOutput("<input type='submit' class='button' name='fixnavs' value='$fix'>");
    $mark = Translator::translateInline("Mark Email As Valid");
    $output->rawOutput("<input type='submit' class='button' name='clearvalidation' value='$mark'>");
    $output->rawOutput("</form>");
        //Show a user's usertable
    $output->rawOutput("<form action='user.php?op=save&userid=$userid$returnpetition' method='POST'>");
    Nav::add("", "user.php?op=save&userid=$userid$returnpetition");
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'>");
    if ($row['loggedin'] == 1 && $row['laston'] > date("Y-m-d H:i:s", strtotime("-" . getsetting("LOGINTIMEOUT", 900) . " seconds"))) {
        $output->outputNotl("`\$");
        $output->rawOutput("<span style='font-size: 20px'>");
        $output->output("`\$Warning:`0");
        $output->rawOutput("</span>");
        $output->output("`\$This user is probably logged in at the moment!`0");
    }
    //Add new composita attack
    $row['totalattack'] = PlayerFunctions::getPlayerAttack($row['acctid']);
    $row['totaldefense'] = PlayerFunctions::getPlayerDefense($row['acctid']);
    //Add the count summary for DKs
    if ($row['dragonkills'] > 0) {
        $row['dragonpointssummary'] = array_count_values(($row['dragonpoints'] > '' ? unserialize($row['dragonpoints']) : array()));
    } else {
        $row['dragonpointssummary'] = array();
    }

    // Okay, munge the display name down to just the players name sans
    // title
    /*careful using this hook! add only things with 'viewonly' in there, nothing will be saved if do otherwise! Example:
    do_hook of your module:
    array_push($args['userinfo'], "Some Stuff to have a look at,title");
    $args['userinfo']['test'] = "The truth!!!,viewonly";
    $args['user']['test'] = "Is out there???";
    */
    $showformargs = modulehook("modifyuserview", array("userinfo" => $userinfo, "user" => $row));
    $info = Forms::showForm($showformargs['userinfo'], $showformargs['user']);
    $output->rawOutput("<input type='hidden' value=\"" . htmlentities(serialize($info), ENT_COMPAT, getsetting("charset", "UTF-8")) . "\" name='oldvalues'>");
    $output->rawOutput("</form>");
        $output->output("`n`nLast Page Viewed:`n");
    $output->rawOutput("<iframe src='user.php?op=lasthit&userid=$userid' width='100%' height='400'>");
    $output->output("You need iframes to view the user's last hit here.");
    $output->output("Use the link in the nav instead.");
    $output->rawOutput("</iframe>");
} elseif (httpget("subop") == "module") {
    //Show a user's prefs for a given module.
    Nav::add("Operations");
    Nav::add("Edit user", "user.php?op=edit&userid=$userid$returnpetition");
    $module = httpget('module');
    $info = get_module_info($module);
    if (count($info['prefs']) > 0) {
        $data = array();
        $msettings = array();
        foreach ($info['prefs'] as $key => $val) {
            // Handle vals which are arrays.
            if (is_array($val)) {
                $v = $val[0];
                $x = explode("|", $v);
                $val[0] = $x[0];
                $x[0] = $val;
            } else {
                $x = explode("|", $val);
            }
            $msettings[$key] = $x[0];
            // Set up the defaults as well.
            if (isset($x[1])) {
                $data[$key] = $x[1];
            }
        }
               $sql = "SELECT * FROM " . Database::prefix("module_userprefs") . " WHERE modulename='" . Database::escape($module) . "' AND userid=" . (int)$userid;
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $data[$row['setting']] = $row['value'];
        }
        $output->rawOutput("<form action='user.php?op=savemodule&module=$module&userid=$userid$returnpetition' method='POST'>");
        Nav::add("", "user.php?op=savemodule&module=$module&userid=$userid$returnpetition");
        Translator::getInstance()->setSchema("module-$module");
        Forms::showForm($msettings, $data);
        Translator::getInstance()->setSchema();
        $output->rawOutput("</form>");
    } else {
        $output->output("The $module module doesn't appear to define any user preferences.");
    }
}
Modules::editorNavs('prefs', "user.php?op=edit&subop=module&userid=$userid$returnpetition&module=");
Nav::add("", "user.php?op=lasthit&userid=$userid");
