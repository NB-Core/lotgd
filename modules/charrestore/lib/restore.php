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
    $schema = $connection->executeQuery("DESCRIBE {$table}");
    $types = array();
    while (($column = $schema->fetchAssociative()) !== false) {
        $types[(string) $column['Field']] = strtolower((string) $column['Type']);
    }
    foreach (array('allowednavs', 'lastip') as $defaultColumn) {
        if (isset($types[$defaultColumn]) && !array_key_exists($defaultColumn, $account)) {
            $account[$defaultColumn] = '';
        }
    }
    $columns = $holders = $parameters = array();
    foreach ($account as $key => $value) {
        if (!is_string($key) || !isset($types[$key])) {
            output("`2Dropping the column `^%s`n", (string) $key);
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
            $timestamp = $timestamp === false || ($minimum !== false && $timestamp < $minimum) ? ($minimum ?: time()) : $timestamp;
            $value = str_starts_with($type, 'date(') || $type === 'date' ? date('Y-m-d', $timestamp)
                : (str_starts_with($type, 'time(') || $type === 'time' ? date('H:i:s', $timestamp) : date('Y-m-d H:i:s', $timestamp));
        } elseif ($value !== null) {
            $value = (string) $value;
        }
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

/** Return the per-session CSRF token used by shared synchronous restore forms. */
function charrestore_restore_csrf_token(): string
{
    global $session;
    // Core verification needed: repository usage exposes no generic synchronous-form CSRF API;
    // use a module-scoped session token until the host's canonical helper is confirmed.
    if (empty($session['charrestore_restore_csrf']) || !is_string($session['charrestore_restore_csrf'])) {
        $session['charrestore_restore_csrf'] = bin2hex(random_bytes(32));
    }
    return $session['charrestore_restore_csrf'];
}

/** Validate the shared restore form's CSRF token. */
function charrestore_restore_csrf_valid(): bool
{
    $posted = (string) httppost('csrf_token');
    return $posted !== '' && hash_equals(charrestore_restore_csrf_token(), $posted);
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
    $url = $baseUrl . '&op=finishrestore&file=' . rawurlencode($file);
    rawoutput("<form action='{$url}' method='POST'><input type='hidden' name='csrf_token' value='" . htmlentities(charrestore_restore_csrf_token(), ENT_QUOTES, 'UTF-8') . "'>");
    addnav('', $url);
    if ($conflicts['login_ids'] !== array()) {
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
}

/** Perform a CSRF-protected full restore or preference-only overwrite. */
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
    $conflicts = charrestore_restore_conflicts($account, $newLogin);
    if ($conflicts['acctid_exists']) {
        if ((int) httppost('overwriteprefs') !== 1) {
            output('`$The account ID already exists. Delete it for a full restore or select Overwrite prefs.`0');
            return;
        }
        $result = charrestore_restore_preferences((int) $account['acctid'], $snapshot['prefs'], $context);
        output($result['applied'] ? '`#The preferences were restored for the existing account.`0' : '`$The preferences could not be restored completely.`0');
        return;
    }
    if ($newLogin !== '') {
        $account['login'] = $newLogin;
        $conflicts = charrestore_restore_conflicts($account, $newLogin);
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
