<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
// addnews ready
use Lotgd\Forms;
use Lotgd\Template;

// mail ready
// translator ready

require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/http.php";

$skin = httppost('template');
if ($skin !== '' && Template::isValidTemplate($skin)) {
        Template::setTemplateCookie($skin);
        Template::prepareTemplate(true);
}

require_once __DIR__ . "/lib/villagenav.php";

Translator::getInstance()->setSchema("prefs");

require_once __DIR__ . "/lib/is_email.php";
require_once __DIR__ . "/lib/sanitize.php";

page_header("Preferences");

$op = httpget('op');

addnav("Navigation");
if ($op == "suicide" && getsetting("selfdelete", 0) != 0) {
       $userid = (int)httpget('userid');
    require_once __DIR__ . "/lib/charcleanup.php";
    if (char_cleanup($userid, CHAR_DELETE_SUICIDE)) {
        $sql = "DELETE FROM " . Database::prefix("accounts") . " WHERE acctid=$userid";
        Database::query($sql);
        output("Your character has been deleted!");
        AddNews::add("`#%s quietly passed from this world.", $session['user']['name']);
        addnav("Login Page", "index.php");
        $session = array();
        $session['user'] = array();
        $session['loggedin'] = false;
        $session['user']['loggedin'] = false;
        massinvalidate('charlisthomepage');
        invalidatedatacache("list.php-warsonline");
    }
} elseif ($op == "forcechangeemail") {
    checkday();
    if ($session['user']['alive']) {
        villagenav();
    } else {
        addnav("Return to the news", "news.php");
    }
    addnav("Return to the Prefs", "prefs.php");
    $replacearray = explode("|", $session['user']['replaceemail']);
    $email = $replacearray[0];
    output("`\$The email change request to the address `q\"%s`\$\" has been forced. Links sent will not work anymore.`n`n", $email);
    $session['user']['emailaddress'] = $replacearray[0];
    $session['user']['replaceemail'] = '';
    $session['user']['emailvalidation'] = '';
    debuglog("Email Change Request from " . $session['user']['emailaddress'] . " to " . $email . " has been forced after the wait period", $session['user']['acctid'], $session['user']['acctid'], "Email");
} elseif ($op == "cancelemail") {
    checkday();
    if ($session['user']['alive']) {
        villagenav();
    } else {
        addnav("Return to the news", "news.php");
    }
    addnav("Return to the Prefs", "prefs.php");
    $replacearray = explode("|", $session['user']['replaceemail']);
    $email = $replacearray[0];
    output("`\$The email change request to the address `q\"%s`\$\" has been cancelled. Links sent will not work anymore.`n`n", $email);
    $session['user']['replaceemail'] = '';
    $session['user']['emailvalidation'] = '';
    debuglog("Email Change Request from " . $session['user']['emailaddress'] . " to " . $email . " has been cancelled", $session['user']['acctid'], $session['user']['acctid'], "Email");
} else {
    checkday();
    if ($session['user']['alive']) {
        villagenav();
    } else {
        addnav("Return to the news", "news.php");
    }


    $oldvalues = httppost('oldvalues');
    $oldvalues = html_entity_decode(
        (string) $oldvalues,
        ENT_COMPAT,
        getsetting('charset', 'UTF-8')
    );
    $oldvalues = unserialize($oldvalues);

    $post = httpallpost();
    //strip unnecessary values
    unset($post['oldvalues']);
    unset($post['showFormTabIndex']);

    if (count($post) == 0) {
    } else {
        $pass1 = httppost('pass1');
        $pass2 = httppost('pass2');
        if ($pass1 != $pass2) {
            output("`#Your passwords do not match.`n");
        } else {
            if ($pass1 != "") {
                if (strlen($pass1) > 3) {
                    if (substr($pass1, 0, 5) != "!md5!") {
                        $pass1 = md5(md5($pass1));
                    } else {
                        $pass1 = md5(substr($pass1, 5));
                    }
                    $session['user']['password'] = $pass1;
                    output("`#Your password has been changed.`n");
                } else {
                    output("`#Your password is too short.");
                    output("It must be at least 4 characters.`n");
                }
            }
        }
        reset($post);
        $nonsettings = array(
            "pass1" => 1,
            "pass2" => 1,
            "email" => 1,
            "template" => 1,
            "bio" => 1
        );
        foreach ($post as $key => $val) {
            // If this is one we don't save, skip
            if (isset($nonsettings[$key]) && $nonsettings[$key]) {
                continue;
            }
            if (
                isset($oldvalues[$key]) &&
                    stripslashes($val) == $oldvalues[$key]
            ) {
                continue;
            }
            // If this is a module userpref handle and skip
            debug("Setting $key to $val");
            if (strstr($key, "___")) {
                $val = httppost($key);
                $x = explode("___", $key);
                $module = $x[0];
                $key = $x[1];
                modulehook(
                    "notifyuserprefchange",
                    array("name" => $key,
                            "old" => (isset($oldvalues[$module . "___" . $key]) ? $oldvalues[$module . "___" . $key] : ''),
                            "new" => $val)
                );
                set_module_pref($key, $val, $module);
                continue;
            }
            $session['user']['prefs'][$key] = httppost($key);
        }
        $bio = stripslashes(httppost('bio'));
        $bio = comment_sanitize($bio);
        if ($bio != comment_sanitize($session['user']['bio'])) {
            if ($session['user']['biotime'] > "9000-01-01") {
                output("`\$You cannot modify your bio.");
                output("It has been blocked by the administrators!`0`n");
            } else {
                $session['user']['bio'] = $bio;
                $session['user']['biotime'] = date("Y-m-d H:i:s");
            }
        }
        $email = httppost('email');
        if ($email != $session['user']['emailaddress']) {
            if (getsetting('playerchangeemail', 0)) {
                if (is_email($email)) {
                    if (getsetting("requirevalidemail", 0) == 1) {
                        $emailverification = "x" . md5(date("Y-m-d H:i:s") . $email);
                        $emailverification = substr($emailverification, 0, strlen($emailverification) - 2);
                        //cut last char, won't be salved in the DB else!
                        $subj = translate_mail("LoGD Account Verification", 0);
                        $shortname = $session['user']['login'];
                        $gameurl = getsetting("serverurl", "https://lotgd.com");
                        $serveraddress = $gameurl . "/create.php";
                        $serveraddress = sprintf("%s?op=val&id=%s", $serveraddress, $emailverification);
                        $serverurl = $gameurl;

                        $msg = translate_mail(array("An email change has been requested to this email account.`n`nLogin name: %s `n`n",$shortname));
                        $confirm = translate_mail(array("In order to confirm it, you will need to click on the link below.`n`n %s`n`nNote: You need to be LOGGED OUT of the game to do so. If you are logged in while clicking, log out and try again.`n`n",$serveraddress,$emailverification), 0);
                        $oldconfirm = translate_mail(array("The validation link has been sent, along with this email address, to the old account to verify your change.`n`n"));
                        $ownermsg = translate_mail(array("An email change has been requested to the email account %s.`n`nLogin name: %s `n`n",$email,$shortname));
                        $newvalidationsent = translate_mail(array("The validation will be sent to the new account.`nIf you did NOT request this, somebody with your password got in and requested the change. Go in with your password immediately and change it back. Alter your password, too.`n`n",$shortname,0));
                        $oldvalidationsent = translate_mail(array("No validation will be sent to the new account, so if you did NOT request this, rest assured, you got this message, not them.`n`n"));
                        if (getsetting('playerchangeemailauto', 0)) {
                            $changetimeoutwarning = translate_mail(array("Note that if there is no response from this email address the request will automatically be accepted in about %s days.`n`nThis request can be cancelled anytime in your preferences in the game.`n`n",getsetting('playerchangeemaildays', 3)));
                        } else {
                            $changetimeoutwarning = '';
                        }
                        $footer = $changetimeoutwarning . translate_mail(array("`n`nThanks for playing!`n`n%s",$serverurl));

                        if (getsetting("validationtarget", 0) == 0) {
                            // old account
                            $msg .= $oldconfirm . $footer;
                            $ownermsg .= $oldvalidationsent . $confirm . $footer;
                        } else {
                            $msg .= $confirm . $footer;
                            $ownermsg .= $newvalidationsent . $footer;
                        }
                        //mail new emailaddress
                                $to_array = array($email => $email);
                                $from_array = array(getsetting("gameadminemail", "postmaster@localhost") => getsetting("gameadminemail", "postmaster@localhost"));
                                \Lotgd\Mail::send($to_array, str_replace("`n", "\n", $msg), $subj, $from_array, false, "text/plain");

                        //mail old email address
                                $to_array = array($session['user']['emailaddress'] => $session['user']['login']);
                                $from_array = array(getsetting("gameadminemail", "postmaster@localhost") => getsetting("gameadminemail", "postmaster@localhost"));
                                \Lotgd\Mail::send($to_array, str_replace("`n", "\n", $ownermsg), $subj, $from_array, false, "text/plain");

                        //save replacemail
                        $session['user']['replaceemail'] = $email . "|" . date("Y-m-d H:i:s");
                        $session['user']['emailvalidation'] = $emailverification;
                        debuglog("Email Change requested from " . $session['user']['emailaddress'] . " to " . $email, $session['user']['acctid'], $session['user']['acctid'], "Email");
                        output("`4An email was sent to `\$%s`4 to validate your change. Click the link (`bwhile being logged out!`b) in the email to activate the change. If nothing is done, your email will stay as it is.`0`n`n", translate_inline((getsetting("validationtarget", 0) ? "your new email address" : "your old email address")));
                        if (getsetting('playerchangeemailauto', 0)) {
                            output("`qNote that if there is no response from this email address the request will automatically be accepted in about %s days.`n`n`\$This request can be cancelled anytime here.`4`n`n", getsetting('playerchangeemaildays', 3));
                            if (getsetting("validationtarget", 0) == 0) {
                                output("`\$If you have trouble, please petition. Depending on the policy, we may act to avoid potential abuse.`n`n");
                            }
                        } else {
                            if (getsetting("validationtarget", 0) == 0) {
                                output("`\$If your old account does not exist anymore or you have trouble, please petition. Depending on the policy, we may act to avoid potential abuse.`n`n");
                            }
                        }
                    } else {
                        output("`#Your email address has been changed.`n");
                        debuglog("Email changed from " . $email . " to " . $email, $session['user']['acctid'], $session['user']['acctid'], "Email");
                        $session['user']['emailaddress'] = $email;
                    }
                } else {
                    if (getsetting("requireemail", 0) == 1) {
                        output("`#That is not a valid email address.`n");
                    } else {
                        output("`#Your email address has been changed.`n");
                        debuglog("Email changed from " . $email . " to " . $email, $session['user']['acctid'], $session['user']['acctid'], "Email");
                        $session['user']['emailaddress'] = $email;
                    }
                }
            } else {
                output("`#Your email cannot be changed, system settings prohibit it.`n");
                output("Use the Petition link to ask the  server administrator to change your email address if this one is no longer valid.`n");
            }
        }
        output("`\$Settings saved!`n`n");
    }

    if (!isset($session['user']['prefs']['timeformat'])) {
        $session['user']['prefs']['timeformat'] = "[m/d h:ia]";
    }

    if (!isset($session['user']['prefs']['timeoffset'])) {
        $session['user']['prefs']['timeoffset'] = 0;
    }

    $form = array(
        "Account Preferences,title",
        "pass1" => "Password,password",
        "pass2" => "Retype,password",
        "email" => "Email Address",

        "Character Preferences,title",
        "sexuality" => "Which sex are you attracted to?,enum,0,male,1,female",
        "Note: if you find both attractive then choose one to be your primary. You may change it at any time.,note",

        "Display Preferences,title",
        "template" => "Skin,theme",
                "sortedmenus" => "Nav Headlines are sorted by order?,enum,off,Off,asc,Ascending,desc,Descending",
                "navsort_headers" => "Nav Sub-Headlines are sorted by order?,enum,off,Off,asc,Ascending,desc,Descending",
                "navsort_subheaders" => "Items under a headline are sorted by order?,enum,off,Off,asc,Ascending,desc,Descending",
                "language" => "Language,enum," . getsetting("serverlanguages", "en,English,de,Deutsch,fr,Français,dk,Danish,es,Español,it,Italian"),
        "tabconfig" => "Show config sections in tabs,bool",
        "forestcreaturebar" => "Forest Creatures show health ...,enum,0,Only Text,1,Only Healthbar,2,Healthbar AND Text",
        "ajax" => "Turn AJAX on?,bool",
        "Note: AJAX refreshes i.e. mail notifications (You have X new mails...) without needing you to reload the page. Turn on and see if it gives your computer a headache or not,note",
        "mailwidth" => "Width of your standard mail reply textbox,int",
        "mailheight" => "Height of your standard mail reply textbox,int",
        "popupsize" => "Size of the mailwindow when it opens,text",
        "Note: i.e. 150x120 equals 150 pixels times 120 pixels - keep that format.,note",

        "Game Behavior Preferences,title",
        "emailonmail" => "Send email when you get new Ye Olde Mail?,bool",
        "systemmail" => "Send email for system generated messages?,bool",
        "dirtyemail" => "Allow profanity in received Ye Olde Poste messages?,bool",
        "timestamp" => "Show timestamps in commentary?,enum,0,None,1,Real Time [12/25 1:27pm],2,Relative Time (1h35m)",
        "timeformat" => array("Timestamp format (currently displaying time as %s whereas default format is \"[m/d h:ia]\"),string,20",
            date(
                $session['user']['prefs']['timeformat'],
                strtotime("now") + ($session['user']['prefs']['timeoffset'] * 60 * 60)
            )),
        "timeoffset" => array("Hours to offset time displays (%s currently displays as %s)?,int",
            date($session['user']['prefs']['timeformat']),
            date(
                $session['user']['prefs']['timeformat'],
                strtotime("now") + ($session['user']['prefs']['timeoffset'] * 60 * 60)
            )),
        "ihavenocheer" => "`0Always disable all holiday related text replacements (such as a`1`0l`1`0e => e`1`0g`1`0g n`1`0o`1`0g for December),bool",
        "bio" => "Short Character Biography (255 chars max),string,255",
        "nojump" => "Don't jump to comment areas after refreshing or posting a comment?,bool",
    );
    rawoutput("<script src='src/Lotgd/md5.js' defer></script>");
    $warn = translate_inline("Your password is too short.  It must be at least 4 characters long.");
    rawoutput("<script language='JavaScript'>
	<!--
	function md5pass(){
		//encode passwords before submission to protect them even from network sniffing attacks.
		var passbox = document.getElementById('pass1');
		if (passbox.value.len < 4 && passbox.value.len > 0){
			alert('$warn');
			return false;
		}else{
			var passbox2 = document.getElementById('pass2');
			if (passbox2.value.substring(0, 5) != '!md5!') {
				passbox2.value = '!md5!' + hex_md5(passbox2.value);
			}
			if (passbox.value.substring(0, 5) != '!md5!') {
				passbox.value = '!md5!' + hex_md5(passbox.value);
			}
			return true;
		}
	}
	//-->
	</script>");
    //
    $prefs = $session['user']['prefs'];
    $prefs['bio'] = $session['user']['bio'];
        $cookieTemplate = Template::getTemplateCookie();
    if ($cookieTemplate !== '') {
            $prefs['template'] = Template::addTypePrefix($cookieTemplate);
    }
    if (!isset($prefs['template']) || $prefs['template'] == "") {
            $prefs['template'] = Template::addTypePrefix(getsetting("defaultskin", DEFAULT_TEMPLATE));
    }
    if (!isset($prefs['sexuality']) || $prefs['sexuality'] == "") {
        $prefs['sexuality'] = !$session['user']['sex'];
    }
    if (!isset($prefs['mailwidth']) || $prefs['mailwidth'] == "") {
        $prefs['mailwidth'] = 60;
    }
    if (!isset($prefs['mailheight']) || $prefs['mailheight'] == "") {
        $prefs['mailheight'] = 9;
    }
    $prefs['email'] = $session['user']['emailaddress'];
    // Default tabbed config to true
    if (!isset($prefs['tabconfig'])) {
        $prefs['tabconfig'] = 1;
    }

    // Okay, allow modules to add prefs one at a time.
    // We are going to do it this way to *ensure* that modules don't conflict
    // in namespace.
    $sql = "SELECT modulename FROM " . Database::prefix("modules") . " WHERE infokeys LIKE '%|prefs|%' AND active=1 ORDER BY modulename";
    $result = Database::query($sql);
    $everfound = 0;
    $foundmodules = array();
    $msettings = array();
    $mdata = array();
    while ($row = Database::fetchAssoc($result)) {
        $module = $row['modulename'];
        $info = get_module_info($module);
        if (!isset($info['prefs']) || count($info['prefs']) <= 0) {
            continue;
        }
        $tempsettings = array();
        $tempdata = array();
        $found = 0;
        foreach ($info['prefs'] as $key => $val) {
            $isuser = preg_match("/^user_/", $key);
            $ischeck = preg_match("/^check_/", $key);

            if (is_array($val)) {
                $v = $val[0];
                $x = explode("|", $v);
                $val[0] = $x[0];
                $x[0] = $val;
            } else {
                $x = explode("|", $val);
            }

            $type = explode(",", $x[0]);
            if (isset($type[1])) {
                $type = trim($type[1]);
            } else {
                $type = "string";
            }

            // Okay, if we have a title section, let's copy over the last
            // title section
            if (strstr($type, "title")) {
                if ($found) {
                    $everfound = 1;
                    $found = 0;
                    $msettings = array_merge($msettings, $tempsettings);
                    $mdata = array_merge($mdata, $tempdata);
                }
                $tempsettings = array();
                $tempdata = array();
            }

            if (
                !$isuser && !$ischeck && !strstr($type, "title") &&
                    !strstr($type, "note")
            ) {
                continue;
            }
            if ($isuser) {
                $found = 1;
            }
            // If this is a check preference, we need to call the modulehook
            // checkuserpref  (requested by cortalUX)
            if ($ischeck) {
                $args = modulehook(
                    "checkuserpref",
                    array("name" => $key, "pref" => $x[0], "default" => $x[1]),
                    false,
                    $module
                );
                if (isset($args['allow']) && !$args['allow']) {
                    continue;
                }
                $x[0] = $args['pref'];
                $x[1] = $args['default'];
                $found = 1;
            }

            $tempsettings[$module . "___" . $key] = $x[0];
            if (array_key_exists(1, $x)) {
                $tempdata[$module . "___" . $key] = $x[1];
            }
        }
        if ($found) {
            $msettings = array_merge($msettings, $tempsettings);
            $mdata = array_merge($mdata, $tempdata);
            $everfound = 1;
        }

        // If we found a user editable one
        if ($everfound) {
            // Collect the values
            $foundmodules[] = $module;
        }
    }
    if ($foundmodules != array()) {
        $sql = "SELECT * FROM " . Database::prefix("module_userprefs") . " WHERE modulename IN ('" . implode("','", $foundmodules) . "') AND (setting LIKE 'user_%' OR setting LIKE 'check_%') AND userid='" . $session['user']['acctid'] . "'";
        $result1 = Database::query($sql);
        while ($row1 = Database::fetchAssoc($result1)) {
            $mdata[$row1['modulename'] . "___" . $row1['setting']] = $row1['value'];
        }
        $form = array_merge($form, $msettings);
        $prefs = array_merge($prefs, $mdata);
    }


    if ($session['user']['replaceemail'] != '') {
        //we have an email change request here
        $replacearray = explode("|", $session['user']['replaceemail']);
        if (count($replacearray) < 2) {
            //something awry going on, we have max 1 element there
            $replacearray = array($replacearray[0],'(raw data)');
        }
        output("`\$There is an email change request pending to the email address `q\"%s`\$\" that was given at the timestamp %s (Server Time Zone).`n", $replacearray[0], $replacearray[1]);
        $expirationdate = strtotime("+ " . getsetting('playerchangeemaildays', 3) . " days", strtotime($replacearray[1]));
        $left = $expirationdate - strtotime("now");
        $hoursleft = round($left / (60 * 60), 1);
        $autoaccept = getsetting('playerchangeemailauto', 0);
        if ($autoaccept) {
            if ($hoursleft > 0) {
                output("`n`qIf not cancelled, the option to automatically accept the new email address without verification will be due in approximately %s hours and can be done on this page.`n`n", $hoursleft);
            } else {
                // display the direct link to change it.
                $changeemail = translate_inline("Force your email address NOW");
                output("`n`qTime is up, you can now accept the change via this button:`n`n");
                rawoutput("<form action='prefs.php?op=forcechangeemail' method='POST'><input type='submit' class='button' value='$changeemail'></form><br>");
                addnav("", "prefs.php?op=forcechangeemail");
            }
        } else {
            output("`\$If you have trouble with this, please petition.`n`n");
        }
        $cancelemail = translate_inline("Cancel email change request");
        output("`\$Cancel the request with the following button:`n`n");
        rawoutput("<form action='prefs.php?op=cancelemail' method='POST'><input type='submit' class='button' value='$cancelemail'></form><br>");
        addnav("", "prefs.php?op=cancelemail");
    }

    rawoutput("<form action='prefs.php?op=save' method='POST' onSubmit='return(md5pass)'>");
    $info = Forms::showForm($form, $prefs);
    rawoutput("<input type='hidden' value=\"" .
            htmlentities(serialize($info), ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\" name='oldvalues'>");

    rawoutput("</form><br>");
    addnav("", "prefs.php?op=save");

    // Stop clueless lusers from deleting their character just because a
    // monster killed them.
    if ($session['user']['alive'] && getsetting("selfdelete", 0) != 0) {
        rawoutput("<form action='prefs.php?op=suicide&userid={$session['user']['acctid']}' method='POST'>");
        $deltext = translate_inline('Delete Character');
        $conf = translate_inline("Are you sure you wish to PERMANENTLY delete your character?");
        rawoutput("<table class='noborder' width='100%'><tr><td width='100%'></td><td style='background-color:#FF00FF' align='right'>");
        rawoutput("<input type='submit' class='button' value='$deltext' onClick='return confirm(\"$conf\");'>");
        rawoutput("</td></tr></table>");
        rawoutput("</form><br>");
        addnav("", "prefs.php?op=suicide&userid={$session['user']['acctid']}");
    }
}
page_footer();
