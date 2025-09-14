<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;

// translator ready
// addnews ready
// mail ready
define("ALLOW_ANONYMOUS", true);
use Lotgd\Mail;
use Lotgd\CheckBan;
use Lotgd\Settings;
use Lotgd\Sanitize;
use Lotgd\DebugLog;
use Lotgd\Cookies;
use Lotgd\ServerFunctions;

require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/is_email.php";

$original = Settings::getInstance();
$settings_extended = new Settings('settings_extended');
Settings::setInstance($original);
$GLOBALS['settings'] = $original;

Translator::getInstance()->setSchema("create");

$trash = getsetting("expiretrashacct", 1);
$new = getsetting("expirenewacct", 10);
$old = getsetting("expireoldacct", 45);

$msg = '';

CheckBan::check();
$op = httpget('op');
if ($op == 'val' || $op == 'forgotval') {
    if (ServerFunctions::isTheServerFull() == true) {
        //server is full, your "cheat" does not work here buddy ;) you can't bypass this!
        page_header("Account Validation");
        output("Sorry, there are too many people online. Click at the link you used to get here later on. Thank you.");
        addnav("Login", "index.php");

        page_footer();
    }
}

if ($op == "forgotval") {
    $id = httpget('id');
    $sql = "SELECT acctid,login,superuser,password,name,replaceemail,emailaddress,emailvalidation FROM " . Database::prefix("accounts") . " WHERE forgottenpassword='" . Database::escape($id) . "' AND forgottenpassword!=''";
    $result = Database::query($sql);
    if (Database::numRows($result) > 0) {
        $row = Database::fetchAssoc($result);
        $sql = "UPDATE " . Database::prefix("accounts") . " SET forgottenpassword='' WHERE forgottenpassword='$id';";
        Database::query($sql);
        output("`#`cYour login request has been validated.  You may now log in.`c`0");
        rawoutput("<form action='login.php' method='POST'>");
        rawoutput("<input name='name' value=\"{$row['login']}\" type='hidden'>");
        rawoutput("<input name='password' value=\"!md52!{$row['password']}\" type='hidden'>");
        rawoutput("<input name='force' value='1' type='hidden'>");
        $click = translate_inline("Click here to log in");
        rawoutput("<input type='submit' class='button' value='$click'></form>");
        output_notl("`n");
        if ($trash > 0) {
            output("`^Characters that have never been logged into will be deleted after %s day(s) of no activity.`n`0", $trash);
        }
        if ($new > 0) {
            output("`^Characters that have never reached level 2 will be deleted after %s days of no activity.`n`0", $new);
        }
        if ($old > 0) {
            output("`^Characters that have reached level 2 at least once will be deleted after %s days of no activity.`n`0", $old);
        }
        //rare case: we have somebody who deleted his first validation email and then requests a forgotten PW...
        if ($row['emailvalidation'] != "" && substr($row['emailvalidation'], 0, 1) != "x") {
            $sql = "UPDATE " . Database::prefix('accounts') . " SET emailvalidation='' WHERE acctid=" . $row['acctid'];
            Database::query($sql);
        }
    } else {
        output("`#Your request could not be verified.`n`n");
        output("This may be because the link you used is invalid.");
        output("Try to log in, and if that doesn't help, use the 'Forgotten Password' option to retrieve a new mail.`n`nIn case of all hope lost, use the petition link at the bottom of the page and provide ALL details with what you did and what info you got.`n`n");
    }
} elseif ($op == "val") {
    $id = httpget('id');
    $sql = "SELECT acctid,login,superuser,password,name,replaceemail,emailaddress FROM " . Database::prefix("accounts") . " WHERE emailvalidation='" . Database::escape($id) . "' AND emailvalidation!=''";
    $result = Database::query($sql);
    if (Database::numRows($result) > 0) {
        $row = Database::fetchAssoc($result);
        if ($row['replaceemail'] != '') {
            $replace_array = explode("|", $row['replaceemail']);
            $replaceemail = $replace_array[0]; //1==date
            //note: remove any forgotten password request!
            $sql = "UPDATE " . Database::prefix("accounts") . " SET emailaddress='" . $replaceemail . "', replaceemail='',forgottenpassword='' WHERE emailvalidation='$id';";
            Database::query($sql);
            output("`#`c Email changed successfully!`c`0`n");
                        DebugLog::add("Email change request validated by link from " . $row['emailaddress'] . " to " . $replaceemail, $row['acctid'], $row['acctid'], "Email");
            //If a superuser changes email, we want to know about it... at least those who can ee it anyway, the user editors...
            if ($row['superuser'] > 0) {
                // 5 failed attempts for superuser, 10 for regular user
                // send a system message to admin
                $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE (superuser&" . SU_EDIT_USERS . ")";
                $result2 = Database::query($sql);
                $subj = translate_mail(array("`#%s`j has changed the email address",$row['name']), 0);
                $alert = translate_mail(array("Email change request validated by link to %s from %s originally for login '%s'.",$replaceemail,$row['emailaddress'],$row['login']), 0);
                while ($row2 = Database::fetchAssoc($result2)) {
                    $msg = translate_mail(array("This message is generated as a result of an email change to a superuser account.  Log Follows:`n`n%s",$alert), 0);
                    if (Database::affectedRows() > 0) {
                        $noemail = true;
                    } else {
                        $noemail = false;
                    }
                    Mail::systemMail($row2['acctid'], $subj, $msg, 0, $noemail);
                }
            }
        }
        $sql = "UPDATE " . Database::prefix("accounts") . " SET emailvalidation='' WHERE emailvalidation='$id';";
        Database::query($sql);
        output("`#`cYour email has been validated.  You may now log in.`c`0");
        output(
            "Your email has been validated, your login name is `^%s`0.`n`n",
            $row['login']
        );
        if ($row['replaceemail'] == '') {
            //no auto-login for email changers
            rawoutput("<form action='login.php' method='POST'>");
            rawoutput("<input name='name' value=\"{$row['login']}\" type='hidden'>");
            rawoutput("<input name='password' value=\"!md52!{$row['password']}\" type='hidden'>");
            rawoutput("<input name='force' value='1' type='hidden'>");
            $click = translate_inline("Click here to log in");
            rawoutput("<input type='submit' class='button' value='$click'></form>");
        }
        output_notl("`n");
        if ($trash > 0) {
            output("`^Characters that have never been logged into will be deleted after %s day(s) of no activity.`n`0", $trash);
        }
        if ($new > 0) {
            output("`^Characters that have never reached level 2 will be deleted after %s days of no activity.`n`0", $new);
        }
        if ($old > 0) {
            output("`^Characters that have reached level 2 at least once will be deleted after %s days of no activity.`n`0", $old);
        }
        savesetting("newestplayer", $row['acctid']);
    } else {
        output("`#Your email could not be verified.`n`n");
        output("This may be because you already validated your email.");
        output("Try to log in, and if that doesn't help, use the 'Forgotten Password' option to retrieve a new mail.`n`nIn case of all hope lost, use the petition link at the bottom of the page and provide ALL details with what you did and what info you got.`n`n");
    }
}

