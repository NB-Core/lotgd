<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Mail;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Modules;

/**
 * Display the mail compose form.
 */
function mailWrite(): void
{
    global $session;

    $output = Output::getInstance();
    $settings = Settings::getInstance();

    // Capture request values
    $subject    = (string) Http::post('subject');
    $subjectGet = (string) Http::get('subject');
    $replyTo    = (int) Http::get('replyto');
    $forwardTo  = (int) Http::post('forwardto');
    $toGet      = (string) Http::get('to');
    $toPost     = (string) Http::post('to');
    $bodyGet    = (string) Http::get('body'); // Prefilled request value for body text

    $charset = $settings->getSetting('charset', 'UTF-8');
    $charsetIso = $settings->getSetting('charset', 'ISO-8859-1');
    $mailSizeLimit = (int) $settings->getSetting('mailsizelimit', 1024);
    $superuserMessage = $settings->getSetting(
        'superuseryommessage',
        "Asking an admin for gems, gold, weapons, armor, or anything else which you have not earned will not be honored. If you are experiencing problems with the game, please use the 'Petition for Help' link instead of contacting an admin directly."
    );

    $body  = ''; // Loaded message body when replying or forwarding
    $row   = [];
    $msgId = 0;

    if ($replyTo > 0) {
        // Reply path
        $msgId = $replyTo;
    } elseif ($forwardTo > 0) {
        // Forward path
        $msgId = $forwardTo;
    }

    if ($msgId > 0) {
        $row = Mail::getMessage($session['user']['acctid'], $msgId);

        if ($row) {
            if ((!isset($row['login']) || $row['login'] === '') && $forwardTo == 0) {
                $output->output("You cannot reply to a system message.`n`nPress the \"Back\" button in your browser to get back.");
                $row = [];
                popup_footer();
            }

            if ($forwardTo > 0) {
                $row['login'] = '';
            }

            if (isset($row['login']) && $row['login'] !== '') {
                $row['superuser'] = getSuperuserFlag($row['login']);
            }
        } else {
            $output->output("Eek, no such message was found!`n");
        }
    }

    if ($toGet !== '') {
        $temp = getAccountByLogin($toGet);

        if ($temp === null) {
            $output->output("Could not find that person.`n");
        } else {
            $row = $temp;
        }
    }

    if (is_array($row)) {
        if (isset($row['subject']) && $row['subject']) {
            if ((int) $row['msgfrom'] === 0) {
                $row['name'] = Translator::translateInline('`i`^System`0`i');

                // No translation for subject if it's not an array
                $rowSubject = @unserialize($row['subject']);
                if ($rowSubject !== false) {
                    $row['subject'] = Translator::sprintfTranslate(...$rowSubject);
                }

                // No translation for body if it's not an array
                $rowBody = @unserialize($row['body']);
                if ($rowBody !== false) {
                    $row['body'] = Translator::sprintfTranslate(...$rowBody);
                }
            }

            $subject = $row['subject'];
            if (strncmp($subject, 'RE: ', 4) !== 0) {
                $subject = "RE: $subject";
            }
        }

        if (isset($row['body']) && $row['body']) {
            $body = "\n\n---" . Translator::sprintfTranslate(
                'Original Message from %s(%s)',
                Sanitize::sanitize($row['name']),
                date('Y-m-d H:i:s', strtotime($row['sent']))
            ) . "---\n" . $row['body'];
        }
    }

    $output->rawOutput("<form action='mail.php?op=send' method='post'>");
    $output->rawOutput("<input type='hidden' name='returnto' value=\"$msgId\">");

    $superusers = [];
    $acctidTo   = 0; // recipient account ID for hooks

    if (isset($row['login']) && $row['login'] !== '' && $forwardTo == 0) {
        $output->outputNotl(
            "<input type='hidden' name='to' id='to' value=\"" .
            htmlentities($row['login'], ENT_COMPAT, $charset) .
            "\">",
            true
        );
        $output->output('`2To: `^%s`n', $row['name']);
        if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
            $superusers[] = $row['login'];
        }
    } elseif (is_array($row)) {
        renderRecipientSelection($toPost, $superusers, $acctidTo, $row);
    }

    renderSuperuserScript($superusers);

    $output->output('`2Subject:');
    $output->rawOutput(
        "<input name='subject' value=\"" .
        htmlentities($subject, ENT_COMPAT, $charset) .
        htmlentities(stripslashes($subjectGet), ENT_COMPAT, $charset) .
        "\"><br>"
    );

    $output->rawOutput("<div id='warning' style='visibility: hidden; display: none;'>");
    // superuser messages do not get translated.
    $output->output("`2Notice: `^%s`n", $superuserMessage);
    // Give modules a chance to put info in here to this user
    Modules::hook('mail-write-notify', ['acctid_to' => $acctidTo]);
    $output->rawOutput('</div>');

    $output->output('`2Body:`n');
    renderResizeScripts();

    $prefs = &$session['user']['prefs'];
    if (!isset($prefs['mailwidth']) || $prefs['mailwidth'] === '') {
        $prefs['mailwidth'] = 60;
    }
    if (!isset($prefs['mailheight']) || $prefs['mailheight'] === '') {
        $prefs['mailheight'] = 9;
    }

    $cols = max(10, $prefs['mailwidth']);
    $rows = max(10, $prefs['mailheight']);

    $output->rawOutput(
        "<table style='border:0;cellspacing:10'><tr><td><input type='button' onClick=\"increase(textarea1,1);\" value='+' accesskey='+'></td><td><input type='button' onClick=\"increase(textarea1,-1);\" value='-' accesskey='-'></td><td><input type='button' onClick=\"cincrease(textarea1,-1);\" value='<-'></td><td><input type='button' onClick=\"cincrease(textarea1,1);\" value='->' accesskey='-'></td></tr></table>"
    );

    // mb_substr is necessary if you have chars that take up more than 1 byte.
    $textBody = htmlentities(
        str_replace(
            '`n',
            "\n",
            Sanitize::sanitizeMb(
                mb_substr(
                    $body,
                    0,
                    $mailSizeLimit,
                    $charsetIso
                )
            )
        ),
        ENT_COMPAT,
        $charset
    );
    $textBody .= htmlentities(
        Sanitize::sanitizeMb(stripslashes($bodyGet)),
        ENT_COMPAT,
        $charset
    );

    $output->rawOutput(
        "<textarea id='textarea1' class='input' onKeyUp='sizeCount(this);' name='body' cols='$cols' rows='$rows'>$textBody</textarea>"
    );

    $send     = Translator::translateInline('Send');
    $sendClose = Translator::translateInline('Send and Close');
    $sendBack  = Translator::translateInline('Send and back to main Mailbox');
    $output->rawOutput(
        "<table border='0' cellpadding='0' cellspacing='0' width='100%'><tr><td><input type='submit' class='button' value='$send'></td><td style='width:20px;'></td><td><input type='submit' class='button' value='$sendClose' name='sendclose'></td><td><input type='submit' class='button' value='$sendBack' name='sendback'></td><td align='right'><div id='sizemsg'></div></td></tr></table>"
    );
    $output->rawOutput('</form>');

    renderSizeCountScript();

    if (isset($GLOBALS['forms_output'])) {
        $GLOBALS['forms_output'] = $output->getRawOutput();
    }
}

