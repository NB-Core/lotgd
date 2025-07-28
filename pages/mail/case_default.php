<?php
declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;

output("`b`iMail Box`i`b");
if (isset($session['message'])) {
    output($session['message']);
}
$session['message'] = "";
$sortorder = httpget('sortorder');
if ($sortorder == '') {
    $sortorder = 'date';
}
$order = match ($sortorder) {
        'subject' => 'subject',
        'name'    => 'name',
        default   => 'sent'
};
$sorting_direction = (int)httpget('direction');
if ($sorting_direction == 0) {
    $direction = "DESC";
} else {
    $direction = "ASC";
}
$newdirection = (int)!$sorting_direction;

$rows = Mail::getInbox($session['user']['acctid'], $order, $direction);
$db_num_rows = count($rows);
if ($db_num_rows > 0) {
    $no_subject = Translator::translateInline("`i(No Subject)`i");
    $subject = Translator::translateInline("Subject");
    $from = Translator::translateInline("Sender");
    $date = Translator::translateInline("SendDate");
    $arrow = ($sorting_direction ? "arrow_down.png" : "arrow_up.png");

    rawoutput("<form action='mail.php?op=process' onsubmit=\"return confirm('Do you really want to delete/move/process those entries?');\" method='post'><table>");
    rawoutput("<tr class='trhead'><td></td>");
    rawoutput("<td>" . ($sortorder == 'subject' ? "<img src='images/shapes/$arrow' alt='$arrow'" : "") . "<a href='mail.php?sortorder=subject&direction=" . ($sortorder == 'subject' ? $newdirection : $sorting_direction) . "'>$subject</a></td>");
    rawoutput("<td>" . ($sortorder == 'name' ? "<img src='images/shapes/$arrow' alt='$arrow'" : "") . "<a href='mail.php?sortorder=name&direction=" . ($sortorder == 'name' ? $newdirection : $sorting_direction) . "'>$from</a></td>");
    rawoutput("<td>" . ($sortorder == 'date' ? "<img src='images/shapes/$arrow' alt='$arrow'" : "") . "<a href='mail.php?sortorder=date&direction=" . ($sortorder == 'date' ? $newdirection : $sorting_direction) . "'>$date</a></td>");
    rawoutput("</tr>");
       $from_list = array();
       $userlist = array();

    foreach ($rows as $row) {
        if ($row['acctid']) {
                $userlist[] = $row['acctid'];
        }
    }

        $user_statuslist = PlayerFunctions::massIsPlayerOnline($userlist);

    foreach ($rows as $row) {
        rawoutput("<tr>");
        rawoutput("<td nowrap><input type='checkbox' id='" . $row['messageid'] . "' name='msg[]' value='{$row['messageid']}'>");
        rawoutput("<img src='images/" . ($row['seen'] ? "old" : "new") . "scroll.GIF' width='16px' height='16px' alt='" . ($row['seen'] ? "Old" : "New") . "'></td>");
        rawoutput("<td>");
        $status_image = "";
        if ((int)$row['msgfrom'] == 0) {
            $row['name'] = Translator::translateInline("`i`^System`0`i");
            // Only translate the subject if it's an array, ie, it came from the game.
            if (isset($row['subject'])) {
                $row_subject = \Lotgd\Serialization::safeUnserialize($row['subject']);
            } else {
                $row_subject = "";
            }
            if ($row_subject !== false && $row_subject != null && is_array($row_subject)) {
                $row['subject'] = Translator::sprintfTranslate(...$row_subject);
            }
        } elseif ($row['name'] == '') {
            $row['name'] = Translator::translateInline("`i`^Deleted User`0`i");
        } else {
            //get status
            $online = $user_statuslist[$row['acctid']];
            $status = ($online ? "online" : "offline");
            $status_image = "<img src='images/$status.gif' alt='$status'>";
        }
        //collect sanitized names plus message IDs for later use
        $sname = Sanitize::sanitize($row['name']);
        if (!isset($from_list[$sname])) {
            $from_list[$sname] = "'" . $row['messageid'] . "'";
        } else {
            $from_list[$sname] .= ", '" . $row['messageid'] . "'";
        }
        // In one line so the Translator doesn't screw the Html up
        rawoutput("<a href='mail.php?op=read&id={$row['messageid']}'>");
        output_notl(((trim($row['subject'])) ? $row['subject'] : $no_subject));
        rawoutput("</a>");
        rawoutput("</td><td><a href='mail.php?op=read&id={$row['messageid']}'>");
        output_notl($row['name']);
        rawoutput("</a>$status_image</td><td><a href='mail.php?op=read&id={$row['messageid']}'>" . date("M d, h:i a", strtotime($row['sent'])) . "</a></td>");
        rawoutput("</tr>");
    }
    rawoutput("</table>");
    $script = "<script language='Javascript'>
					function check_all() {
						var elements = document.getElementsByName(\"msg[]\");
						var max = elements.length;
						var Zaehler=0;
                                                var checktext='" . Translator::translateInline("Check all") . "';
                                                var unchecktext='" . Translator::translateInline("Uncheck all") . "';
						var check = false;
						for (Zaehler=0;Zaehler<max;Zaehler++) {
							if (elements[Zaehler].checked==true) {
								check=true;
								break;
							}
						}
						if (check==false) {
							for (Zaehler=0;Zaehler<max;Zaehler++) {
								elements[Zaehler].checked=true;
								document.getElementById('button_check').value=unchecktext;
							}
						} else {
							for (Zaehler=0;Zaehler<max;Zaehler++) {
								elements[Zaehler].checked=false;
								document.getElementById('button_check').value=checktext;
							}
						}
					}
					function check_name(who) {
						if (who=='') return;
					";
    $add = '';
    $i = 0;
    $option = "<option value=''>---</option>
		";
    foreach ($from_list as $key => $ids) {
        if ($add == '') {
            $add = "new Array(" . $ids . ")";
        } else {
            $add .= ",new Array(" . $ids . ")";
        }
        $option .= "<option value='$i'>" . $key . "</option>
			";
        $i++;
    }
    $script .= "var container = new Array($add);
			var who = document.getElementById('check_name_select').value;
                        var unchecktext='" . Translator::translateInline("Uncheck all") . "';
			for (var i=0;i<container[who].length;i++) {
				document.getElementById(container[who][i]).checked=true;
			}
			document.getElementById('button_check').value=unchecktext;
		}
					</script>";
    rawoutput($script);
    $checkall = htmlentities(Translator::translateInline("Check All"), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
    $delchecked = htmlentities(Translator::translateInline("Delete Checked"), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
    $checknames = htmlentities(Translator::translateInline("`vCheck by Name"), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
        output_notl("<label for='check_name_select'>" . $checknames . "</label> <select onchange='check_name()' id='check_name_select'>" . $option . "</select><br>", true);
    rawoutput("<input type='button' id='button_check' value=\"$checkall\" class='button' onClick='check_all()'>");
    rawoutput("<input type='submit' class='button' value=\"$delchecked\">");
    //enter here more input buttons as you like, you can then evaluate them via the mailfunctions hook
    modulehook("mailform", array());
    //end of hooking
    rawoutput("</form>");
} else {
    output("`i`4Aww, you have no mail, how sad.`i");
}
output("`n`n`i`lYou currently have %s messages in your inbox.`nYou will no longer be able to receive messages from players if you have more than %s unread messages in your inbox.  `nMessages are automatically deleted (read or unread) after %s days.", $db_num_rows, getsetting('inboxlimit', 50), getsetting("oldmail", 14));
