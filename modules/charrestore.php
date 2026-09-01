<?php

declare(strict_types=1);

/**
 * Administrative module to snapshot and restore player accounts.
 *
 * Shared-library contract: charrestore/lib owns snapshot validation, persistence,
 * and restore mechanics. Dependent reset modules retain their settings, hooks,
 * directories, and legacy archive names while gaining preference-only overwrite.
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\MySQL\Database;
use Lotgd\Forms;
use Lotgd\GameLog;
use Lotgd\Settings;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Connection;

function charrestore_getmoduleinfo(): array
{
    $info = array(
            "name" => "Character Restorer",
            "category" => "Administrative",
            "version" => "2.0",
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

/** Build the explicit Character Restorer shared-library context. */
function charrestore_context(): array
{
    return array(
        'owner' => 'charrestore',
        'snapshot_dir' => (string) get_module_setting('snapshot_dir', 'charrestore'),
        'filename_strategy' => 'charrestore',
        'log_category' => 'charrestore',
        'privacy_filter' => true,
        'deletion_notification' => true,
        'excluded_modules_hook' => true,
        'email_search' => true,
    );
}

/** Load the module-owned shared snapshot and restore API. */
function charrestore_load_library(): bool
{
    $snapshotLibrary = __DIR__ . '/charrestore/lib/snapshot.php';
    $restoreLibrary = __DIR__ . '/charrestore/lib/restore.php';
    if (!is_file($snapshotLibrary) || !is_file($restoreLibrary)) {
        return false;
    }
    require_once $snapshotLibrary;
    require_once $restoreLibrary;
    return function_exists('charrestore_snapshot_create') && function_exists('charrestore_restore_admin_flow');
}

/** Create a privacy-filtered Character Restorer snapshot. */
function charrestore_create_snapshot(int $acctid): bool
{
    if (!charrestore_load_library()) {
        GameLog::log('Character Restorer shared library is unavailable.', 'charrestore');
        return false;
    }
    return charrestore_snapshot_create($acctid, charrestore_context());
}

/** Send the historical deletion notification after a successful snapshot. */
function charrestore_send_deletion_notification(array $snapshot, string $targetmail): void
{
    $account = $snapshot['account'];
    $targetid = (int) ($account['acctid'] ?? 0);
    $login = (string) ($account['login'] ?? '');
    $subject = translate_mail(array('Your character %s', sanitize($login)), $targetid);
    $body = translate_mail(array(
        "Your character %s has been deleted by you or has expired on the game. `nIf you choose to reactivate this account in the future, note that it will be archived but without personal data. `n`nThis means, your email address and other personal data will be removed from the copy. If you want it restored, you need to recall your email adress or your password,only this will work!`n`nRegards,
Staff of %s",
        sanitize($login),
        get_module_setting('adminname', 'charrestore'),
    ), $targetid);
    $sent = charrestore_sendmail($targetmail, str_replace('`n', '</br>', $body), $subject,
        get_module_setting('adminmail', 'charrestore'), get_module_setting('adminname', 'charrestore'));
    output($sent ? '`$The notification message has been sent!`n' : '`$There has been an error! The notification message was NOT sent!`n');
}

