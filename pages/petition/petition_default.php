<?php

declare(strict_types=1);

use Lotgd\Stripslashes;
use Lotgd\Cookies;
use Lotgd\Page\Header;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Modules\HookHandler;
use Lotgd\Sanitize;
use Lotgd\OutputArray;
use Lotgd\Translator;

Translator::getInstance()->setSchema('petition');
Header::popupHeader("Petition for Help");
$post = (array) Http::allPost();
$problem = (string) (Http::post('problem') ?? '');
if (count($post) > 0 && (string) Http::post('abuse') !== 'yes') {
        $ip = explode(".", $_SERVER['REMOTE_ADDR']);
        array_pop($ip);
        $ip = implode(".", $ip) . ".";
        $cookie_lgi = Cookies::getLgi() ?? '';
    $sql = "SELECT count(petitionid) AS c FROM " . Database::prefix("petitions") . " WHERE (ip LIKE '$ip%' OR id = '" . addslashes($cookie_lgi) . "') AND date > '" . date("Y-m-d H:i:s", strtotime("-1 day")) . "'";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    if ($row['c'] < 5 || (isset($session['user']['superuser']) && $session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO)) {
        if (!isset($session['user']['acctid'])) {
            $session['user']['acctid'] = 0;
        }
        if (!isset($session['user']['password'])) {
            $session['user']['password'] = "";
        }
        $p = $session['user']['password'];
        unset($session['user']['password']);
        $date = date("Y-m-d H:i:s");
        //$post['cancelpetition'] = false;
        //$post['cancelreason'] = 'The admins here decided they didn\'t like something about how you submitted your petition.  They were also too lazy to give a real reason.';
        $post = HookHandler::hook("addpetition", $post);
        if (!isset($post['cancelpetition']) || !$post['cancelpetition']) {
            $sql = "INSERT INTO " . Database::prefix("petitions") . " (author,date,body,pageinfo,ip,id) VALUES (" . (int)$session['user']['acctid'] . ",'$date',\"" . addslashes(OutputArray::output($post)) . "\",\"" . addslashes(OutputArray::output($session, "Session:")) . "\",'{$_SERVER['REMOTE_ADDR']}','" . addslashes($cookie_lgi) . "')";
            Database::query($sql);
            // If the admin wants it, email the petitions to them.
            if ($settings->getSetting("emailpetitions", 0)) {
                // Yeah, the format of this is ugly.
                $name = Sanitize::colorSanitize($session['user']['name']);
                $url = $settings->getSetting(
                    "serverurl",
                    "http://" . $_SERVER['SERVER_NAME'] .
                    ($_SERVER['SERVER_PORT'] == 80 ? "" : ":" . $_SERVER['SERVER_PORT']) .
                    dirname($_SERVER['REQUEST_URI'])
                );
                if (!preg_match("/\/$/", $url)) {
                    $url = $url . "/";
                    $settings->saveSetting("serverurl", $url);
                }
                $tl_server = Translator::translateInline("Server");
                $tl_author = Translator::translateInline("Author");
                $tl_date = Translator::translateInline("Date");
                $tl_body = Translator::translateInline("Body");
                $tl_subject = Translator::sprintfTranslate("New LoGD Petition at %s", $url);

                $msg  = "$tl_server: $url\n";
                $msg .= "$tl_author: $name\n";
                $msg .= "$tl_date : $date\n";
                $msg .= "$tl_body :\n" . OutputArray::output($post) . "\n";
                mail($settings->getSetting("gameadminemail", "postmaster@localhost.com"), $tl_subject, $msg);
            }
            $session['user']['password'] = $p;
            $output->output("Your petition has been sent to the server admin.");
            $output->output("Please be patient, most server admins have jobs and obligations beyond their game, so sometimes responses will take a while to be received.");
        } else {
            $output->output("`\$There was a problem with your petition!`n");
            $output->output("`@Please read the information below carefully; there was a problem with your petition, and it was not submitted.\n");
            $output->rawOutput("<blockquote>");
            $output->output($post['cancelreason']);
            $output->rawOutput("</blockquote>");
        }
    } else {
        $output->output("`\$`bError:`b There have already been %s petitions filed from your network in the last day; to prevent abuse of the petition system, you must wait until there have been 5 or fewer within the last 24 hours.", $row['c']);
        $output->output("If you have multiple issues to bring up with the staff of this server, you might think about consolidating those issues to reduce the overall number of petitions you file.");
    }
} else {
    $output->output("`c`b`\$Before sending a petition, please make sure you have read the motd.`n");
    $output->output("Petitions about problems we already know about just take up time we could be using to fix those problems.`b`c`n");
    $output->rawOutput("<form action='petition.php?op=submit' method='POST'>");
    if ($session['user']['loggedin']) {
        $output->output("Your Character's Name: ");
        $output->outputNotl("%s", $session['user']['name']);
        $output->rawOutput("<input type='hidden' name='charname' value=\"" . htmlentities($session['user']['name'], ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "\">");
        $output->output("`nYour email address: ");
        $output->outputNotl("%s", htmlentities($session['user']['emailaddress']));
        $output->rawOutput("<input type='hidden' name='email' value=\"" . htmlentities($session['user']['emailaddress'], ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "\">");
    } else {
        $output->output("Your Character's Name: ");
        $output->rawOutput("<input name='charname' size='46'>");
        $output->output("`nYour email address: ");
        $output->rawOutput("<input name='email' size='50'>");
        $nolog = Translator::translateInline("Character is not logged in!!");
        $output->rawOutput("<input name='unverified' type='hidden' value='$nolog'>");
    }
        $output->rawOutput("<label for='problem_type'>");
        $output->output("`nType of your Problem / Enquiry: ");
        $output->rawOutput("</label>");
        $output->rawOutput("<select name='problem_type' id='problem_type'>");
    $types = $settings->getSetting('petition_types', 'General');
    $types = explode(",", $types);
    foreach ($types as $type) {
        $type = htmlentities($type, ENT_COMPAT, $settings->getSetting("charset", "UTF-8"));
        $output->rawOutput("<option value='" . $type . "'>$type</option>");
    }
    $output->rawOutput("</select><br/>");
    $output->output("`nDescription of the problem:`n");
    $abuse = (string) Http::get('abuse');
    if ($abuse === '') {
        $abuse = (string) Http::post('abuse');
    }
    if ($abuse === 'yes') {
        $output->rawOutput("<textarea name='description' cols='55' rows='7' class='input'></textarea>");
        $output->rawOutput("<input type='hidden' name='abuse' value=\"" . Stripslashes::deep($problem) . "\"><br><hr><pre>" . stripslashes(rawurldecode($problem)) . "</pre><hr><br>");
        $output->rawOutput("<input type='hidden' name='abuseplayer' value=\"" . (string) Http::post('abuseplayer') . "\">");
    } else {
        $output->rawOutput("<textarea name='description' cols='55' rows='7' class='input'>" . Stripslashes::deep(($problem)) . "</textarea>");
    }
    HookHandler::hook("petitionform", array());
    $submit = Translator::translateInline("Submit");
    $output->rawOutput("<br/><input type='submit' class='button' value='$submit'><br/>");
    $output->output("Please be as descriptive as possible in your petition.");
    $output->output("If you have questions about how the game works, please check out the <a href='petition.php?op=faq'>FAQ</a>.", true);
    $output->output("Petitions about game mechanics will more than likely not be answered unless they have something to do with a bug.");
    $output->output("Remember, if you are not signed in, and do not provide an email address, we have no way to contact you.");
    $output->rawOutput("</form>");
}