/**
 * Get the superuser flag for a login.
 */
function getSuperuserFlag(string $login): int
{
    $conn  = Database::getDoctrineConnection();
    $table = Database::prefix('accounts');

    $stmt = $conn->executeQuery(
        "SELECT superuser FROM $table WHERE login = :login",
        ['login' => $login]
    );
    $acct = $stmt->fetchAssociative() ?: [];

    return (int) ($acct['superuser'] ?? 0);
}

/**
 * Fetch account information by login.
 */
function getAccountByLogin(string $login): ?array
{
    $conn  = Database::getDoctrineConnection();
    $table = Database::prefix('accounts');

    $stmt = $conn->executeQuery(
        "SELECT login, name, superuser FROM $table WHERE login = :login",
        ['login' => $login]
    );
    $row = $stmt->fetchAssociative();

    return $row ?: null;
}

/**
 * Render recipient selection or input field.
 */
function renderRecipientSelection(string $to, array &$superusers, int &$acctidTo, array &$row): void
{
    $output = Output::getInstance();
    $settings = Settings::getInstance();
    $charset = $settings->getSetting('charset', 'UTF-8');

    $output->rawOutput("<label for='to'>");
    $output->output('`2To: ');
    $output->rawOutput('</label>');

    $conn  = Database::getDoctrineConnection();
    $table = Database::prefix('accounts');

    $stmt = $conn->executeQuery(
        "SELECT acctid, login, name, superuser FROM $table WHERE login = :login AND locked = 0",
        ['login' => $to]
    );

    $rows = [];
    $row = $stmt->fetchAssociative();

    if ($row !== false) {
        $rows[] = $row;
    } else {
        $string = '%';
        $charset = $settings->getSetting('charset', 'UTF-8');
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($to, $charset);
            if ($length === false) {
                $length = strlen($to);
                for ($x = 0; $x < $length; ++$x) {
                    $string .= $to[$x] . '%';
                }
            } else {
                for ($x = 0; $x < $length; ++$x) {
                    $string .= mb_substr($to, $x, 1, $charset) . '%';
                }
            }
        } else {
            $toLen = strlen($to);
            for ($x = 0; $x < $toLen; ++$x) {
                $string .= $to[$x] . '%';
            }
        }

        $stmt = $conn->executeQuery(
            "SELECT acctid, login, name, superuser FROM $table WHERE name LIKE :pattern AND locked = 0 " .
            "ORDER BY login = :exact_login DESC, name = :exact_name DESC, login",
            [
                'pattern'     => $string,
                'exact_login' => $to,
                'exact_name'  => $to,
            ]
        );
        $rows = $stmt->fetchAllAssociative();
    }

    $dbNumRows = count($rows);

    if ($dbNumRows == 1) {
        $row = $rows[0];
        $output->outputNotl(
            "<input type='hidden' id='to' name='to' value=\"" .
            htmlentities($row['login'], ENT_COMPAT, $charset) .
            "\">",
            true
        );
        $output->outputNotl("`^{$row['name']}`n");
        if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
            $superusers[] = $row['login'];
        }
        $acctidTo = $row['acctid'];
    } elseif ($dbNumRows == 0) {
        $output->output("`\$No one was found who matches \"%s\".`n", stripslashes($to));
        $output->output('`@Please try again.`n');
        Http::set('prepop', $to, true);
        $output->rawOutput('</form>');
        require_once 'pages/mail/case_address.php';
        popup_footer();
    } else {
        $output->outputNotl("<select name='to' id='to' onchange='check_su_warning();'>", true);
        $superusers = [];

        foreach ($rows as $row) {
            $output->outputNotl(
                "<option value=\"" . htmlentities($row['login'], ENT_COMPAT, $charset) . "\">",
                true
            );
            $output->outputNotl('%s', Sanitize::fullSanitize($row['name']));
            if (($row['superuser'] & SU_GIVES_YOM_WARNING) && !($row['superuser'] & SU_OVERRIDE_YOM_WARNING)) {
                $superusers[] = $row['login'];
            }
        }
        $output->outputNotl('</select>`n', true);
    }
}