if ($op == "forgot") {
    $charname = httppost('charname');
    if ($charname != "") {
        $sql = "SELECT acctid,login,emailaddress,forgottenpassword,password FROM " . Database::prefix("accounts") . " WHERE login='" . Database::escape($charname) . "'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            if (trim($row['emailaddress']) != "") {
                if ($row['forgottenpassword'] == "") {
                    $row['forgottenpassword'] = substr("x" . md5(date("Y-m-d H:i:s") . $row['password']), 0, 32);
                    $sql = "UPDATE " . Database::prefix("accounts") . " SET forgottenpassword='{$row['forgottenpassword']}' where login='{$row['login']}'";
                    Database::query($sql);
                }

                $subj = translate_mail($settings_extended->getSetting('forgottenpasswordmailsubject'), $row['acctid']);
                $msg = translate_mail($settings_extended->getSetting('forgottenpasswordmailtext'), $row['acctid']);
                $replace = array(
                        "{login}" => $row['login'],
                        "{acctid}" => $row['acctid'],
                        "{emailaddress}" => $row['emailaddress'],
                        "{requester_ip}" => $_SERVER['REMOTE_ADDR'],
                        "{gameurl}" => getsetting("serverurl", "https://lotgd.com") . "/create.php",
                        "{forgottenid}" => $row['forgottenpassword'],
                          );

                $keys = array_keys($replace);
                $values = array_values($replace);
                $msg = str_replace($keys, $values, $msg);
                $msg = str_replace("`n", "\n", $msg);

                                $to_array = array($row['emailaddress'] => $row['login']);
                                $from_array = array(getsetting("gameadminemail", "postmaster@localhost") => getsetting("gameadminemail", "postmaster@localhost"));
                                \Lotgd\Mail::send($to_array, $msg, $subj, $from_array, false, "text/plain");
                output("`#Sent a new validation email to the address on file for that account.");
                output("You may use the validation email to log in and change your password.");
            } else {
                output("`#We're sorry, but that account does not have an email address associated with it, and so we cannot help you with your forgotten password.");
                output("Use the Petition for Help link at the bottom of the page to request help with resolving your problem.");
            }
        } else {
            output("`#Could not locate a character with that name.");
            output("Look at the List Warriors page off the login page to make sure that the character hasn't expired and been deleted.");
        }
    } else {
        rawoutput("<form action='create.php?op=forgot' method='POST'>");
        output("`bForgotten Passwords:`b`n`n");
        output("Enter your character's name: ");
        rawoutput("<input name='charname'>");
        output_notl("`n");
        $send = translate_inline("Email me my password");
        rawoutput("<input type='submit' class='button' value='$send'>");
        rawoutput("</form>");
    }
}
page_header("Create A Character");
if (getsetting("allowcreation", 1) == 0) {
    output("`\$Creation of new accounts is disabled on this server.");
    output("You may try it again another day or contact an administrator.");
} else {
    if ($op == "create") {
        $emailverification = "";
                $shortname = Sanitize::sanitizeName(getsetting("spaceinname", 0), httppost('name'));

        if (soap($shortname) != $shortname) {
            output("`\$Error`^: Bad language was found in your name, please consider revising it.`n");
            $op = "";
        } else {
            $blockaccount = false;
            $email = httppost('email');
            $pass1 = httppost('pass1');
            $pass2 = httppost('pass2');
            if (getsetting("blockdupeemail", 0) == 1 && getsetting("requireemail", 0) == 1) {
                $sql = "SELECT login FROM " . Database::prefix("accounts") . " WHERE emailaddress='" . Database::escape($email) . "'";
                $result = Database::query($sql);
                if (Database::numRows($result) > 0) {
                    $blockaccount = true;
                    $msg .= translate_inline("You may have only one account.`n");
                }
            }

            $passlen = (int)httppost("passlen");
            if (
                substr($pass1, 0, 5) != "!md5!" &&
                    substr($pass1, 0, 6) != "!md52!"
            ) {
                $passlen = strlen($pass1);
            }
            if ($passlen <= 3) {
                $msg .= translate_inline("Your password must be at least 4 characters long.`n");
                $blockaccount = true;
            }
            if ($pass1 != $pass2) {
                $msg .= translate_inline("Your passwords do not match.`n");
                $blockaccount = true;
            }
            if (strlen($shortname) < 3) {
                $msg .= translate_inline("Your name must be at least 3 characters long.`n");
                $blockaccount = true;
            }
            if (strlen($shortname) > 25) {
                $msg .= translate_inline("Your character's name cannot exceed 25 characters.`n");
                $blockaccount = true;
            }
            if (getsetting("requireemail", 0) == 1 && is_email($email) || getsetting("requireemail", 0) == 0) {
            } else {
                $msg .= translate_inline("You must enter a valid email address.`n");
                $blockaccount = true;
            }
            $args = modulehook("check-create", httpallpost());
            $args['blockaccount'] = $args['blockaccount'] ?? false;
            $args['msg'] = $args['msg'] ?? '';

            if ($args['blockaccount']) {
                $msg .= $args['msg'];
                $blockaccount = true;
            }

            if (!$blockaccount) {
                $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE login='$shortname'";
                $result = Database::query($sql);
                $count = Database::numRows($result);
                $sql = "SELECT playername FROM " . Database::prefix("accounts") ;
                $result = Database::query($sql);
                while ($row = Database::fetchAssoc($result)) {
                    if (Sanitize::sanitize($row['playername']) == $shortname) {
                        $count++;
                        break;
                    }
                }
                if ($count > 0) {
                    output("`\$Error`^: Someone is already known by that name in this realm, please try again.");
                    $op = "";
                } else {
                    $sex = (int)httppost('sex');
                    // Inserted the following line to prevent hacking
                    // Reported by Eliwood
                    if ($sex <> SEX_MALE) {
                        $sex = SEX_FEMALE;
                    }
                    require_once __DIR__ . "/lib/titles.php";
                    $title = get_dk_title(0, $sex);
                    if (getsetting("requirevalidemail", 0)) {
                        $emailverification = md5(date("Y-m-d H:i:s") . $email);
                    }
                    $refer = httpget('r');
                    if ($refer > "") {
                        $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE login='" . Database::escape($refer) . "'";
                        $result = Database::query($sql);
                        if (Database::numRows($result) > 0) {
                            $ref = Database::fetchAssoc($result);
                            $referer = $ref['acctid'];
                        } else {
                            //expired, deleted...
                            output("`\$The referral code you used is not active anymore - please get in touch with the provider, if you want the referral to count. Thank you!`n`nThen either create a new char or let us now in a timely manner who referred you!`n`n");
                            $referer = 0;
                        }
                    } else {
                        $referer = 0;
                    }
                    $dbpass = "";
                    if (substr($pass1, 0, 5) == "!md5!") {
                        $dbpass = md5(substr($pass1, 5));
                    } else {
                        $dbpass = md5(md5($pass1));
                    }
                    $allowednavs = addslashes(serialize(['village.php' => true]));
                    $sql = "INSERT INTO " . Database::prefix("accounts") . "
                                                (playername,name, superuser, title, password, sex, login, laston, uniqueid, lastip, gold, location, emailaddress, emailvalidation, referer, regdate,badguy,allowednavs,restorepage,specialinc,specialmisc,bufflist,dragonpoints,replaceemail,forgottenpassword,prefs,hauntedby,donationconfig,bio,ctitle,companions)
                                                VALUES
                                                ('$shortname','$title $shortname', '" . getsetting("defaultsuperuser", 0) . "', '$title', '$dbpass', '$sex', '$shortname', '" . date("Y-m-d H:i:s", strtotime("-1 day")) . "', '" . (Cookies::getLgi() ?? '') . "', '" . $_SERVER['REMOTE_ADDR'] . "', " . getsetting("newplayerstartgold", 50) . ", '" . addslashes(getsetting('villagename', LOCATION_FIELDS)) . "', '$email', '$emailverification', '$referer', NOW(),'','" . $allowednavs . "', 'village.php','','','',0,'','','','','','','','')";
                    Database::query($sql);
                    if (Database::affectedRows() <= 0) {
                        output("`\$Error`^: Your account was not created for an unknown reason, please try again. ");
                    } else {
                        $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE login='$shortname'";
                        $result = Database::query($sql);
                        $row = Database::fetchAssoc($result);
                        $args = httpallpost();
                        $args['acctid'] = $row['acctid'];
                        //insert output
                        $sql_output = "INSERT INTO " . Database::prefix("accounts_output") . " VALUES ({$row['acctid']},'');";
                        Database::query($sql_output);
                        //end
                        modulehook("process-create", $args);
                        if ($emailverification != "") {
                            $subj = translate_mail($settings_extended->getSetting('verificationmailsubject'), 0);
                            $msg = translate_mail($settings_extended->getSetting('verificationmailtext'), 0);
                            $replace = array(
                                    "{login}" => $shortname,
                                    "{acctid}" => $row['acctid'],
                                    "{emailaddress}" => $email,
                                    "{gameurl}" => getsetting("serverurl", "https://lotgd.com") . "/create.php",
                                    "{validationid}" => $emailverification,
                                      );

                            $keys = array_keys($replace);
                            $values = array_values($replace);
                            $msg = str_replace($keys, $values, $msg);
                            $msg = str_replace("`n", "\n", $msg);
                                                        $to_array = array($email => $shortname);
                                                        $from_array = array(getsetting("gameadminemail", "postmaster@localhost") => getsetting("gameadminemail", "postmaster@localhost"));
                                                        \Lotgd\Mail::send($to_array, $msg, $subj, $from_array, false, "text/plain");
                            output("`4An email was sent to `\$%s`4 to validate your address.  Click the link in the email to activate your account.`0`n`n", $email);
                        } else {
                            rawoutput("<form action='login.php' method='POST'>");
                            rawoutput("<input name='name' value=\"$shortname\" type='hidden'>");
                            rawoutput("<input name='password' value=\"$pass1\" type='hidden'>");
                            $click = translate_inline("Click here to log in");
                            rawoutput("<input type='submit' class='button' value='$click'>");
                            rawoutput("</form>");
                            output_notl("`n");
                            savesetting("newestplayer", $row['acctid']);
                        }
                        output("`\$Your account was created, your login name is `^%s`\$.`n`n", $shortname);
                        if ($trash > 0) {
                            output("`^Characters that have never been logged into will be deleted after %s day(s) of no activity.`n`0", $trash);
                        }
                        if ($new > 0) {
                            output("`^Characters that have never reached level 2 will be deleted after %s days of no activity.`n`0", $new);
                        }
                        if ($old > 0) {
                            output("`^Characters that have reached level 2 at least once will be deleted after %s days of no activity.`n`0", $old);
                        }
                    }
                }
            } else {
                output("`\$Error`^:`n%s", $msg);
                $op = "";
            }
        }
    }
    if ($op == "") {
        output("`&`c`bCreate a Character`b`c`0");
        $refer = httpget('r');
        if ($refer) {
            $refer = "&r=" . htmlentities($refer, ENT_COMPAT, getsetting("charset", "UTF-8"));
        }

        rawoutput("<script src='src/Lotgd/md5.js' defer></script>");
        rawoutput("<script language='JavaScript'>
				<!--
				function md5pass(){
				// encode passwords
				var plen = document.getElementById('passlen');
				var pass1 = document.getElementById('pass1');
				plen.value = pass1.value.length;

				if(pass1.value.substring(0, 5) != '!md5!') {
				pass1.value = '!md5!'+hex_md5(pass1.value);
				}
				var pass2 = document.getElementById('pass2');
				if(pass2.value.substring(0, 5) != '!md5!') {
				pass2.value = '!md5!'+hex_md5(pass2.value);
				}

				}
				//-->
				</script>");
                rawoutput("<form action=\"create.php?op=create$refer\" method='POST' onSubmit=\"md5pass();\">");
                // this is the first thing a new player will se, so let's make it look
                // better
                rawoutput("<input type='hidden' name='passlen' id='passlen' value='0'>");
                rawoutput("<table><tr valign='top'><td>");
                output("How will you be known to this world? ");
                rawoutput("</td><td><input name='name'></td></tr><tr valign='top'><td>");
                output("Enter a password: ");
                rawoutput("</td><td><input type='password' name='pass1' id='pass1'></td></tr><tr valign='top'><td>");
                output("Re-enter it for confirmation: ");
                rawoutput("</td><td><input type='password' name='pass2' id='pass2'></td></tr><tr valign='top'><td>");
                output("Enter your email address: ");
                $r1 = translate_inline("`^(optional -- however, if you choose not to enter one, there will be no way that you can reset your password if you forget it!)`0");
                $r2 = translate_inline("`\$(required)`0");
                $r3 = translate_inline("`\$(required, an email will be sent to this address to verify it before you can log in)`0");
        if (getsetting("requireemail", 0) == 0) {
            $req = $r1;
        } elseif (getsetting("requirevalidemail", 0) == 0) {
            $req = $r2;
        } else {
            $req = $r3;
        }
                rawoutput("</td><td><input name='email'>");
                output_notl("%s", $req);
                rawoutput("</td></tr></table>");
                output(
                    "`nAnd are you a %s Female or a %s Male?`n",
                    "<input type='radio' name='sex' value='1'>",
                    "<input type='radio' name='sex' value='0' checked>",
                    true
                );
                modulehook("create-form");
                $createbutton = translate_inline("Create your character");
                rawoutput("<input type='submit' class='button' value='$createbutton'>");
                output_notl("`n`n");
        if ($trash > 0) {
            output("`^Characters that have never been logged into will be deleted after %s day(s) of no activity.`n`0", $trash);
        }
        if ($new > 0) {
            output("`^Characters that have never reached level 2 will be deleted after %s days of no activity.`n`0", $new);
        }
        if ($old > 0) {
            output("`^Characters that have reached level 2 at least once will be deleted after %s days of no activity.`n`0", $old);
        }
                rawoutput("</form>");
    }
}
addnav("Login", "index.php");
page_footer();
