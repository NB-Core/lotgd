<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;

/**
 * Default mail case handler.
 */
function mailDefault(): void
{
    global $session;

    output('`b`iMail Box`i`b');
    if (isset($session['message'])) {
        output($session['message']);
    }
    $session['message'] = '';

    $sortOrder = httpget('sortorder');
    if ($sortOrder === false || $sortOrder === '') {
        $sortOrder = 'date';
    }
    $order = match ($sortOrder) {
        'subject' => 'subject',
        'name'    => 'name',
        default   => 'sent',
    };

    $sortingDirection = (int) httpget('direction');
    $direction = $sortingDirection === 0 ? 'DESC' : 'ASC';
    $newDirection = (int) ! $sortingDirection;

    $rows = Mail::getInbox($session['user']['acctid'], $order, $direction);
    $dbNumRows = count($rows);

    if ($dbNumRows > 0) {
        $noSubject = Translator::translateInline('`i(No Subject)`i');
        $subject = Translator::translateInline('Subject');
        $from = Translator::translateInline('Sender');
        $date = Translator::translateInline('SendDate');
        $arrow = ($sortingDirection ? 'arrow_down.png' : 'arrow_up.png');

        renderMailTableHeader($sortOrder, $sortingDirection, $newDirection, $subject, $from, $date, $arrow);

        $userList = [];
        foreach ($rows as $row) {
            if ($row['acctid']) {
                $userList[] = $row['acctid'];
            }
        }

        $userStatusList = PlayerFunctions::massIsPlayerOnline($userList);

        $fromList = renderMailRows($rows, $userStatusList, $noSubject);

        renderMailFooter($fromList);
    } else {
        output('`i`4Aww, you have no mail, how sad.`i');
    }

    output('`n`n`i`lYou currently have %s messages in your inbox.`nYou will no longer be able to receive messages from players if you have more than %s unread messages in your inbox.  `nMessages are automatically deleted (read or unread) after %s days.', $dbNumRows, getsetting('inboxlimit', 50), getsetting('oldmail', 14));
}

mailDefault();

/**
 * Render the mail table header.
 */
function renderMailTableHeader(string $sortOrder, int $sortingDirection, int $newDirection, string $subject, string $from, string $date, string $arrow): void
{
    rawoutput("<form action='mail.php?op=process' onsubmit=\"return confirm('Do you really want to delete/move/process those entries?');\" method='post'><table>");
    rawoutput("<tr class='trhead'><td></td>");
    rawoutput("<td>" . ($sortOrder === 'subject' ? "<img src='images/shapes/$arrow' alt='$arrow'>" : '') . "<a href='mail.php?sortorder=subject&direction=" . ($sortOrder === 'subject' ? $newDirection : $sortingDirection) . "'>$subject</a></td>");
    rawoutput("<td>" . ($sortOrder === 'name' ? "<img src='images/shapes/$arrow' alt='$arrow'>" : '') . "<a href='mail.php?sortorder=name&direction=" . ($sortOrder === 'name' ? $newDirection : $sortingDirection) . "'>$from</a></td>");
    rawoutput("<td>" . ($sortOrder === 'date' ? "<img src='images/shapes/$arrow' alt='$arrow'>" : '') . "<a href='mail.php?sortorder=date&direction=" . ($sortOrder === 'date' ? $newDirection : $sortingDirection) . "'>$date</a></td>");
    rawoutput('</tr>');
}

/**
 * Render mail rows and return the from list.
 */
function renderMailRows(array $rows, array $userStatusList, string $noSubject): array
{
    $fromList = [];

    foreach ($rows as $row) {
        rawoutput('<tr>');
        rawoutput("<td nowrap><input type='checkbox' id='" . $row['messageid'] . "' name='msg[]' value='{$row['messageid']}'>");
        rawoutput("<img src='images/" . ($row['seen'] ? 'old' : 'new') . "scroll.GIF' width='16px' height='16px' alt='" . ($row['seen'] ? 'Old' : 'New') . "'></td>");
        rawoutput('<td>');

        $statusImage = '';
        if ((int) $row['msgfrom'] === 0) {
            $row['name'] = Translator::translateInline('`i`^System`0`i');
            if (isset($row['subject'])) {
                $rowSubject = \Lotgd\Serialization::safeUnserialize($row['subject']);
            } else {
                $rowSubject = '';
            }
            if ($rowSubject !== false && $rowSubject !== null && is_array($rowSubject)) {
                $row['subject'] = Translator::sprintfTranslate(...$rowSubject);
            }
        } elseif ($row['name'] === '') {
            $row['name'] = Translator::translateInline('`i`^Deleted User`0`i');
        } elseif (empty($row['name']) || !$row['acctid']) {
            $row['name'] = Translator::translateInline('`i`^Deleted User`0`i');
        } else {
            $online = $userStatusList[$row['acctid']] ?? false;
            $status = $online ? 'online' : 'offline';
            $statusImage = "<img src='images/$status.gif' alt='$status'>";
        }

        $sname = Sanitize::sanitize($row['name']);
        if (! isset($fromList[$sname])) {
            $fromList[$sname] = "'" . $row['messageid'] . "'";
        } else {
            $fromList[$sname] .= ", '" . $row['messageid'] . "'";
        }

        rawoutput("<a href='mail.php?op=read&id={$row['messageid']}'>");
        output_notl((trim($row['subject']) ? $row['subject'] : $noSubject));
        rawoutput('</a>');
        rawoutput("</td><td><a href='mail.php?op=read&id={$row['messageid']}'>");
        output_notl($row['name']);
        rawoutput("</a>$statusImage</td><td><a href='mail.php?op=read&id={$row['messageid']}'>" . date('M d, h:i a', strtotime($row['sent'])) . '</a></td>');
        rawoutput('</tr>');
    }

    rawoutput('</table>');

    return $fromList;
}

/**
 * Render footer scripts and controls for the mail table.
 */
function renderMailFooter(array $fromList): void
{
    $script = "<script type='text/javascript'>
                                        function check_all() {
                                                var elements = document.getElementsByName(\"msg[]\");
                                                var max = elements.length;
                                                var Zaehler=0;
                                                var checktext='" . Translator::translateInline('Check all') . "';
                                                var unchecktext='" . Translator::translateInline('Uncheck all') . "';
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
    foreach ($fromList as $key => $ids) {
        if ($add === '') {
            $add = 'new Array(' . $ids . ')';
        } else {
            $add .= ',new Array(' . $ids . ')';
        }
        $option .= "<option value='$i'>" . $key . "</option>
                        ";
        $i++;
    }
    $script .= "var container = new Array($add);
                        var who = document.getElementById('check_name_select').value;
                        var unchecktext='" . Translator::translateInline('Uncheck all') . "';
                        for (var i=0;i<container[who].length;i++) {
                                document.getElementById(container[who][i]).checked=true;
                        }
                        document.getElementById('button_check').value=unchecktext;
                }
                                        </script>";
    rawoutput($script);
    $checkall = appoencode(Translator::translateInline('Check All'));
    $delchecked = appoencode(Translator::translateInline('Delete Checked'));
    $checknames = appoencode(Translator::translateInline('`vCheck by Name`0'));
    output_notl("<label for='check_name_select'>" . $checknames . "</label> <select onchange='check_name()' id='check_name_select'>" . $option . "</select><br>", true);
    rawoutput("<input type='button' id='button_check' value=\"$checkall\" class='button' onClick='check_all()'>");
    rawoutput("<input type='submit' class='button' value=\"$delchecked\">");
    modulehook('mailform', []);
    rawoutput('</form>');
}
