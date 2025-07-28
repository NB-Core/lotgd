<?php
declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;

$row = Mail::getMessage($session['user']['acctid'], $id);
if ($row) {
    $reply = Translator::translateInline("Reply");
    $del = Translator::translateInline("Delete");
    $forward = Translator::translateInline("Forward");
    $unread = Translator::translateInline("Mark Unread");
    $report = Translator::translateInline("Report to Admin");
    $prev = Translator::translateInline("< Previous");
    $next = Translator::translateInline("Next >");
    $problem = "Abusive Email Report:\nFrom: {$row['name']}\nSubject: {$row['subject']}\nSent: {$row['sent']}\nID: {$row['messageid']}\nBody:\n{$row['body']}";
    $problemplayer = (int)$row['msgfrom'];
    $status_image = "";
    if ((int)$row['msgfrom'] == 0) {
        $row['name'] = Translator::translateInline("`i`^System`0`i");
        // No translation for subject if it's not an array
                $row_subject = \Lotgd\Serialization::safeUnserialize($row['subject']);
        if ($row_subject !== false && is_array($row_subject)) {
            $row['subject'] = Translator::sprintfTranslate(...$row_subject);
        } else {
            $row['subject'] = $row_subject;
        }
        // No translation for body if it's not an array
                $row_body = \Lotgd\Serialization::safeUnserialize($row['body']);
        if ($row_body !== false && is_array($row_body)) {
            $row['body'] = Translator::sprintfTranslate(...$row_body);
        } else {
            $row['body'] = $row_body;
        }
    } elseif ($row['name'] == "") {
        $row['name'] = Translator::translateInline("`^Deleted User");
    } else {
        //get status
        $online = (int)PlayerFunctions::isPlayerOnline($row['acctid']);
        $status = ($online ? "online" : "offline");
        $status_image = "<img src='images/$status.gif' alt='$status'>";
    }
    if (!$row['seen']) {
        output("`b`#NEW`b`n");
    } else {
        output("`n");
    }
        $adjacent = Mail::adjacentMessageIds($session['user']['acctid'], $id);
        $pid = $adjacent['prev'];
        $nid = $adjacent['next'];
    output("`b`2From:`b `^%s", $row['name']);
    output_notl($status_image . "`n", true);
    output("`b`2Subject:`b `^%s`n", $row['subject']);
    output("`b`2Sent:`b `^%s`n", $row['sent']);
    rawoutput("<table style=\"width:50%;border:0;cellspacing:10;\">");
    rawoutput("<tr><td><a href='mail.php?op=write&replyto={$row['messageid']}' class='motd'>$reply</a></td><td><a href='mail.php?op=address&id={$row['messageid']}' class='motd'>$forward</a><td>");
    if ($pid > 0) {
        rawoutput("<a href='mail.php?op=read&id=$pid' class='motd'>" . htmlentities($prev, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</a>");
        rawoutput("</td><td nowrap='true'>");
    } else {
        rawoutput(htmlentities($prev), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
        rawoutput("</td><td nowrap='true'>");
    }
    if ($nid > 0) {
        rawoutput("<a href='mail.php?op=read&id=$nid' class='motd'>" . htmlentities($next, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</a></td>");
    } else {
        rawoutput(htmlentities($next, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</td>");
    }
    rawoutput("</tr></table><br/>");
    output_notl(Sanitize::sanitizeMb(str_replace("\n", "`n", $row['body'])));
        Mail::markRead($session['user']['acctid'], $id);
    rawoutput("<table width='50%' border='0' cellpadding='0' cellspacing='5'><tr>
		<td><a href='mail.php?op=write&replyto={$row['messageid']}' class='motd'>$reply</a></td>
		<td><a href='mail.php?op=del&id={$row['messageid']}' class='motd'>$del</a></td>
		</tr><tr>
		<td><a href='mail.php?op=unread&id={$row['messageid']}' class='motd'>$unread</a></td>");
    // Don't allow reporting of system messages as abuse.
    if ((int)$row['msgfrom'] != 0) {
        rawoutput("<td><form action=\"petition.php\" method='post'><input type='hidden' name='problem' value=\"" . htmlentities($problem, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\"/><input type='hidden' name='abuse' value=\"yes\"/><input type='hidden' name='abuseplayer' value=\"" . $problemplayer . "\"/><input type='submit' class='motd' value='$report'/></form></td>");
    } else {
        rawoutput("<td>&nbsp;</td>");
    }
    rawoutput("</tr><tr>");
    rawoutput("<td nowrap='true'>");
    if ($pid > 0) {
        rawoutput("<a href='mail.php?op=read&id=$pid' class='motd'>" . htmlentities($prev, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</a>");
    } else {
        rawoutput(htmlentities($prev), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
    }
    rawoutput("</td><td nowrap='true'>");
    if ($nid > 0) {
        rawoutput("<a href='mail.php?op=read&id=$nid' class='motd'>" . htmlentities($next, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "</a>");
    } else {
        rawoutput(htmlentities($next, ENT_COMPAT, getsetting("charset", "ISO-8859-1")));
    }
    rawoutput("</td>");
    rawoutput("</tr></table>");
} else {
    output("Eek, no such message was found!");
}
