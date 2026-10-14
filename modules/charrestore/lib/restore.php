<?php

declare(strict_types=1);

/** Shared restoration and administrative rendering for Character Restorer dependants. */

/** Return the host Doctrine connection used for parameterized restore operations. */
function charrestore_restore_connection(): object
{
    return \Lotgd\MySQL\Database::getDoctrineConnection();
}

/**
 * Detect account-id and login conflicts for a snapshot.
 *
 * @param array<string, mixed> $account Snapshot account data.
 * @return array{acctid_exists:bool, login_ids:array<int, int>}
 */
function charrestore_restore_conflicts(array $account, string $login = ''): array
{
    $connection = charrestore_restore_connection();
    $table = db_prefix('accounts');
    $login = $login !== '' ? $login : (string) ($account['login'] ?? '');
    $rows = $connection->fetchAllAssociative(
        "SELECT acctid FROM {$table} WHERE login = :login",
        array('login' => $login)
    );
    $idRow = $connection->fetchAssociative(
        "SELECT COUNT(acctid) AS c FROM {$table} WHERE acctid = :acctid",
        array('acctid' => (int) ($account['acctid'] ?? 0))
    );
    return array(
        'acctid_exists' => (int) ($idRow['c'] ?? 0) > 0,
        'login_ids' => array_map('intval', array_column($rows, 'acctid')),
    );
}

/**
 * Restore preference keys with an upsert, falling back to the host preference API.
 * Unrelated keys are deliberately never deleted.
 *
 * Inside a transaction the caller owns, the reasons a preference failed are
 * returned in `problems` instead of being logged, since the rollback would
 * take the log entry with it.
 *
 * @param array<string, mixed> $prefs Module-keyed preference payload.
 * @param array<string, mixed> $context Shared library context.
 * @return array{applied:bool, used_fallback:bool, problems:list<string>}
 */
function charrestore_restore_preferences(int $acctid, array $prefs, array $context): array
{
    // Character Restorer exposes the optimized upsert when its module entry point is loaded.
    if (function_exists('charrestore_restore_prefs_upsert')) {
        return charrestore_restore_prefs_upsert(charrestore_restore_connection(), $acctid, $prefs);
    }
    // Inside the shared overwrite transaction a game-log row would be rolled
    // back with the writes it explains; the overwrite logs these afterwards.
    $connection = charrestore_restore_connection();
    $shared = method_exists($connection, 'isTransactionActive') && $connection->isTransactionActive();
    $problems = array();
    $complete = true;
    foreach ($prefs as $module => $values) {
        if (!is_string($module) || $module === '' || !is_array($values)) {
            $complete = false;
            continue;
        }
        if (!is_module_installed($module)) {
            output("`\$Skipping prefs for module `^%s`\$ because this module is not currently installed.`n", $module);
            continue;
        }
        foreach ($values as $setting => $value) {
            if (!is_string($setting) || (!is_scalar($value) && $value !== null)) {
                $complete = false;
                continue;
            }
            try {
                set_module_pref($setting, (string) $value, $module, $acctid);
            } catch (Throwable $exception) {
                $complete = false;
                $problem = "Preference restore failed for {$module}/{$setting}: " . $exception->getMessage();
                if ($shared) {
                    $problems[] = $problem;
                } else {
                    charrestore_snapshot_log($context, $problem);
                }
            }
        }
    }
    return array('applied' => $complete, 'used_fallback' => true, 'problems' => $problems);
}

/**
 * Read the live accounts schema used to validate archived account fields.
 *
 * @return array<string, string> Column names mapped to lowercase SQL types.
 */
function charrestore_restore_account_schema(): array
{
    $connection = charrestore_restore_connection();
    $schema = $connection->executeQuery('DESCRIBE ' . db_prefix('accounts'));
    $types = array();
    while (($column = $schema->fetchAssociative()) !== false) {
        $types[(string) $column['Field']] = strtolower((string) $column['Type']);
    }

    return $types;
}

/**
 * Filter and normalize snapshot values against the live accounts schema.
 *
 * This is shared by inserts and overwrites so both paths retain the same date,
 * numeric, sex, and legacy-default compatibility behavior. Snapshot fields that
 * do not exist in the current schema are deliberately omitted.
 *
 * @param array<string, mixed> $account Snapshot account data.
 * @param array<string, string> $types Live account column types.
 * @return array<string, mixed> Validated values keyed by live column name.
 */
