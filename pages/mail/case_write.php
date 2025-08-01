<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\Sanitize;
use Lotgd\Translator;

$subject = httppost('subject');
$body = "";
$row = "";
$replyto = (int)httpget('replyto');
$forwardto = (int) httppost('forwardto');
if ($replyto > 0) {
    $msgid = $replyto;
} else {
    $msgid = $forwardto;
}
if ($msgid > 0) {
    $row = Mail::getMessage($session['user']['acctid'], $msgid);
    if ($row) {
        if ((!isset($row['login']) || $row['login'] === '') && $forwardto == 0) {
            output("You cannot reply to a system message.`n`nPress the \"Back\" button in your browser to get back.");
            $row = [];
            popup_footer();
        }
        if ($forwardto > 0) {
            $row['login'] = '';
        }
        if (isset($row['login']) && $row['login'] !== '') {
            $sql = "SELECT superuser FROM " . db_prefix('accounts')
                . " WHERE login = '" . addslashes($row['login']) . "'";
            $result = db_query($sql);
            $acct = db_fetch_assoc($result);
            $row['superuser'] = $acct['superuser'] ?? 0;
        }
    } else {
        output("Eek, no such message was found!`n");
    }
}
$to = httpget('to');
if ($to) {
    $sql = "SELECT login,name, superuser FROM " . db_prefix("accounts") . " WHERE login=\"$to\"";
    $result = db_query($sql);
    if (!($row = db_fetch_assoc($result))) {
        output("Could not find that person.`n");
    }
}
if (is_array($row)) {
    if (isset($row['subject']) && $row['subject']) {
        if ((int)$row['msgfrom'] == 0) {
            $row['name'] = Translator::translateInline("`i`^System`0`i");
            // No translation for subject if it's not an array
            $row_subject = @unserialize($row['subject']);
            if ($row_subject !== false) {
                $row['subject'] = Translator::sprintfTranslate(...$row_subject);
            }
            // No translation for body if it's not an array
            $row_body = @unserialize($row['body']);
            if ($row_body !== false) {
                $row['body'] = Translator::sprintfTranslate(...$row_body);
            }
        }
        $subject = $row['subject'];
        if (strncmp($subject, "RE: ", 4) !== 0) {
            $subject = "RE: $subject";
        }
    }
    if (isset($row['body']) && $row['body']) {
        $body = "\n\n---" . Translator::sprintfTranslate("Original Message from %s(%s)", Sanitize::sanitize($row['name']), date("Y-m-d H:i:s", strtotime($row['sent']))) . "---\n" . $row['body'];
    }
}
rawoutput("<form action='mail.php?op=send' method='post'>");
rawoutput("<input type='hidden' name='returnto' value=\"" . htmlentities(stripslashes($msgid), ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">");
$superusers = array();
$acctid_to = 0; //only for hook right now
if (isset($row['login']) && $row['login'] != "" && $forwardto == 0) {
    output_notl("<input type='hidden' name='to' id='to' value=\"" . htmlentities($row['login'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">", true);
    output("`2To: `^%s`n", $row['name']);
    if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
        array_push($superusers, $row['login']);
    }
} else {
        rawoutput("<label for='to'>");
        output("`2To: ");
        rawoutput("</label>");
    $to = httppost('to');
    $sql = "SELECT acctid,login,name,superuser FROM " . db_prefix('accounts') . " WHERE login = '" . addslashes($to) . "' AND locked = 0";
    $result = db_query($sql);
    $db_num_rows = db_num_rows($result);
    if ($db_num_rows != 1) {
        $string = "%";
        $to_len = strlen($to);
        for ($x = 0; $x < $to_len; ++$x) {
            $string .= $to[$x] . "%";
        }
        $sql = "SELECT login,name,superuser FROM " . db_prefix("accounts") . " WHERE name LIKE '" . addslashes($string) . "' AND locked=0 ORDER by login='$to' DESC, name='$to' DESC, login";
        $result = db_query($sql);
        $db_num_rows = db_num_rows($result);
    }
    if ($db_num_rows == 1) {
        $row = db_fetch_assoc($result);
        output_notl("<input type='hidden' id='to' name='to' value=\"" . htmlentities($row['login'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">", true);
        output_notl("`^{$row['name']}`n");
        if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
            array_push($superusers, $row['login']);
        }
        $acctid_to = $row['acctid'];
    } elseif ($db_num_rows == 0) {
        output("`\$No one was found who matches \"%s\".`n", stripslashes($to));
        output("`@Please try again.`n");
        httpset('prepop', $to, true);
        rawoutput("</form>");
                require("pages/mail/case_address.php");
        popup_footer();
    } else {
        output_notl("<select name='to' id='to' onchange='check_su_warning();'>", true);
        $superusers = array();
        while ($row = db_fetch_assoc($result)) {
            output_notl("<option value=\"" . htmlentities($row['login'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">", true);
            output_notl("%s", Sanitize::fullSanitize($row['name']));
            if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
                array_push($superusers, $row['login']);
            }
        }
        output_notl("</select>`n", true);
    }
}
rawoutput("<script type='text/javascript'>var superusers = new Array();");
foreach ($superusers as $val) {
    rawoutput("	superusers['" . addslashes($val) . "'] = true;");
}
rawoutput("</script>");
output("`2Subject:");
rawoutput("<input name='subject' value=\"" . htmlentities($subject, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . htmlentities(stripslashes(httpget('subject')), ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\"><br>");
rawoutput("<div id='warning' style='visibility: hidden; display: none;'>");
//superuser messages do not get translated.
output("`2Notice: `^%s`n", $superusermessage);
// Give modules a chance to put info in here to this user
modulehook("mail-write-notify", array("acctid_to" => $acctid_to));
rawoutput("</div>");
output("`2Body:`n");
rawoutput("<script type=\"text/javascript\">function increase(target, value){  if (target.rows + value > 3 && target.rows + value < 50) target.rows = target.rows + value;}</script>");
rawoutput("<script type=\"text/javascript\">function cincrease(target, value){  if (target.cols + value > 3 && target.cols + value < 150) target.cols = target.cols + value;}</script>");
$key = 1;
$keyout = 'body';
$prefs=&$session['user']['prefs'];
if (!isset($prefs['mailwidth']) || $prefs['mailwidth'] == "") {
    $prefs['mailwidth'] = 60;
}
if (!isset($prefs['mailheight']) || $prefs['mailheight'] == "") {
    $prefs['mailheight'] = 9;
}

$cols = max(10, $prefs['mailwidth']);
$rows = max(10, $prefs['mailheight']);
rawoutput("<table style='border:0;cellspacing:10'><tr><td><input type='button' onClick=\"increase(textarea$key,1);\" value='+' accesskey='+'></td><td><input type='button' onClick=\"increase(textarea$key,-1);\" value='-' accesskey='-'></td>");
rawoutput("<td><input type='button' onClick=\"cincrease(textarea$key,-1);\" value='<-'></td><td><input type='button' onClick=\"cincrease(textarea$key,1);\" value='->' accesskey='-'></td></tr></table>");
//substr is necessary if you have chars that take up more than 1 byte. That breaks the entire HTMLentities up and it returns nothing
rawoutput("<textarea id='textarea$key' class='input' onKeyUp='sizeCount(this);' name='$keyout' cols='$cols' rows='$rows'>" . htmlentities(str_replace("`n", "\n", Sanitize::sanitizeMb(substr($body, 0, getsetting("mailsizelimit", 1024, getsetting("charset", "ISO-8859-1"))))), ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . htmlentities(Sanitize::sanitizeMb(stripslashes(httpget('body'))), ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</textarea>");
//rawoutput("<textarea name='body' id='textarea' class='input' cols='60' rows='9' onKeyUp='sizeCount(this);'>".htmlentities($body, ENT_COMPAT, getsetting("charset", "ISO-8859-1")).htmlentities(stripslashes(httpget('body')), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</textarea><br>");
$send = Translator::translateInline("Send");
$sendclose = Translator::translateInline("Send and Close");
$sendback = Translator::translateInline("Send and back to main Mailbox");
rawoutput("<table border='0' cellpadding='0' cellspacing='0' width='100%'><tr><td><input type='submit' class='button' value='$send'></td><td style='width:20px;'></td><td><input type='submit' class='button' value='$sendclose' name='sendclose'></td><td><input type='submit' class='button' value='$sendback' name='sendback'></td><td align='right'><div id='sizemsg'></div></td></tr></table>");
rawoutput("</form>");
$sizemsg = "`#Max message size is `@%s`#, you have `^XX`# characters left.";
$sizemsg = Translator::translateInline($sizemsg);
$sizemsg = sprintf($sizemsg, getsetting("mailsizelimit", 1024));
$sizemsgover = "`\$Max message size is `@%s`\$, you are over by `^XX`\$ characters!";
$sizemsgover = Translator::translateInline($sizemsgover);
$sizemsgover = sprintf($sizemsgover, getsetting("mailsizelimit", 1024));
$sizemsg = explode("XX", $sizemsg);
$sizemsgover = explode("XX", $sizemsgover);
$usize1 = addslashes("<span>" . appoencode($sizemsg[0]) . "</span>");
$usize2 = addslashes("<span>" . appoencode($sizemsg[1]) . "</span>");
$osize1 = addslashes("<span>" . appoencode($sizemsgover[0]) . "</span>");
$osize2 = addslashes("<span>" . appoencode($sizemsgover[1]) . "</span>");
rawoutput("
<script type='text/javascript'>
	var maxlen = " . getsetting("mailsizelimit", 1024) . ";
	function sizeCount(box){
		if (box==null) return;
		var len = box.value.length;
		var msg = '';
		if (len <= maxlen){
			msg = '$usize1'+(maxlen-len)+'$usize2';
		}else{
			msg = '$osize1'+(len-maxlen)+'$osize2';
		}
		document.getElementById('sizemsg').innerHTML = msg;
	}
	sizeCount(document.getElementById('textarea'));
		function check_su_warning(){
		var to = document.getElementById('to');
		var warning = document.getElementById('warning');
		if (superusers[to.value]){
			warning.style.visibility = 'visible';
			warning.style.display = 'inline';
		}else{
			warning.style.visibility = 'hidden';
			warning.style.display = 'none';
		}
	}
	check_su_warning();
</script>");