function charrestore_notify_admin_snapshot_failure(string $path, string $reason): void
{
    $message = sprintf('Character snapshot failure: %s Path: %s', $reason, $path);
    GameLog::log($message, 'charrestore');

    $settings = Settings::getInstance();
    $adminmail = $settings->getSetting('gameadminemail', 'postmaster@localhost');
    if (! $adminmail) {
        return;
    }

    $subject = 'Character snapshot failed';
    $body = nl2br(sprintf("A character snapshot could not be saved.\nReason: %s\nPath: %s", $reason, $path));
    charrestore_sendmail($adminmail, $body, $subject, $adminmail, $adminmail);
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

    $operation = (string) httpget('op');
    if (in_array($operation, array('list', 'beginrestore', 'finishrestore'), true)) {
        if (!charrestore_load_library()) {
            GameLog::log('Character Restorer shared library is unavailable.', 'charrestore');
            output('`$Character Restorer shared services are unavailable.`0');
        } else {
            charrestore_restore_admin_flow(
                charrestore_context(),
                $operation,
                'runmodule.php?module=charrestore' . $retnav
            );
        }
    } elseif ($operation === 'hashtest') {
        output('Emailaddress to convert:`n');
        rawoutput("<form action='runmodule.php?module=charrestore&op=hashtest{$retnav}' method='POST'>");
        addnav('', 'runmodule.php?module=charrestore&op=hashtest' . $retnav);
        rawoutput("<input name='teststring'><input type='submit' class='button'></form>");
        output('Hashed String: `$%s', charrestore_gethash((string) httppost('teststring')));
    } elseif ($operation === 'backup') {
        $acctid = (int) httpget('userid');
        output($acctid > 0 && charrestore_create_snapshot($acctid)
            ? '`^Character backup created successfully.`0' : '`$Failed to create character backup.`0');
    } elseif ($operation === 'hashconvert') {
        // Hash conversion also consumes validated snapshots, so it must establish
        // the shared API independently of the list/preview/restore operations.
        if (!charrestore_load_library()) {
            GameLog::log('Character Restorer shared library is unavailable.', 'charrestore');
            output('`$Character Restorer shared services are unavailable.`0');
            page_footer();
            return;
        }
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
            $content = charrestore_snapshot_load($entry, charrestore_context());
            if ($content === null) {
                continue;
            }
            $email_acc = (string) ($content['account']['emailaddress'] ?? '');
            if (strlen($email_acc) == strlen(charrestore_gethash('test')) && strpos($email_acc, '@') === false) {
                continue; //already hashed and salted or superlong email
            } else {
                //found one hit, now count up and convert if necessary
                $dks_acc = $content['account']['dragonkills'];
                if ($convert == 1) {
                    //convert this one
                    $content['account']['emailaddress'] = charrestore_gethash($email_acc);
                    $payload = serialize($content);
                    $written = file_put_contents($path . $entry, $payload, LOCK_EX);
                    if ($written !== strlen($payload)) {
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

/**
 * Restores module preferences for an account using SQL upsert semantics.
 *
 * We intentionally avoid deleting rows from module_userprefs because newer keys
 * may have been introduced since the backup was created; those keys must survive.
 *
 * @param Connection $conn  Doctrine DBAL connection.
 * @param int        $acctid Target account ID receiving the preferences.
 * @param array      $prefs  Snapshot module preferences grouped by module then setting.
 *
 * @return array{unique_key_ready: bool, used_fallback: bool, applied: bool}
 */
function charrestore_restore_prefs_upsert(Connection $conn, int $acctid, array $prefs): array
{
    $uniqueKeyReady = charrestore_can_use_userprefs_upsert_key($conn);
    $hadErrors = false;
    if ($uniqueKeyReady) {
        $prefsTable = Database::prefix('module_userprefs');
        try {
            // Keep upsert writes atomic so prefs are restored all-or-nothing.
            $conn->beginTransaction();
            foreach ($prefs as $moduleKey => $values) {
                $modulename = charrestore_extract_modulename($moduleKey);
                if ($modulename === null) {
                    continue;
                }

                if (! is_module_installed($modulename)) {
                    output("`\$Skipping prefs for module `^%s`\$ because this module is not currently installed.`n", $modulename);
                    continue;
                }
                if (! is_array($values)) {
                    $hadErrors = true;
                    GameLog::log(
                        sprintf('charrestore: malformed prefs payload for module %s during upsert restore.', $modulename),
                        'charrestore'
                    );
                    output("`\$Skipping malformed prefs for module `^%s`\$ (expected key/value array).`n", $modulename);
                    continue;
                }

                output("`3Module: `2%s`3...`n", $modulename);
                foreach ($values as $prefname => $value) {
                    // Upsert updates only the matching key, preserving extra keys already on account.
                    $conn->executeStatement(
                        "INSERT INTO {$prefsTable} (userid, modulename, setting, value)
                         VALUES (:userid, :modulename, :setting, :value)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)",
                        [
                            'userid' => $acctid,
                            'modulename' => (string) $modulename,
                            'setting' => (string) $prefname,
                            'value' => (string) $value,
                        ],
                        [
                            'userid' => ParameterType::INTEGER,
                            'modulename' => ParameterType::STRING,
                            'setting' => ParameterType::STRING,
                            'value' => ParameterType::STRING,
                        ]
                    );
                }
            }
            $conn->commit();
            return ['unique_key_ready' => true, 'used_fallback' => false, 'applied' => ! $hadErrors];
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            GameLog::log(
                sprintf('charrestore: transactional upsert failed; switching to fallback restore: %s', $e->getMessage()),
                'charrestore'
            );
        }
    }

    // Fallback preserves pre-existing behavior if upsert prerequisites are unavailable.
    $applied = charrestore_restore_prefs_fallback($acctid, $prefs);
    return ['unique_key_ready' => $uniqueKeyReady, 'used_fallback' => true, 'applied' => $applied];
}

/**
 * Restores preferences using legacy per-key writes.
 *
 * This fallback is used only when upsert prerequisites are unavailable; it still
 * avoids destructive deletes so newer preference keys are preserved.
 */
function charrestore_restore_prefs_fallback(int $acctid, array $prefs): bool
{
    $allApplied = true;
    foreach ($prefs as $moduleKey => $values) {
        $modulename = charrestore_extract_modulename($moduleKey);
        if ($modulename === null) {
            continue;
        }

        if (! is_module_installed($modulename)) {
            output("`\$Skipping prefs for module `^%s`\$ because this module is not currently installed.`n", $modulename);
            continue;
        }
        if (! is_array($values)) {
            $allApplied = false;
            GameLog::log(
                sprintf('charrestore: malformed prefs payload for module %s during fallback restore.', $modulename),
                'charrestore'
            );
            output("`\$Skipping malformed prefs for module `^%s`\$ (expected key/value array).`n", $modulename);
            continue;
        }

        output("`3Module: `2%s`3...`n", $modulename);
        foreach ($values as $prefname => $value) {
            try {
                set_module_pref((string) $prefname, (string) $value, $modulename, $acctid);
            } catch (\Throwable $e) {
                $allApplied = false;
                GameLog::log(
                    sprintf(
                        'charrestore: failed fallback pref restore for module %s setting %s: %s',
                        $modulename,
                        (string) $prefname,
                        $e->getMessage()
                    ),
                    'charrestore'
                );
            }
        }
    }

    return $allApplied;
}

/**
 * Extracts a module name from legacy snapshot preference keys.
 *
 * Some old snapshots may contain keys as objects with a modulename property,
 * while newer snapshots use plain string keys.
 *
 * @param mixed $moduleKey
 */
function charrestore_extract_modulename($moduleKey): ?string
{
    if (is_object($moduleKey)) {
        if (property_exists($moduleKey, 'modulename') && is_string($moduleKey->modulename)) {
            return $moduleKey->modulename;
        }
        return null;
    }

    if (is_string($moduleKey)) {
        return $moduleKey;
    }

    return null;
}

/**
 * Checks whether module_userprefs has an exact unique key for upsert safety.
 *
 * ON DUPLICATE KEY UPDATE needs a unique key that matches exactly the preference
 * identity tuple (userid, modulename, setting) and no extra columns.
 * Runtime schema changes are intentionally avoided here; database migrations
 * must add/maintain this index during deployment, not during restore actions.
 */
function charrestore_can_use_userprefs_upsert_key(Connection $conn): bool
{
    try {
        $prefsTable = Database::prefix('module_userprefs');
        $indexes = $conn->fetchAllAssociative("SHOW INDEX FROM {$prefsTable}");
    } catch (\Throwable $e) {
        GameLog::log(
            sprintf('charrestore: failed to inspect module_userprefs indexes for upsert check: %s', $e->getMessage()),
            'charrestore'
        );
        return false;
    }

    if (charrestore_has_exact_userprefs_unique_key($indexes)) {
        return true;
    }

    GameLog::log(
        'charrestore: module_userprefs unique key (userid, modulename, setting) missing; using fallback preference restore.',
        'charrestore'
    );
    return false;
}

/**
 * Checks whether any unique index is exactly the 3-key preference identity.
 *
 * @param array<int, array<string, mixed>> $indexes
 */
function charrestore_has_exact_userprefs_unique_key(array $indexes): bool
{
    $uniqueIndexes = [];
    foreach ($indexes as $index) {
        if ((int) ($index['Non_unique'] ?? 1) !== 0) {
            continue;
        }
        $keyName = (string) ($index['Key_name'] ?? '');
        $seq = (int) ($index['Seq_in_index'] ?? 0);
        $column = strtolower((string) ($index['Column_name'] ?? ''));
        $subPart = $index['Sub_part'] ?? null;
        $isFullLength = $subPart === null || $subPart === '' || (int) $subPart === 0;
        if (! isset($uniqueIndexes[$keyName])) {
            $uniqueIndexes[$keyName] = [];
        }
        $uniqueIndexes[$keyName][$seq] = [
            'column' => $column,
            'full_length' => $isFullLength,
        ];
    }

    foreach ($uniqueIndexes as $columnsBySeq) {
        ksort($columnsBySeq);
        $entries = array_values($columnsBySeq);
        $columns = array_column($entries, 'column');
        $allFullLength = ! in_array(false, array_column($entries, 'full_length'), true);
        // Require an exact 3-column unique key to ensure duplicate detection
        // is based on userid+modulename+setting only, with no prefix indexing.
        if (
            count($entries) === 3 &&
            $allFullLength &&
            count(array_unique($columns)) === 3 &&
            in_array('userid', $columns, true) &&
            in_array('modulename', $columns, true) &&
            in_array('setting', $columns, true)
        ) {
            return true;
        }
    }

    return false;
}