function charrestore_restore_normalize_account(array $account, array $types): array
{
    foreach (array('allowednavs', 'lastip') as $defaultColumn) {
        if (isset($types[$defaultColumn]) && !array_key_exists($defaultColumn, $account)) {
            $account[$defaultColumn] = '';
        }
    }

    $normalized = array();
    foreach ($account as $key => $value) {
        if (!is_string($key) || !isset($types[$key])) {
            output('`2Dropping the unknown snapshot column `^%s`0`n', (string) $key);
            continue;
        }
        $type = $types[$key];
        if ($key === 'laston') {
            $value = date('Y-m-d H:i:s', strtotime('-1 day'));
        } elseif ($key === 'sex') {
            $value = in_array((int) $value, array(SEX_MALE, SEX_FEMALE), true) ? (int) $value : SEX_MALE;
        } elseif (str_contains($type, 'int')) {
            $value = (int) $value;
        } elseif (str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'decimal')) {
            $value = (float) $value;
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            $timestamp = strtotime((string) $value);
            $minimum = strtotime(DATETIME_DATEMIN);
            $timestamp = $timestamp === false || ($minimum !== false && $timestamp < $minimum)
                ? ($minimum ?: time())
                : $timestamp;
            $value = str_starts_with($type, 'date(') || $type === 'date'
                ? date('Y-m-d', $timestamp)
                : (str_starts_with($type, 'time(') || $type === 'time'
                    ? date('H:i:s', $timestamp)
                    : date('Y-m-d H:i:s', $timestamp));
        } elseif ($value !== null) {
            $value = (string) $value;
        }
        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * Insert a complete account after normalizing values against the live account schema.
 *
 * @param array<string, mixed> $account Snapshot account data.
 * @param array<string, mixed> $context Shared library context.
 * @return int Restored account id, or zero on failure.
 */
function charrestore_restore_account(array $account, array $context): int
{
    $connection = charrestore_restore_connection();
    $table = db_prefix('accounts');
    $account = charrestore_restore_normalize_account($account, charrestore_restore_account_schema());
    $columns = $holders = $parameters = array();
    foreach ($account as $key => $value) {
        $parameter = 'value_' . count($parameters);
        $columns[] = '`' . str_replace('`', '``', $key) . '`';
        $holders[] = ':' . $parameter;
        $parameters[$parameter] = $value;
    }
    if ($columns === array()) {
        return 0;
    }
    try {
        $connection->executeStatement(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')',
            $parameters
        );
    } catch (Throwable $exception) {
        charrestore_snapshot_log($context, 'Account restore failed: ' . $exception->getMessage());
        return 0;
    }
    $desired = (int) ($account['acctid'] ?? 0);
    return $desired > 0 ? $desired : (int) $connection->lastInsertId();
}

/**
 * Whether writes to the accounts and preference tables can be rolled back.
 *
 * DBAL opens a transaction whatever the tables are stored in, but MySQL does
 * not roll back a MyISAM table, and the schema synchronizer keeps the engine
 * of a table that already exists: an installation upgraded from an old release
 * can still have one. Anything that cannot be confirmed counts as no.
 */
function charrestore_restore_tables_transactional(object $connection): bool
{
    foreach (array('beginTransaction', 'commit', 'rollBack', 'isTransactionActive', 'fetchAllAssociative') as $method) {
        if (!method_exists($connection, $method)) {
            return false;
        }
    }
    $tables = array(db_prefix('accounts'), db_prefix('module_userprefs'));
    try {
        $rows = $connection->fetchAllAssociative(
            'SELECT t.TABLE_NAME AS name, e.TRANSACTIONS AS transactions'
                . ' FROM information_schema.TABLES t'
                . ' JOIN information_schema.ENGINES e ON e.ENGINE = t.ENGINE'
                . ' WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME IN (:accounts, :prefs)',
            array('accounts' => $tables[0], 'prefs' => $tables[1])
        );
    } catch (Throwable $exception) {
        return false;
    }
    $transactional = array();
    foreach ($rows as $row) {
        $transactional[(string) ($row['name'] ?? '')] = strtoupper((string) ($row['transactions'] ?? '')) === 'YES';
    }
    foreach ($tables as $table) {
        if (($transactional[$table] ?? false) !== true) {
            return false;
        }
    }
    return true;
}

/**
 * File a refused full-account overwrite in the security log.
 *
 * Refusing to replace a live account is a denied admin action, so it is filed
 * under the administrator who asked, with the account they named and why.
 *
 * @param array<string, mixed> $context Shared library context.
 */
function charrestore_restore_overwrite_refused(int $acctid, string $reason, array $context): void
{
    global $session;
    \Lotgd\SecurityLog::event(
        'Character Restorer refused to overwrite an existing account',
        array(
            'target_acctid' => $acctid,
            'reason' => $reason,
            'owner' => (string) ($context['owner'] ?? 'charrestore'),
        ),
        (int) ($session['user']['acctid'] ?? 0),
        \Lotgd\GameLog::SEVERITY_WARNING
    );
}

/**
 * Destructively restore account fields and preferences over the matching ID.
 *
 * The archived acctid is used only as the immutable update target and is never
 * included among mutable values. The shared connection transaction keeps the
 * account row and preference restore atomic. On tables that cannot roll back
 * (MyISAM) it still runs, but a failure may leave the account partly written,
 * and the log and the security log say so.
 *
 * The target is logged out, whatever the snapshot says: a session still open on
 * it must not carry on with the restored credentials and superuser flags. The
 * administrator's own account is refused, since the save at the end of their own
 * request would write the account as it was loaded back over the snapshot.
 *
 * @param array<string, mixed> $account Snapshot account data.
 * @param array<string, mixed> $prefs Snapshot module preferences.
 * @param array<string, mixed> $context Shared library context.
 *
 * @return bool True once the overwrite is committed, even if the security log
 *              could not take its entry; false when it failed, which leaves the
 *              account unchanged only where the tables roll back.
 */
function charrestore_restore_overwrite_account(array $account, array $prefs, array $context): bool
{
    global $session;
    $acctid = (int) ($account['acctid'] ?? 0);
    if ($acctid <= 0) {
        charrestore_snapshot_log($context, 'Full account overwrite rejected: snapshot has no valid account ID.');
        return false;
    }
    if ($acctid === (int) ($session['user']['acctid'] ?? 0)) {
        charrestore_restore_overwrite_refused($acctid, 'it is the account running the restore', $context);
        return false;
    }

    try {
        $connection = charrestore_restore_connection();
        $schema = charrestore_restore_account_schema();
        $values = charrestore_restore_normalize_account($account, $schema);
        if (array_key_exists('loggedin', $schema)) {
            $values['loggedin'] = 0;
        }
    } catch (Throwable $exception) {
        charrestore_snapshot_log(
            $context,
            "Full account overwrite preparation failed for acctid {$acctid}: " . $exception->getMessage()
        );
        return false;
    }
    unset($values['acctid']);
    if ($values === array()) {
        charrestore_snapshot_log($context, "Full account overwrite for acctid {$acctid} has no valid mutable fields.");
        return false;
    }

    $assignments = array();
    $parameters = array('target_acctid' => $acctid);
    foreach ($values as $column => $value) {
        $parameter = 'value_' . count($assignments);
        $assignments[] = '`' . str_replace('`', '``', $column) . '` = :' . $parameter;
        $parameters[$parameter] = $value;
    }

    // On MyISAM the transaction below is a formality: what was written before
    // a failure stays. The restore still runs; a failure is reported as such.
    $atomic = charrestore_restore_tables_transactional($connection);

    try {
        $connection->beginTransaction();
        // Locked until the commit, so the account cannot be deleted between
        // this check and the writes, leaving preferences without an account.
        $target = $connection->fetchAssociative(
            'SELECT acctid FROM ' . db_prefix('accounts') . ' WHERE acctid = :target_acctid FOR UPDATE',
            array('target_acctid' => $acctid)
        );
        if ($target === false) {
            throw new RuntimeException('The target account no longer exists.');
        }
        $connection->executeStatement(
            'UPDATE ' . db_prefix('accounts') . ' SET ' . implode(', ', $assignments)
                . ' WHERE acctid = :target_acctid',
            $parameters
        );
        $preferenceResult = charrestore_restore_preferences($acctid, $prefs, $context);
        if (!$preferenceResult['applied']) {
            // The reasons were held back from the game log, which would have
            // lost them in the rollback; this exception carries them past it.
            throw new RuntimeException(implode(' ', array_merge(
                array('One or more archived preferences could not be restored.'),
                (array) ($preferenceResult['problems'] ?? array())
            )));
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ($atomic) {
            charrestore_snapshot_log(
                $context,
                "Full account overwrite failed for acctid {$acctid}; changes were rolled back: " . $exception->getMessage()
            );
            return false;
        }
        // Credentials or superuser flags may already be the snapshot's, so
        // this must reach the administrator whatever the logs do. The security
        // event goes first: SecurityLog writes PHP's error log before the game
        // log, so the event is on record even when the game log refuses it.
        $failure = "Full account overwrite failed for acctid {$acctid}; the tables cannot roll back, so part of"
            . ' the snapshot may already be written: ' . $exception->getMessage();
        try {
            \Lotgd\SecurityLog::event(
                'Character Restorer overwrite failed on tables that cannot roll back',
                array(
                    'target_acctid' => $acctid,
                    'owner' => (string) ($context['owner'] ?? 'charrestore'),
                ),
                (int) ($session['user']['acctid'] ?? 0),
                \Lotgd\GameLog::SEVERITY_ERROR
            );
        } catch (Throwable $logFailure) {
            // Its error-log line was written before the game log refused the row.
        }
        try {
            charrestore_snapshot_log($context, $failure);
        } catch (Throwable $logFailure) {
            error_log('[charrestore] ' . $failure);
        }
        return false;
    }

    // Credentials, e-mail and superuser flags come from the snapshot too,
    // so who replaced which live account belongs in the security log. The
    // overwrite is committed by now: a log that cannot take the entry must
    // not make it look rolled back.
    try {
        \Lotgd\SecurityLog::event(
            'Character Restorer overwrote an existing account with a snapshot',
            array(
                'target_acctid' => $acctid,
                'owner' => (string) ($context['owner'] ?? 'charrestore'),
                'transactional' => $atomic,
            ),
            (int) ($session['user']['acctid'] ?? 0),
            \Lotgd\GameLog::SEVERITY_WARNING
        );
    } catch (Throwable $exception) {
        // SecurityLog writes PHP's error log before the game log, so the event
        // itself is on record there; this says the game log row is missing.
        error_log(
            "[charrestore] Account {$acctid} was overwritten, but the game log refused its security entry: "
                . $exception->getMessage()
        );
    }
    return true;
}

/** Return the per-session CSRF token used by shared synchronous restore forms. */
function charrestore_restore_csrf_token(): string
{
    return \Lotgd\Security\Csrf::token(\Lotgd\Security\Csrf::SCOPE_CHARRESTORE);
}

/** Validate the shared restore form's CSRF token. */
function charrestore_restore_csrf_valid(): bool
{
    return !\Lotgd\Forms::isUnverifiedRequest(\Lotgd\Security\Csrf::SCOPE_CHARRESTORE);
}

/**
 * Render the common list, preview, and final restore operation.
 *
 * @param array<string, mixed> $context Shared library context.
 */
function charrestore_restore_admin_flow(array $context, string $operation, string $baseUrl): void
{
    if ($operation === 'list') {
        charrestore_restore_render_list($context, $baseUrl);
    } elseif ($operation === 'beginrestore') {
        charrestore_restore_render_preview($context, (string) httpget('file'), $baseUrl);
    } elseif ($operation === 'finishrestore') {
        charrestore_restore_finish($context, (string) httpget('file'), $baseUrl);
    }
}

/** Render the common archive search and validated results. */
function charrestore_restore_render_list(array $context, string $baseUrl): void
{
    $charset = (string) getsetting('charset', 'UTF-8');
    rawoutput("<form action='{$baseUrl}&op=list' method='POST'>");
    addnav('', $baseUrl . '&op=list');
    $fields = array('login' => 'Character Login');
    if (!empty($context['email_search'])) {
        $fields['email'] = 'Character Email';
        $fields['email_hashcheck'] = 'Display hash value for which email';
    }
    $fields['start'] = 'After date';
    $fields['end'] = 'Before date';
    foreach ($fields as $field => $label) {
        output($label . ': ');
        rawoutput("<input name='{$field}' value='" . htmlentities((string) httppost($field), ENT_QUOTES, $charset) . "'><br>");
    }
    rawoutput("<input type='submit' value='" . htmlentities(translate_inline('Submit'), ENT_QUOTES, $charset) . "' class='button'></form>");
    $login = (string) httppost('login');
    $email = (string) httppost('email');
    $hashCheck = (string) httppost('email_hashcheck');
    $start = (string) httppost('start');
    $end = (string) httppost('end');
    if ($login === '' && $email === '' && $hashCheck === '' && $start === '' && $end === '') {
        return;
    }
    if (!empty($context['email_search']) && function_exists('charrestore_gethash')) {
        if ($hashCheck !== '') {
            output('Informational hash: %s`n', charrestore_gethash($hashCheck));
            output('Informational hash (lowercased): %s`n', charrestore_gethash(strtolower($hashCheck)));
        }
        output('Informational hash (empty): %s`n', charrestore_gethash(''));
        if ($email !== '') {
            $email = charrestore_gethash($email);
        }
    }
    $startTime = $start === '' ? false : strtotime($start);
    $endTime = $end === '' ? false : strtotime($end);
    $found = 0;
    $filters = array('login' => $login, 'start' => $startTime, 'end' => $endTime, 'email' => $email);
    foreach (charrestore_snapshot_metadata($context, $filters) as $row) {
        $url = $baseUrl . '&op=beginrestore&file=' . rawurlencode($row['entry']);
        rawoutput("<a href='{$url}'>" . htmlentities($row['name'], ENT_QUOTES, $charset) . '</a> (' . date('M d, Y', $row['date']) . ') (' . htmlentities($row['email'], ENT_QUOTES, $charset) . ') ' . $row['dragonkills'] . ' DKs ID ' . $row['acctid'] . '<br>');
        addnav('', $url);
        $found++;
    }
    if ($found === 0) {
        output('No characters matching the specified criteria were found.');
    }
}

/** Render a validated snapshot preview and restore choices. */
function charrestore_restore_render_preview(array $context, string $file, string $baseUrl): void
{
    $snapshot = charrestore_snapshot_load($file, $context);
    if ($snapshot === null) {
        output('`$Snapshot error: %s`0`n', translate_inline('The selected snapshot is invalid or unreadable.'));
        return;
    }
    $conflicts = charrestore_restore_conflicts($snapshot['account']);
    $snapshotId = (int) ($snapshot['account']['acctid'] ?? 0);
    $otherLoginOwners = array_filter(
        $conflicts['login_ids'],
        static fn (int $id): bool => $id !== $snapshotId
    );
    $url = $baseUrl . '&op=finishrestore&file=' . rawurlencode($file);
    rawoutput("<form action='{$url}' method='POST'>" . \Lotgd\Security\Csrf::hiddenField(\Lotgd\Security\Csrf::SCOPE_CHARRESTORE));
    addnav('', $url);
    if ($otherLoginOwners !== array()) {
        output("`\$The user's login conflicts with an existing login in the system.`n`^New Login: ");
        rawoutput("<input name='newlogin'><br>");
    }
    if ($conflicts['acctid_exists']) {
        output("`\$The snapshot account ID already exists on this server.`n");
        rawoutput("<label><input type='checkbox' name='overwriteprefs' value='1'>" . htmlentities(translate_inline('Overwrite prefs'), ENT_QUOTES, 'UTF-8') . '</label><br>');
        output('`#This overwrites matching preference keys without deleting unrelated keys.`0`n');
    }
    output('`n`#Some user info:`0`n');
    foreach (array('login' => 'Login', 'name' => 'Name', 'acctid' => 'Account ID', 'laston' => 'Last On', 'emailaddress' => 'Email', 'dragonkills' => 'DKs', 'level' => 'Level') as $key => $label) {
        output('`^%s: `#%s`n', translate_inline($label), (string) ($snapshot['account'][$key] ?? ''));
    }
    rawoutput("<input type='submit' value='" . htmlentities(translate_inline('Do the restore'), ENT_QUOTES, 'UTF-8') . "' class='button'></form>");
    // The destructive overwrite is a button of its own, with a confirmation,
    // rather than a box ticked in the form above. It is offered only when the
    // snapshot's login is not another account's, since it would be refused.
    if ($conflicts['acctid_exists'] && $otherLoginOwners === array()) {
        global $session;
        if ($snapshotId === (int) ($session['user']['acctid'] ?? 0)) {
            output('`n`$Restoring over the current user is not offered for the account you are logged in with.`0`n');
            return;
        }
        if (!charrestore_restore_tables_transactional(charrestore_restore_connection())) {
            output('`n`$These tables cannot roll back (e.g. MyISAM): if the restore fails halfway, what was already written stays.`0');
        }
        output('`n`$Destructive: this restores the snapshot account fields and preferences over the current user while preserving the existing account ID, and logs the user out.`0`n');
        rawoutput(\Lotgd\Forms::postButton(
            $url,
            translate_inline('Restore anyway over the current user'),
            translate_inline('Overwrite the current user with this snapshot? Their account fields and preferences are replaced.'),
            'button',
            \Lotgd\Security\Csrf::SCOPE_CHARRESTORE,
            array('overwriteaccount' => '1')
        ));
    }
}

/** Perform a CSRF-protected new restore, preference-only restore, or full overwrite. */
function charrestore_restore_finish(array $context, string $file, string $baseUrl): void
{
    if (!charrestore_restore_csrf_valid()) {
        output('`$The restore request could not be verified. Please preview the snapshot again.`0');
        return;
    }
    $snapshot = charrestore_snapshot_load($file, $context);
    if ($snapshot === null) {
        output('`$Snapshot error: %s`0', translate_inline('The selected snapshot is invalid or unreadable.'));
        return;
    }
    $account = $snapshot['account'];
    $newLogin = trim((string) httppost('newlogin'));
    if ($newLogin !== '') {
        $account['login'] = $newLogin;
    }
    $conflicts = charrestore_restore_conflicts($account, (string) ($account['login'] ?? ''));
    if ($conflicts['acctid_exists']) {
        $preferencesOnly = (int) httppost('overwriteprefs') === 1;
        $fullOverwrite = (int) httppost('overwriteaccount') === 1;
        if ($preferencesOnly === $fullOverwrite) {
            output('`$The account ID already exists. Select Overwrite prefs, or use the separate button to restore over the current user.`0');
            return;
        }
        if ($fullOverwrite) {
            $targetId = (int) ($account['acctid'] ?? 0);
            $otherLoginOwners = array_filter(
                $conflicts['login_ids'],
                static fn (int $id): bool => $id !== $targetId
            );
            if ($otherLoginOwners !== array()) {
                charrestore_restore_overwrite_refused($targetId, 'the login belongs to another account', $context);
                output('`$That login belongs to another account. The destructive overwrite was cancelled.`0');
                return;
            }
            $restored = charrestore_restore_overwrite_account($account, $snapshot['prefs'], $context);
            if ($restored) {
                output('`#The current user was fully overwritten with the snapshot account fields and preferences; the account ID was preserved.`0');
            } elseif (charrestore_restore_tables_transactional(charrestore_restore_connection())) {
                output('`$The full overwrite was not carried out; the account is unchanged. See the administrative log.`0');
            } else {
                output('`$The full overwrite failed. These tables cannot roll back, so part of it may have been written; check the account and the administrative log.`0');
            }
            return;
        }
        $result = charrestore_restore_preferences((int) $account['acctid'], $snapshot['prefs'], $context);
        output($result['applied'] ? '`#The preferences were restored for the existing account.`0' : '`$The preferences could not be restored completely.`0');
        return;
    }
    if ($conflicts['login_ids'] !== array()) {
        output('`$That login already exists. Please return to the preview and choose another.`0');
        return;
    }
    $id = charrestore_restore_account($account, $context);
    if ($id <= 0) {
        output('`$The account could not be restored. See the administrative log.`0');
        return;
    }
    addnav('Edit the restored user', 'user.php?op=edit&userid=' . $id);
    output('`#The account was restored.`n');
    $result = charrestore_restore_preferences($id, $snapshot['prefs'], $context);
    output($result['applied'] ? '`#The preferences were restored.`0' : '`$The preferences could not be restored completely.`0');
}
