<?php

declare(strict_types=1);

/**
 * Administrative module to snapshot and restore player accounts.
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\MySQL\Database;
use Lotgd\Forms;
use Lotgd\ErrorHandler;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

function charrestore_getmoduleinfo(): array
{
    $info = array(
            "name" => "Character Restorer",
            "category" => "Administrative",
            "version" => "1.2",
            "author" => "Eric Stevens, modifications +nb",
            "download" => "core_module",
            "settings" => array(
                "General,title",
                "auto_snapshot" => "Create character snapshots upon character expiration?,bool|1",
                "email_hash_salt" => "Salt Value for your server. NEVER CHANGE THIS AFTER THE FIRST CHANGE!,text|CHANGEME",
                "Thresholds,title",
                "dk_threshold" => "&nbsp;&nbsp;+-- Dragon Kill threshold above which snapshots will be taken?,int|5",
                "lvl_threshold" => "&nbsp;&nbsp;&nbsp;&nbsp;+-- Level within this DK above which snapshots will be taken?,int|0",
                "manual_snapshot" => "Create a snapshot when a char is manually deleted?,bool|0",
                "suicide_snapshot" => "Create a snapshot when a user deletes themselves?,bool|0",
                "permadeath_snapshot" => "Create a snapshot when a user perma-dies?,bool|1",
                "Perma death is not current implemented at the time of writing this module; nor do I have any plans that way; it just made sense to reserve it in case either I or someone else ever introduced this option.,note",
                "Directory,title",
                "snapshot_dir" => "Location to store snapshots|../logd_snapshots",
                "Notifications and expirations,title",
                //              "notifymail"=>"Notify the restored char owner via mail?,bool|1",
                "Users get a mail upon expiration with a token - put here your sender data in,note",
                "adminname" => "Name of the Sender of the email,text|Noname",
                "adminmail" => "Emailaddress of the Sender,text|noreply@noreply.com",
                ),
            "prefs" => array(
                    "hasaccess" => "Has Access to the restorer,bool|0",
                      ),
            );
    return $info;
}

function charrestore_install(): bool
{
    module_addhook_priority("village-desc", 5000);
    module_addhook("delete_character");
    module_addhook("superuser");
    module_addhook("petition-status");
    module_addhook_priority("addpetition", 50);
    module_addhook_priority("petitionform", 50);
    return true;
}

function charrestore_uninstall(): bool
{
       return true;
}

function charrestore_dohook(string $hookname, array $args): array
{
    switch ($hookname) {
        case "village-desc":
            global $session;
            $email_acc = $session['user']['emailaddress'];
            //check if the email is a hash value and warn the user
            if (strlen($email_acc) == strlen(charrestore_gethash('test')) && strpos($email_acc, '@') === false) {
                //if ($session['user']['acctid']==7) {
                rawoutput("<div style='border:2px red; background-color:#002502;font-size:2rem!important;color:#FF0000;>");
                output_notl("`cYou do not have a valid email address! Please correct this in your Preferences immediately!`c");
                rawoutput("</div>");
            }
            break;
        case "petitionform":
            //add some fields to the petition for charrestore
            $charrestore = httpget('charrestore');
            if ($charrestore == 1) {
                $fields = array(
                        "Character Restore Form,title",
                        "login" => "Login Name",
                        "last_online_time" => "Last Online (approx.)",
                        "registered_email_address" => "Registered email address",
                        "oro_kills" => "Amount of Oro Kills",
                        "custom_name" => "Custom Name (if any)",
                           );
                $vals = array();
                Forms::showForm($fields, $vals, true);
            } else {
                output("`n`\$If you are trying to restore a character, click here: ");
                rawoutput("<a href='petition.php?charrestore=1'>" . translate_inline("Character Restore Form", "petition") . "</a>");
                output("`n`0");
            }
            break;
        case "superuser":
            global $session;
            $hasaccess = (int) get_module_pref("hasaccess");
            if (($session['user']['superuser'] & SU_EDIT_USERS) || $hasaccess) {
                addnav("Character Restore");
                addnav(
                    "Restore a deleted char",
                    "runmodule.php?module=charrestore&op=list&admin=true"
                );
            }
            break;
        case "modifyuserview":
            global $session;
            if (is_module_active('charrestore')) {
                $hasaccess = (int) get_module_pref('hasaccess');
                if (($session['user']['superuser'] & SU_EDIT_USERS) || $hasaccess) {
                    $acctid = (int) ($args['user']['acctid'] ?? 0);
                    addnav('Character Backup');
                    addnav('Make a Backup', "runmodule.php?module=charrestore&op=backup&userid={$acctid}");
                }
            }
            break;
        case "petition-status":
            global $session;
            $hasaccess = (int) get_module_pref("hasaccess");
            $retid = (int) httpget('id');
            if ((($session['user']['superuser'] & SU_EDIT_USERS) && $retid > 0) || $hasaccess) {
                addnav("Character Restore");
                addnav(
                    "Restore a deleted char",
                    "runmodule.php?module=charrestore&op=list&admin=true&returnpetition=$retid"
                );
            }
            break;
        case "delete_character":
            if (
                $args['deltype'] == CHAR_DELETE_AUTO &&
                ! get_module_setting("auto_snapshot")
            ) {
                return $args;
            }
            if (
                $args['deltype'] == CHAR_DELETE_MANUAL &&
                ! get_module_setting("manual_snapshot")
            ) {
                return $args;
            }
            if (
                $args['deltype'] == CHAR_DELETE_SUICIDE &&
                ! get_module_setting("suicide_snapshot")
            ) {
                return $args;
            }
            if (
                $args['deltype'] == CHAR_DELETE_PERMADEATH &&
                ! get_module_setting("permadeath_snapshot")
            ) {
                return $args;
            }

            if ($args['deltype'] == CHAR_DELETE_AUTO) {
                $conn = Database::getDoctrineConnection();
                $table = Database::prefix('accounts');
                $row = $conn->fetchAssociative(
                    "SELECT dragonkills, level FROM {$table} WHERE acctid = :acctid",
                    ['acctid' => (int) $args['acctid']],
                    ['acctid' => ParameterType::INTEGER]
                );
                if ($row) {
                    $dragonkills = (int) $row['dragonkills'];
                    $level       = (int) $row['level'];
                    if (
                        $dragonkills < (int) get_module_setting('dk_threshold') ||
                        (
                            $dragonkills === (int) get_module_setting('dk_threshold') &&
                            $level < (int) get_module_setting('lvl_threshold')
                        )
                    ) {
                        return $args;
                    }
                }
            }

            $snapshot = charrestore_create_snapshot((int) $args['acctid']);
            if (! $snapshot) {
                $args['prevent_cleanup'] = true;
            }

            return $args;
    }

    return $args;
}

function charrestore_create_snapshot(int $acctid): bool
{
    $conn    = Database::getDoctrineConnection();
    $table   = Database::prefix('accounts');
    $account = $conn->fetchAssociative(
        "SELECT * FROM {$table} WHERE acctid = :acctid",
        ['acctid' => $acctid],
        ['acctid' => ParameterType::INTEGER]
    );
    if (! $account) {
        return false;
    }

    $user = array("account" => array(), "prefs" => array());

    //set up the user's account table fields
    //reduces storage footprint.
    //id and ip are not necessary and also related to identify persons (stripped)
    $nosavefields = array("output" => true, "allowednavs" => true, "lastip" => true, "uniqueid" => true);
    foreach ($account as $key => $val) {
        if (! isset($nosavefields[$key])) {
            $user['account'][$key] = $val;
        }
    }

    //time to remove personal data so we can store a copy indefinitely
    $user_email = $user['account']['emailaddress'];
    $user['account']['emailaddress'] = charrestore_gethash($user['account']['emailaddress']);
    $user['account']['replaceemail'] = charrestore_gethash($user['account']['replaceemail']);

    //set up the user's module preferences
    //add a hook for module to not include themselves (data privacy issue)
    $nosavemodules = modulehook('charrestore_nosavemodules', array());
    $prefsTable = Database::prefix('module_userprefs');
    $prefs = $conn->fetchAllAssociative(
        "SELECT * FROM {$prefsTable} WHERE userid = :acctid",
        ['acctid' => $acctid],
        ['acctid' => ParameterType::INTEGER]
    );
    foreach ($prefs as $pref) {
        if (! isset($user['prefs'][$pref['modulename']])) {
            $user['prefs'][$pref['modulename']] = array();
        }
        if (! isset($nosavemodules[$pref['modulename']])) {
            $user['prefs'][$pref['modulename']][$pref['setting']] = $pref['value'];
        }
    }

    //write the file
    $path = charrestore_getstorepath();
    $filename = $path . str_replace(" ", "_", $user['account']['login']) . "|" . $user['account']['acctid'] . "|" . date("Ymd");
    $fp = @fopen($filename, "w+");
    $failure = true;
    if ($fp) {
        if (fwrite($fp, serialize($user)) !== false) {
            $failure = false;
        }
        fclose($fp);
    }
    if ($failure === true) {
        $errstr = "Path not openable or error writing: " . $filename;
        ErrorHandler::Register(E_USER_ERROR, $errstr, __FILE__, __LINE__);
        return false;
    }

    $targetid = $user['account']['acctid'];
    $targetmail = $user_email;
    $subject = translate_mail(array("Your character %s", sanitize($user['account']['login'])), $targetid);
    $body = translate_mail(
        array(
            "Your character %s has been deleted by you or has expired on the game. `nIf you choose to reactivate this account in the future, note that it will be archived but without personal data. `n`nThis means, your email address and other personal data will be removed from the copy. If you want it restored, you need to recall your email adress or your password,only this will work!`n`nRegards,\nStaff of %s",
            sanitize($user['account']['login']), get_module_setting('adminname', 'charrestore')
        ),
        $targetid
    );
    $body = str_replace("`n", "</br>", $body);
    $result = charrestore_sendmail($targetmail, $body, $subject, get_module_setting('adminmail', 'charrestore'), get_module_setting('adminname', 'charrestore'));
    if ($result) {
        output("`\$The notification message has been sent!`n");
    } else {
        output("`\$There has been an error! The notification message was NOT sent!`n");
    }

    return true;
}

function charrestore_getstorepath()
{
    //returns a valid path name where snapshots are stored.
    $path = get_module_setting("snapshot_dir", "charrestore");
    if (substr($path, -1) != "/" && substr($path, -1) != "\\") {
        $path = $path . "/";
    }
    return $path;
}

function charrestore_is_blocked(): bool
{
    global $session;
    $list = (string) get_module_setting('blocked_acctids');
    $blocked = array_map('intval', array_filter(array_map('trim', explode(',', $list))));
    return in_array((int) $session['user']['acctid'], $blocked, true);
}

function charrestore_run(): void
{
    global $session;

    $hasaccess = (bool) get_module_pref('hasaccess');

    if (charrestore_is_blocked()) {
        page_header("Character Restore");
        output("`n`4You do not have access to the Character Restorer.`0");
        page_footer();
        return;
    }

    if (! $hasaccess) {
        SuAccess::check(SU_EDIT_USERS);
    }

    $retid = (int)httpget('returnpetition');
 //allow backlink to petition
    page_header("Character Restore");
    SuperuserNav::render();
    if ($retid > 0) {
            addnav("Petition");
            addnav("Return to petition", "viewpetition.php?op=view&id=$retid");
            $retnav = "&returnpetition=$retid";
    } else {
        $retnav = "";
    }
           addnav("Functions");
           addnav("Search", "runmodule.php?module=charrestore&op=list" . $retnav);
           addnav("Convert Email to Hash", "runmodule.php?module=charrestore&op=hashtest" . $retnav);

           addnav("Legacy Converts");
           addnav("Convert Email to Hash", "runmodule.php?module=charrestore&op=hashconvert" . $retnav);

    if (httpget("op") == "list") {
        output("Please note that only characters who have reached at least level %s in DK %s will have been saved!`n`n", get_module_setting("lvl_threshold", "charrestore"), get_module_setting("dk_threshold", "charrestore"));

        output("Search by login, email or both:`n");
        rawoutput("<form action='runmodule.php?module=charrestore&op=list$retnav' method='POST'>");
        addnav("", "runmodule.php?module=charrestore&op=list" . $retnav);
        rawoutput("<table><tr><td>");
        output("Character Login: ");
        $login = httppost('login');
        $login = is_string($login) ? stripslashes($login) : '';
        rawoutput("<input name='login' value=\"" . htmlentities($login, ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\"><br>");
        rawoutput("</td><td>");
        output("Character Email: ");
        $email = httppost('email');
        $email = is_string($email) ? stripslashes($email) : '';
        rawoutput("<input name='email' value=\"" . htmlentities($email, ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\"><br>");
        rawoutput("</td><td>");
        output("Display hash value for which email: ");
        $emailHashCheck = httppost('email_hashcheck');
        $emailHashCheck = is_string($emailHashCheck) ? stripslashes($emailHashCheck) : '';
        rawoutput("<input name='email_hashcheck' placeholder='for information only' value=\"" . htmlentities($emailHashCheck, ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\"><br>");
        rawoutput("</td></tr><tr><td>");
        output("After date: ");
        $startDate = httppost('start');
        $startDate = is_string($startDate) ? stripslashes($startDate) : '';
        rawoutput("<input name='start' placeholder='YYYY-MM-DD format' value=\"" . htmlentities($startDate, ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\"><br>");
        rawoutput("</td><td>");
        output("Before date: ");
        $endDate = httppost('end');
        $endDate = is_string($endDate) ? stripslashes($endDate) : '';
        rawoutput("<input name='end' placeholder='YYYY-MM-DD format' value=\"" . htmlentities($endDate, ENT_COMPAT, getsetting('charset', 'UTF-8')) . "\"><br>");
        rawoutput("</td></tr></table>");
        $submit = translate_inline("Submit");
        rawoutput("<input type='submit' value='$submit' class='button'>");
        rawoutput("</form>");
        //do the search.
        $login = httppost("login");
        $email = httppost("email");
        $email_hash = httppost("email_hashcheck");
        $start = httppost("start");
        $end = httppost("end");
        if ($start > "") {
            $start = strtotime($start);
        }
        if ($end > "") {
            $end = strtotime($end);
        }
        //save the findings
        $found = array();
        if ($email . $login . $start . $end > "") {
            if ($email_hash != "") {
                output("Informational hash: %s`n", charrestore_gethash($email_hash));
                output("Informational hash (lowercased): %s`n", charrestore_gethash(strtolower($email_hash)));
            }
            output("Informational hash (empty): %s`n", charrestore_gethash(""));
            if ($email != "") {
                $email = charrestore_gethash($email); // search for the hash
            }
            $path = charrestore_getstorepath();
            output("Chars saved in %s`n`n", $path);
            $d = dir($path);
            $count = 0;
            //fetch them to sort the directory
            while (($entry = $d->read()) !== false) {
                $new[] = $entry;
            }
            sort($new);
            //          while (($entry = $d->read())!==false){
            foreach ($new as $entry) {
                $e = explode("|", $entry);
                if (count($e) < 2) {
                    continue;
                }
                $name = str_replace("_", " ", $e[0]);
                if (count($e) == 2) {
                    $date = strtotime($e[1]);
                } else {
                    $date = strtotime($e[2]);
                }
                if ($start > "") {
                    if ($date < $start) {
                        continue;
                    }
                }
                if ($end > "") {
                    if ($date > $end) {
                        continue;
                    }
                }
                if ($login > "") {
                    if (strpos(strtolower($name), strtolower($login)) === false) {
                        continue;
                    }
                }
                //read the file
                $content = file_get_contents($path . "/" . $entry);
                //unpack
                $content = unserialize($content);
                $email_acc = $content['account']['emailaddress'];
                $acctid_acc = $content['account']['acctid'];
                $dks_acc = $content['account']['dragonkills'];
                if ($email > "") {
                    if (strpos(strtolower($email_acc), strtolower($email)) === false) {
                        continue;
                    }
                }
                //found one hit, now read the file - please leave this last entry
                $count++;
                $found[$name . "--" . $date] = array("name" => $name,"entry" => $entry,"date" => $date,"email" => $email_acc,"acctid" => $acctid_acc,"dragonkills" => $dks_acc);
                //              rawoutput("<a href='runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($entry)."'>$name</a> (".date("M d, Y",$date).")<br>");
                //              addnav("","runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($entry));
            }
            if ($count == 0) {
                output("No characters matching the specified criteria were found.");
            } else {
                //sort and output the findings
                ksort($found);
                foreach ($found as $row) {
                    rawoutput("<a href='runmodule.php?module=charrestore&op=beginrestore&file=" . rawurlencode($row['entry']) . $retnav . "'>" . $row['name'] . "</a> (" . date("M d, Y", $row['date']) . ") (" . $row['email'] . ") " . $row['dragonkills'] . " DKs ID " . $row['acctid'] . "<br>");
                    addnav("", "runmodule.php?module=charrestore&op=beginrestore&file=" . rawurlencode($row['entry']) . $retnav);
                }
            }
        }
    } elseif (httpget('op') == "hashtest") {
        output("Emailaddress to convert:`n");
        rawoutput("<form action='runmodule.php?module=charrestore&op=hashtest$retnav' method='POST'>");
        addnav("", "runmodule.php?module=charrestore&op=hashtest" . $retnav);
        rawoutput("<table><tr><td>");
        output("String: ");
        rawoutput("<input name='teststring'\"><br>");
        rawoutput("</td><td></tr></table>");
        $submit = translate_inline("Submit");
        rawoutput("<input type='submit' value='$submit' class='button'>");
        rawoutput("</form>");
        output("Hashed String: `\$%s", charrestore_gethash(httppost('teststring')));
    } elseif (httpget('op') == 'backup') {
        $acctid = (int) httpget('userid');
        if ($acctid > 0 && charrestore_create_snapshot($acctid)) {
            output('`^Character backup created successfully.`0');
        } else {
            output('`$Failed to create character backup.`0');
        }
    } elseif (httpget("op") == "beginrestore") {
        $file = httpget('file');
        $file = is_string($file) ? stripslashes($file) : '';
        $user = unserialize(join("", file(charrestore_getstorepath() . $file)));
        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('accounts');
        $row = $conn->fetchAssociative(
            "SELECT COUNT(acctid) AS c FROM {$table} WHERE login = :login",
            ['login' => (string) ($user['account']['login'] ?? '')],
            ['login' => ParameterType::STRING]
        );
        $countExistingLogin = (int) ($row['c'] ?? 0);
        rawoutput("<form action='runmodule.php?module=charrestore&op=finishrestore&file=" . rawurlencode($file) . $retnav . "' method='POST'>");
        addnav("", "runmodule.php?module=charrestore&op=finishrestore&file=" . rawurlencode($file) . $retnav);
        if ($countExistingLogin > 0) {
            output("`\$The user's login conflicts with an existing login in the system.");
            output("You will have to provide a new one, and you should probably think about giving them a new name after the restore.`n");
            output("`^New Login: ");
            rawoutput("<input name='newlogin'><br>");
        }

        $row = $conn->fetchAssociative(
            "SELECT COUNT(acctid) AS c FROM {$table} WHERE acctid = :acctid",
            ['acctid' => (int) ($user['account']['acctid'] ?? 0)],
            ['acctid' => ParameterType::INTEGER]
        );
        if ((int) ($row['c'] ?? 0) > 0) {
            output("`\$The user has already a char here ... you want to maybe restore an older version of it.`n`nYou have to DELETE it first in order to restore this one.");
            page_footer();
        }

        $yes = translate_inline("Do the restore");
        rawoutput("<input type='submit' value='$yes' class='button'>");

        output("`n`#Some user info:`0`n");
        $vars = array(
                "login" => "Login",
                "name" => "Name",
                "acctid" => "Account ID",
                "laston" => "Last On",
                "emailaddress" => "Email Passcode",
                "dragonkills" => "DKs",
                "level" => "Level",
                "gentimecount" => "Total hits",
                 );
        foreach ($vars as $key => $val) {
            output("`^$val: `#%s`n", $user['account'][$key]);
        }
        rawoutput("<input type='submit' value='$yes' class='button'>");
        rawoutput("</form>");
    } elseif (httpget("op") == "finishrestore") {
        $file = httpget('file');
        $file = is_string($file) ? stripslashes($file) : '';
        $user = unserialize(join("", file(charrestore_getstorepath() . $file)));
        $newlogin = (httppost('newlogin') > '' ? httppost('newlogin') : $user['account']['login']);
        $user = unserialize(join("", file(charrestore_getstorepath() . $file)));
        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('accounts');
        $rows = $conn->fetchAllAssociative(
            "SELECT acctid FROM {$table} WHERE login = :login",
            ['login' => (string) $newlogin],
            ['login' => ParameterType::STRING]
        );
        $count = count($rows);
        if ($count > 0) {
            $ids = array();
            foreach ($rows as $row) {
                $ids[] = $row['acctid'];
            }
            $link = "runmodule.php?module=charrestore&op=beginrestore&file=" . rawurlencode($file);
            output(
                "Hm. Login '%s' seems to exist already as Account-ID %s. If you want to go on, you need to give out a new login <a href='%s'>here</a>",
                $newlogin,
                implode(',', $ids),
                $link,
                true
            );
        } else {
            if (httppost("newlogin") > "") {
                $user['account']['login'] = httppost('newlogin');
            }
            $result = $conn->executeQuery('DESCRIBE ' . $table);
            $known_columns = array();
            $column_types = array();
            while ($row = $result->fetchAssociative()) {
                $known_columns[$row['Field']] = true;
                $column_types[$row['Field']] = $row['Type'];
            }

            //sanity fill ups due to empty values and no default values set
            $default_fill = array(
                    "allowednavs",
                    "lastip",
                    );
            foreach ($default_fill as $defval) {
                if (!array_key_exists($defval, $user['account'])) {
                    //set
                    $known_columns[$defval] = true;
                    $user['account'][$defval] = "";
                }
            }
            //end
            $em       = \Lotgd\Doctrine\Bootstrap::getEntityManager();
            $account  = new \Lotgd\Entity\Account();
            $metadata = $em->getClassMetadata(\Lotgd\Entity\Account::class);
            $desiredId = $user['account']['acctid'] ?? null;

            foreach ($user['account'] as $key => $val) {
                if (! isset($known_columns[$key])) {
                    output("`2Dropping the column `^%s`n", $key);
                    continue;
                }

                if (! $metadata->hasField($key)) {
                    continue;
                }

                if ($key === 'acctid') {
                    continue;
                }

                if ($key === 'laston') {
                    $metadata->setFieldValue($account, $key, new \DateTimeImmutable('-1 day'));
                    continue;
                }

                if ($key === 'sex') {
                    $val = (int) $val;
                    if (! in_array($val, [SEX_MALE, SEX_FEMALE], true)) {
                        $val = SEX_MALE;
                    }
                    $metadata->setFieldValue($account, $key, $val);
                    continue;
                }

                if (str_contains($column_types[$key], 'date') || str_contains($column_types[$key], 'time')) {
                    if ($val < DATETIME_DATEMIN) {
                        $val = DATETIME_DATEMIN; // fix old time stamps
                    }
                    $metadata->setFieldValue($account, $key, new \DateTimeImmutable($val));
                    continue;
                }

                if (str_contains($column_types[$key], 'int')) {
                    $metadata->setFieldValue($account, $key, (int) $val);
                    continue;
                }

                $metadata->setFieldValue($account, $key, $val);
            }

            $em->persist($account);
            $em->flush();

            $id = (int) $account->getAcctid();
            $idReassigned = false;
            $originalId   = (int) $desiredId;
            if (is_numeric($desiredId) && (int) $desiredId !== $id) {
                $conn = Database::getDoctrineConnection();
                try {
                    $rows = $conn->update(
                        Database::prefix('accounts'),
                        ['acctid' => (int) $desiredId],
                        ['acctid' => $id]
                    );
                    if ($rows > 0) {
                        $id = (int) $desiredId;
                    } else {
                        $idReassigned = true;
                    }
                } catch (UniqueConstraintViolationException $e) {
                    // old ID already taken; keep $id
                    $idReassigned = true;
                }
                if ((int) $desiredId !== $id) {
                    $idReassigned = true;
                }
            }

            if ($id > 0) {
                if ($session['user']['superuser'] & SU_EDIT_USERS == SU_EDIT_USERS) {
                    addnav("Edit the restored user", "user.php?op=edit&userid=$id" . $retnav);
                }
                output("`#The account was restored.`n");
                output("`#Now working on module preferences.`n");
                foreach ($user['prefs'] as $moduleKey => $values) {
                    if (is_object($moduleKey)) {
                        if (property_exists($moduleKey, 'modulename') && is_string($moduleKey->modulename)) {
                            $modulename = $moduleKey->modulename;
                        } else {
                            continue;
                        }
                    } elseif (is_string($moduleKey)) {
                        $modulename = $moduleKey;
                    } else {
                        continue;
                    }

                    output("`3Module: `2%s`3...`n", $modulename);

                    if (is_module_installed($modulename)) {
                        foreach ($values as $prefname => $value) {
                            set_module_pref($prefname, $value, $modulename, $id);
                        }
                    } else {
                        output("`\$Skipping prefs for module `^%s`\$ because this module is not currently installed.`n", $modulename);
                    }
                }
                output("`#The preferences were restored.`n");
                if ($idReassigned) {
                    output("`#The original account ID `^%s`# could not be used.`n", $originalId);
                    output("`#A new account ID `^%s`# has been assigned.`n", $id);
                    output("`#Preferences have been applied to the new ID.`n");
                }
                // sadly not possible anymore. we do not know the emailaddress (data privacy regulation)
                /*                  $targetid=$user['account']['acctid'];
                                    $targetmail=$user['account']['emailaddress'];
                                    $subject=translate_mail(array("Your character %s",sanitize($user['account']['login'])),$targetid);
                                    $body=translate_mail(array(
                                    "Your character %s has been restored. You may now login to our site and the restored character.`n`nIf you do not remember your password, use the 'Forgotten Password' link on the homepage to get login and change it.`n`nRegards,\nStaff",
                                    sanitize($user['account']['login'])),
                                    $targetid);
                                    $body = str_replace("`n","\n",$body);
                                    if (get_module_setting('notifymail')) {
                                    $result=charrestore_sendmail($targetmail,$body,$subject,get_module_setting('adminmail'),get_module_setting('adminname'));
                                    if ($result) {
                                    output("`\$The notification message has been sent!`n");
                                    } else {
                                    output("`\$There has been an error! The notification message was NOT sent!`n");
                                    }
                                    }
                 */
                rawoutput("<h2>");
                output("`\$Please keep in mind the restored email address is not usable and you need to set it or tell the petitioner to login and set it.`nA \"Forgotten Password\"-Request won't enable access to the account!`n`0");
                rawoutput("</h2>");
            } else {
                output("`\$Something funky has happened, preventing this account from correctly being created.");
                output("I'm sorry, you may have to recreate this account by hand.");
            }
        }
    } elseif (httpget('op') == "hashconvert") {
        $convert = (int)httpget('convert'); // == 1 if we want to convert
        $path = charrestore_getstorepath();
        $d = dir($path);
        $count = 0;
        //fetch them to sort the directory
        while (($entry = $d->read()) !== false) {
            $new[] = $entry;
        }
        sort($new);
        $totalcount = 0;
        //          while (($entry = $d->read())!==false){
        foreach ($new as $entry) {
            $e = explode("|", $entry);
            if (count($e) < 2) {
                continue;
            }
            $totalcount++;
            $name = str_replace("_", " ", $e[0]);
            if (count($e) == 2) {
                $date = strtotime($e[1]);
            } else {
                $date = strtotime($e[2]);
            }
            //read the file
            $content = file_get_contents($path . "/" . $entry);
            //unpack
            $content = unserialize($content);
            $email_acc = $content['account']['emailaddress'];
            if (strlen($email_acc) == strlen(charrestore_gethash('test')) && strpos($email_acc, '@') === false) {
                continue; //already hashed and salted or superlong email
            } else {
                //found one hit, now count up and convert if necessary
                $dks_acc = $content['account']['dragonkills'];
                if ($convert == 1) {
                    //convert this one
                    $content['account']['emailaddress'] = charrestore_gethash($email_acc);
                    $fp = @fopen($path . "/" . $entry, "w+");
                    if ($fp) {
                        if (
                            fwrite(
                                $fp,
                                serialize($content)
                            ) !== false
                        ) {
                            $failure = false;
                        } else {
                            $failure = true;
                        }
                        fclose($fp);
                    }
                    if ($failure == true || !is_writeable($parth . "/" . $entry)) {
                        output("Could not be written: %s`n", $entry);
                    }
                }
                $count++;
                $found[$name . "--" . $date] = array("name" => $name,"entry" => $entry,"date" => $date,"email" => $email_acc,"acctid" => $acctid_acc,"dragonkills" => $dks_acc); //not used but collected
            }
        }
        if ($convert == 1) {
            output("`q%s Chars saved in total. `n`x%s Chars have been converted.`n`n", $totalcount, $count);
        } else {
            output("`q%s Chars saved in total. `n", $totalcount);
        }
        addnav("Convert");
        if ($count > 0) {
            // we need to convert
            output("`2%s Chars have `\$NO SALTED PASSWORD HASH`2 and should be converted now.`n`n", $count);
            output("`\$In case you choose to convert, we advise to backup your data first in case something goes awry during this!!!");
            addnav("Convert now", "runmodule.php?module=charrestore&op=hashconvert&convert=1");
        } else {
            output("`xNo conversion necessary. All emails are salted and hashed.");
            addnav("Convert now", "");
        }
    }
     page_footer();
}

function charrestore_sendmail($to, $body, $subject, $fromaddress, $fromname, $attachments = false)
{
        $to_array = array($to => $to);
        $from_array = array($fromaddress => $fromname);
        $cc_array = false;
        return \Lotgd\Mail::send($to_array, $body, $subject, $from_array, $cc_array, "text/html");
}

function charrestore_gethash($value)
{
    return hash('sha512', $value . get_module_setting('email_hash_salt', 'charrestore'));
}