/**
 * Render javascript with superuser list.
 */
function renderSuperuserScript(array $superusers): void
{
    $output = Output::getInstance();

    $map = [];
    foreach ($superusers as $login) {
        $map[$login] = true;
    }

    $output->rawOutput("<script type='text/javascript'>");
    $output->rawOutput('var superusers = ' . json_encode($map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ';');
    $output->rawOutput('</script>');
}

/**
 * Render textarea resize helper scripts.
 */
function renderResizeScripts(): void
{
    $output = Output::getInstance();

    $output->rawOutput(
        "<script type=\"text/javascript\">function increase(target, value){  if (target.rows + value > 3 && target.rows + value < 50) target.rows = target.rows + value;}</script>"
    );
    $output->rawOutput(
        "<script type=\"text/javascript\">function cincrease(target, value){  if (target.cols + value > 3 && target.cols + value < 150) target.cols = target.cols + value;}</script>"
    );
}

/**
 * Render character counting and warning scripts.
 */
function renderSizeCountScript(): void
{
    $output = Output::getInstance();
    $settings = Settings::getInstance();
    $mailSizeLimit = (int) $settings->getSetting('mailsizelimit', 1024);

    $sizeMsg = '`#Max message size is `@%s`#, you have `^XX`# characters left.';
    $sizeMsg = Translator::translateInline($sizeMsg);
    $sizeMsg = sprintf($sizeMsg, $mailSizeLimit);

    $sizeMsgOver = '`$Max message size is `@%s`$, you are over by `^XX`$ characters!';
    $sizeMsgOver = Translator::translateInline($sizeMsgOver);
    $sizeMsgOver = sprintf($sizeMsgOver, $mailSizeLimit);

    $sizeMsg    = explode('XX', $sizeMsg);
    $sizeMsgOver = explode('XX', $sizeMsgOver);

    $usize1 = addslashes('<span>' . appoencode($sizeMsg[0]) . '</span>');
    $usize2 = addslashes('<span>' . appoencode($sizeMsg[1]) . '</span>');
    $osize1 = addslashes('<span>' . appoencode($sizeMsgOver[0]) . '</span>');
    $osize2 = addslashes('<span>' . appoencode($sizeMsgOver[1]) . '</span>');
    $maxlen = $mailSizeLimit;

    $output->rawOutput("<script type='text/javascript'>");
    $output->rawOutput(
        "var maxlen = $maxlen;" .
        "function sizeCount(box){if(box==null) return;var len = box.value.length;var msg='';if(len <= maxlen){msg='$usize1'+(maxlen-len)+'$usize2';}else{msg='$osize1'+(len-maxlen)+'$osize2';}document.getElementById('sizemsg').innerHTML = msg;}" .
        "function check_su_warning(){var to = document.getElementById('to');var warning = document.getElementById('warning');if(superusers[to.value]){warning.style.visibility='visible';warning.style.display='inline';}else{warning.style.visibility='hidden';warning.style.display='none';}}" .
        'check_su_warning();'
    );
    $output->rawOutput('</script>');
}

if (!defined('LOTGD_MAIL_WRITE_AUTORUN') || LOTGD_MAIL_WRITE_AUTORUN) {
    mailWrite();
}
