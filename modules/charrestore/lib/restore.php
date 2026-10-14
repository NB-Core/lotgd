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
 * @param array<string, mixed> $prefs Module-keyed preference payload.
 * @param array<string, mixed> $context Shared library context.
 * @return array{applied:bool, used_fallback:bool}
 */
function charrestore_restore_preferences(int $acctid, array $prefs, array $context): array
{
    // Character Restorer exposes the optimized upsert when its module entry point is loaded.
    if (function_exists('charrestore_restore_prefs_upsert')) {
        return charrestore_restore_prefs_upsert(charrestore_restore_connection(), $acctid, $prefs);
    }
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
                charrestore_snapshot_log($context, "Preference restore failed for {$module}/{$setting}: " . $exception->getMessage());
            }
        }
    }
    return array('applied' => $complete, 'used_fallback' => true);
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
 * Destructively restore account fields and preferences over the matching ID.
 *
 * The archived acctid is used only as the immutable update target and is never
 * included among mutable values. The shared connection transaction keeps the
 * account row and preference restore atomic when DBAL transactions are available.
 *
 * @param array<string, mixed> $account Snapshot account data.
 * @param array<string, mixed> $prefs Snapshot module preferences.
 * @param array<string, mixed> $context Shared library context.
 */
function charrestore_restore_overwrite_account(array $account, array $prefs, array $context): bool
{
    $acctid = (int) ($account['acctid'] ?? 0);
    if ($acctid <= 0) {
        charrestore_snapshot_log($context, 'Full account overwrite rejected: snapshot has no valid account ID.');
        return false;
    }

    try {
        $connection = charrestore_restore_connection();
        $values = charrestore_restore_normalize_account($account, charrestore_restore_account_schema());
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

    $transactionSupported = method_exists($connection, 'beginTransaction')
        && method_exists($connection, 'commit')
        && method_exists($connection, 'rollBack');
    $startedTransaction = false;
    try {
        if ($transactionSupported) {
            $connection->beginTransaction();
            $startedTransaction = true;
        }
        $target = $connection->fetchAssociative(
            'SELECT COUNT(acctid) AS c FROM ' . db_prefix('accounts') . ' WHERE acctid = :target_acctid',
            array('target_acctid' => $acctid)
        );
        if ((int) ($target['c'] ?? 0) !== 1) {
            throw new RuntimeException('The target account no longer exists.');
        }
        $connection->executeStatement(
            'UPDATE ' . db_prefix('accounts') . ' SET ' . implode(', ', $assignments)
                . ' WHERE acctid = :target_acctid',
            $parameters
        );
        $preferenceResult = charrestore_restore_preferences($acctid, $prefs, $context);
        if (!$preferenceResult['applied']) {
            throw new RuntimeException('One or more archived preferences could not be restored.');
        }
        if ($startedTransaction) {
            $connection->commit();
        }
        return true;
    } catch (Throwable $exception) {
        if ($startedTransaction) {
            $transactionActive = !method_exists($connection, 'isTransactionActive')
                || $connection->isTransactionActive();
            if ($transactionActive) {
                $connection->rollBack();
            }
        }
        charrestore_snapshot_log(
            $context,
            "Full account overwrite failed for acctid {$acctid}; changes were rolled back: " . $exception->getMessage()
        );
        return false;
    }
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
        rawoutput("<label><input type='checkbox' name='overwriteaccount' value='1'>" . htmlentities(translate_inline('Restore anyway over the current user'), ENT_QUOTES, 'UTF-8') . '</label><br>');
        output('`$Destructive: this restores the snapshot account fields and preferences over the current user while preserving the existing account ID.`0`n');
    }
    output('`n`#Some user info:`0`n');
    foreach (array('login' => 'Login', 'name' => 'Name', 'acctid' => 'Account ID', 'laston' => 'Last On', 'emailaddress' => 'Email', 'dragonkills' => 'DKs', 'level' => 'Level') as $key => $label) {
        output('`^%s: `#%s`n', translate_inline($label), (string) ($snapshot['account'][$key] ?? ''));
    }
    rawoutput("<input type='submit' value='" . htmlentities(translate_inline('Do the restore'), ENT_QUOTES, 'UTF-8') . "' class='button'></form>");
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
            output('`$The account ID already exists. Explicitly select either Overwrite prefs or the destructive full-account overwrite, but not both.`0');
            return;
        }
        if ($fullOverwrite) {
            $targetId = (int) ($account['acctid'] ?? 0);
            $otherLoginOwners = array_filter(
                $conflicts['login_ids'],
                static fn (int $id): bool => $id !== $targetId
            );
            if ($otherLoginOwners !== array()) {
                output('`$That login belongs to another account. The destructive overwrite was cancelled.`0');
                return;
            }
            $restored = charrestore_restore_overwrite_account($account, $snapshot['prefs'], $context);
            output($restored
                ? '`#The current user was fully overwritten with the snapshot account fields and preferences; the account ID was preserved.`0'
                : '`$The full overwrite failed and was rolled back. See the administrative log.`0');
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
