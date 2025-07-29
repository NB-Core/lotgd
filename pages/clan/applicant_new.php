<?php

declare(strict_types=1);

use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Modules;
use Lotgd\Sanitize;
use Lotgd\DebugLog;

        $apply = Http::get('apply');
if ($apply == 1) {
    $ocn = Http::post('clanname');
    $ocs = Http::post('clanshort');
    $clanname = stripslashes($ocn);
    $clanname = Sanitize::fullSanitize($clanname);
    if (getsetting('clannamesanitize', 0)) {
        $clanname = preg_replace("'[^[:alpha:] \\'-]'", "", $clanname);
    }
    $clanname = addslashes($clanname);
    Http::postSet('clanname', $clanname);
    $clanshort = Sanitize::fullSanitize($ocs);
    if (getsetting('clanshortnamesanitize', 0)) {
        $clanshort = preg_replace("'[^[:alpha:]]'", "", $clanshort);
    }
    Http::postSet('clanshort', $clanshort);
    $sql = "SELECT * FROM " . Database::prefix("clans") . " WHERE clanname='$clanname'";
    $result = Database::query($sql);
    $e = array (Translator::translateInline("%s`7 looks over your form but informs you that your clan name must consist only of letters, spaces, apostrophes, or dashes.  Also, your short name can consist only of letters. She hands you a blank form."),
        Translator::translateInline("%s`7 looks over your form but informs you that you must have at least 5 and no more than 50 characters in your clan's name (and they must consist only of letters, spaces, apostrophes, or dashes), then hands you a blank form."),
        Translator::translateInline("%s`7 looks over your form but informs you that you must have at least 2 and no more than %s characters in your clan's short name (and they must all be letters), then hands you a blank form."),
        Translator::translateInline("%s`7 looks over your form but informs you that the clan name %s is already taken, and hands you a blank form."),
        Translator::translateInline("%s`7 looks over your form but informs you that the short name %s is already taken, and hands you a blank form."),
        Translator::translateInline("%s`7 asks for the %s gold to start the clan, but you seem to be unable to produce the fees."),
        Translator::translateInline("%s`7 asks for the %s gold and %s gems to start the clan, but you seem to be unable to produce the fees."),
        Translator::translateInline("%s`7 asks for the %s gems to start the clan, but you seem to be unable to produce the fees."),
        Translator::translateInline("She takes your application, and stamps it \"`\$DENIED`7\"."),
    );
    if ($clanname != $ocn || $clanshort != $ocs) {
        $output->outputNotl($e[0], $registrar);
        clanform();
        Nav::add("Return to the Lobby", "clan.php");
    } elseif (strlen($clanname) < 5 || strlen($clanname) > 50) {
        $output->outputNotl($e[1], $registrar);
        clanform();
        Nav::add("Return to the Lobby", "clan.php");
    } elseif (strlen($clanshort) < 2 || strlen($clanshort) > getsetting('clanshortnamelength', 5)) {
        $output->outputNotl($e[2], $registrar, getsetting('clanshortnamelength', 5));
        clanform();
        Nav::add("Return to the Lobby", "clan.php");
    } elseif (Database::numRows($result) > 0) {
        $output->outputNotl($e[3], $registrar, stripslashes($clanname));
        clanform();
        Nav::add("Return to the Lobby", "clan.php");
    } else {
        //too many stupids put < and > in their clanshort name -_-
        $clanshort = str_replace("<", "", $clanshort);
        $clanshort = str_replace(">", "", $clanshort);
        $sql = "SELECT * FROM " . Database::prefix("clans") . " WHERE clanshort='$clanshort'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $output->outputNotl($e[4], $registrar, stripslashes($clanshort));
            clanform();
            Nav::add("Return to the Lobby", "clan.php");
        } else {
            if ($session['user']['gold'] < $gold || $session['user']['gems'] < $gems) {
                if ($gold > 0 && $gems <= 0) {
                    $output->outputNotl($e[5], $registrar, $gold);
                } elseif ($gems > 0 && $gold <= 0) {
                    $output->outputNotl($e[7], $registrar, $gems);
                } else {
                    $output->outputNotl($e[6], $registrar, $gold, $gems);
                }
                $output->outputNotl($e[8], $registrar);
                Nav::add("Return to the Lobby", "clan.php");
            } else {
/*//*/                      $args = array("ocn" => $ocn, "ocs" => $ocs, "clanname" => $clanname, "clanshort" => $clanshort);
/*//*/                      $args = Modules::hook("process-createclan", $args);
/*//*/                      if (isset($args['blocked']) && $args['blocked']) {
/*//*/                          $output->outputNotl(Translator::sprintfTranslate($args['blockmsg']));
/*//*/                          clanform();
/*//*/                          Nav::add("Return to the Lobby", "clan.php");
/*//*/
} else {
                            $sql = "INSERT INTO " . Database::prefix("clans") . " (clanname,clanshort) VALUES ('$clanname','$clanshort')";
                            Database::query($sql);
                            $id = Database::insertId();
                            $session['user']['clanid'] = $id;
                            $session['user']['clanrank'] = CLAN_LEADER + 1; //+1 because he is the founder
                            $session['user']['clanjoindate'] = date("Y-m-d H:i:s");
                            $session['user']['gold'] -= $gold;
                            $session['user']['gems'] -= $gems;
                            DebugLog::add("has started a new clan (<$clanshort> $clanname) for $gold gold and $gems gems.");
                            $output->output("%s`7 looks over your form, and finding that everything seems to be in order, she takes your fees, stamps the form \"`\$APPROVED`7\" and files it in a drawer.`n`n", $registrar);
                            $output->output("Congratulations, you've created a new clan named %s!", stripslashes($clanname));
                            Nav::add("Enter your clan hall", "clan.php");
/*//*/
}
            }
        }
    }
} else {
    $output->output("`7You approach %s`7 and inquire about starting a new clan.", $registrar);
    $output->output("She tells you that there are three requirements to starting a clan.");
    $output->output("First, you have to decide on a full name for your clan.");
    $output->output("Second, you have to decide on an abbreviation for your clan.");
    $output->output("Third you have to decide on whether or not you're willing to give up the fees that are required to start the clan.");
    $output->output("This fee is used to tailor the locks on your clan door to you and your members.`n");
    $output->output("The fees are as follows:`nGold: `^%s`7`nGems: `%%s`7", $gold, $gems);
    Nav::add("Return to the Lobby", "clan.php");
    $e1 = Translator::translateInline("`n`n\"`5Since you do not have enough gold with you, I cannot allow you to apply for a clan,`7\" she says.");
    $e2 = Translator::translateInline("`n`n\"`5Since you do not have enough gems with you, I cannot allow you to apply for a clan,`7\" she says.");
    $e3 = Translator::translateInline("`n`n\"`5If you're ok with these three requirements, please fill out the following form,`7\" she says, handing you a sheet of paper.");
    if ($session['user']['gold'] < $gold) {
        $output->outputNotl($e1);
    } else {
        if ($session['user']['gems'] < $gems) {
            $output->outputNotl($e2, $registrar);
        } else {
            $output->outputNotl($e3, $registrar);
            clanform();
        }
    }
}
