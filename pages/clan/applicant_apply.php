<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Nltoappon;
use Lotgd\Translator;
use Lotgd\Modules;
use Lotgd\Sanitize;

        $to = (int)Http::get('to');
if ($to > 0) {
    $output->output("`%%s`7 accepts your application, files it in her out box, and folds her hands on the desk, staring at you.", $registrar);
    $output->output("You stand there staring blankly back at her for a few minutes before she suggests that perhaps you'd like to take a seat in the waiting area.");

    Nav::add("Return to the Lobby", "clan.php");
    Nav::add("Waiting Area", "clan.php?op=waiting");
    $session['user']['clanid'] = $to;
    $session['user']['clanrank'] = CLAN_APPLICANT;
    $session['user']['clanjoindate'] = date("Y-m-d H:i:s");
    $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE clanid='{$session['user']['clanid']}' AND clanrank>=" . CLAN_OFFICER;
    $result = Database::query($sql);
    $sql = "DELETE FROM " .  Database::prefix("mail") . " WHERE msgfrom=0 AND seen=0 AND subject='" . Database::escape(serialize($apply_subj)) . "'";
    Database::query($sql);
    while ($row = Database::fetchAssoc($result)) {
        $msg = array("`^You have a new clan applicant!  `&%s`^ has completed a membership application for your clan!",$session['user']['name']);
        Mail::systemMail($row['acctid'], $apply_subj, $msg);
    }

    // send reminder mail if clan of choice has a description

    $sql = "SELECT * FROM " . Database::prefix("clans") . " WHERE clanid='$to'";
    $res = Database::queryCached($sql, "clandata-$to", 3600);
    $row = Database::fetchAssoc($res);

    if (Nltoappon::convert($row['clandesc']) != "") {
        $subject = "Clan Application Reminder";
        $mail = "`&Did you remember to read the description of the clan of your choice before applying?  Note that some clans may have requirements that you have to fulfill before you can become a member.  If you are not accepted into the clan of your choice anytime soon, it may be because you have not fulfilled these requirements.  For your convenience, the description of the clan you are applying to is reproduced below.`n`n`c`#%s`@ <`^%s`@>`0`c`n%s";

        Mail::systemMail($session['user']['acctid'], array($subject), array($mail, $row['clanname'], $row['clanshort'], addslashes(Nltoappon::convert($row['clandesc']))));
    }
} else {
    $order = (int)Http::get('order');
    switch ($order) {
        case 1:
            $order = 'clanname ASC';
            break;
        default:
            $order = 'c DESC';
            break;
    }
    $sql = "SELECT MAX(" . Database::prefix("clans") . ".clanid) AS clanid,MAX(clanname) AS clanname,count(" . Database::prefix("accounts") . ".acctid) AS c FROM " . Database::prefix("clans") . " INNER JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("clans") . ".clanid=" . Database::prefix("accounts") . ".clanid WHERE " . Database::prefix("accounts") . ".clanrank > " . CLAN_APPLICANT . " GROUP BY " . Database::prefix("clans") . ".clanid ORDER BY $order";
    $result = Database::query($sql);
    if (Database::numRows($result) > 0) {
        $output->output("`7You ask %s`7 for a clan membership application form.", $registrar);
        $output->output("She opens a drawer in her desk and pulls out a form.  It contains only two lines: Name and Clan Name.");
        $output->output("You furrow your brow, not sure if you really like having to deal with all this red tape, and get set to concentrate really hard in order to complete the form.");
        $output->output("Noticing your attempt to write on the form with your %s, %s`7 claims the form back from you, writes %s`7 on the first line, and asks you the name of the clan that you'd like to join:`n`n", $session['user']['weapon'], $registrar, $session['user']['name']);
        while ($row = Database::fetchAssoc($result)) {
            if ($row['c'] == 0) {
                $sql = "DELETE FROM " . Database::prefix("clans") . " WHERE clanid={$row['clanid']}";
                Database::query($sql);
            } else {
/*//*/                  $row = Modules::hook("clan-applymember", $row);
/*//*/                  if (isset($row['handled']) && $row['handled']) {
                    continue;
}
                $memb_n = Translator::translateInline("(%s members)");
                $memb_1 = Translator::translateInline("(%s member)");
if ($row['c'] == 1) {
    $memb = sprintf($memb_1, $row['c']);
} else {
    $memb = sprintf($memb_n, $row['c']);
}
                $output->outputNotl(
                    "&#149; <a href='clan.php?op=apply&to=%s'>%s</a> %s`n",
                    $row['clanid'],
                    Sanitize::fullSanitize(htmlentities($row['clanname'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))),
                    $memb,
                    true
                );
                Nav::add("", "clan.php?op=apply&to={$row['clanid']}");
            }
        }
        Nav::add("Return to the Lobby", "clan.php");
        Nav::add("Sorting");
        Nav::add("Order by Membercount", "clan.php?op=apply&order=0");
        Nav::add("Order by Clanname", "clan.php?op=apply&order=1");
    } else {
        $output->output("`7You ask %s`7 for a clan membership application form.", $registrar);
        $output->output("She stares at you blankly for a few moments, then says, \"`5Sorry pal, no one has had enough gumption to start up a clan yet.  Maybe that should be you, eh?`7\"");
        Nav::add("Apply for a New Clan", "clan.php?op=new");
        Nav::add("Return to the Lobby", "clan.php");
    }
}
