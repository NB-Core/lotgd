<?php

declare(strict_types=1);

/**
 * Shared snapshot services owned by Character Restorer.
 *
 * Context shape (all keys are optional except owner and snapshot_dir):
 * @phpstan-type CharrestoreContext array{
 *   owner:string,
 *   snapshot_dir:string,
 *   filename_strategy?:'charrestore'|'reset',
 *   log_category?:string,
 *   privacy_filter?:bool,
 *   deletion_notification?:bool,
 *   excluded_modules_hook?:bool,
 *   email_search?:bool
 * }
 * Defaults are: filename_strategy=charrestore, log_category=owner,
 * privacy_filter=false, deletion_notification=false, excluded_modules_hook=false,
 * email_search=false. The latter enables Character Restorer's hashed-email fields.
 */

/**
 * Resolve a configured archive directory relative to the deployed modules directory.
 *
 * @param array<string, mixed> $context Shared library context.
 */
function charrestore_snapshot_directory(array $context): string
{
    $path = trim((string) ($context['snapshot_dir'] ?? ''));
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    if ($path === '') {
        $path = '..' . DIRECTORY_SEPARATOR . 'logd_snapshots';
    }
    if (!charrestore_snapshot_path_is_absolute($path)) {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;
    }
    $real = realpath($path);
    return rtrim($real !== false ? $real : $path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

/** Determine whether a filesystem path is absolute. */
function charrestore_snapshot_path_is_absolute(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
}

/** Log a shared-library failure using the best host facility available. */
function charrestore_snapshot_log(array $context, string $message): void
{
    $category = (string) ($context['log_category'] ?? $context['owner'] ?? 'charrestore');
    if (class_exists('\\Lotgd\\GameLog') && method_exists('\\Lotgd\\GameLog', 'log')) {
        \Lotgd\GameLog::log($message, $category);
    } elseif (function_exists('gamelog')) {
        gamelog('[' . $category . '] ' . $message);
    } else {
        error_log('[' . $category . '] ' . $message);
    }
}

/**
 * Create an account and preference snapshot using the context's legacy filename strategy.
 *
 * @param array<string, mixed> $context Shared library context.
 */
function charrestore_snapshot_create(int $acctid, array $context): bool
{
    $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
    $account = $connection->fetchAssociative(
        'SELECT * FROM ' . db_prefix('accounts') . ' WHERE acctid = :acctid',
        array('acctid' => $acctid)
    );
    if (!is_array($account)) {
        return false;
    }
    $originalEmail = (string) ($account['emailaddress'] ?? '');
    if (!empty($context['privacy_filter'])) {
        foreach (array('output', 'allowednavs', 'lastip', 'uniqueid') as $column) {
            unset($account[$column]);
        }
        $account['emailaddress'] = charrestore_gethash((string) ($account['emailaddress'] ?? ''));
        $account['replaceemail'] = charrestore_gethash((string) ($account['replaceemail'] ?? ''));
    }
    $excluded = !empty($context['excluded_modules_hook'])
        ? (array) modulehook('charrestore_nosavemodules', array()) : array();
    $snapshot = array('account' => $account, 'prefs' => array());
    $rows = $connection->fetchAllAssociative(
        'SELECT * FROM ' . db_prefix('module_userprefs') . ' WHERE userid = :acctid',
        array('acctid' => $acctid)
    );
    foreach ($rows as $row) {
        $module = (string) $row['modulename'];
        if (!isset($excluded[$module])) {
            $snapshot['prefs'][$module][(string) $row['setting']] = $row['value'];
        }
    }
    $directory = charrestore_snapshot_directory($context);
    if (!is_dir($directory) || !is_writable($directory)) {
        charrestore_snapshot_log($context, 'Snapshot directory is missing or not writable: ' . $directory);
        return false;
    }
    $login = str_replace(' ', '_', (string) ($account['login'] ?? ''));
    $filename = ($context['filename_strategy'] ?? 'charrestore') === 'reset'
        ? $login . '|' . date('Ymd') . '|DK_' . (int) ($account['dragonkills'] ?? 0)
        : $login . '|' . (int) ($account['acctid'] ?? 0) . '|' . date('Ymd');
    $payload = serialize($snapshot);
    $written = file_put_contents($directory . $filename, $payload, LOCK_EX);
    if ($written !== strlen($payload)) {
        charrestore_snapshot_log($context, 'Snapshot file could not be written completely: ' . $filename);
        return false;
    }
    if (!empty($context['deletion_notification']) && function_exists('charrestore_send_deletion_notification')) {
        charrestore_send_deletion_notification($snapshot, $originalEmail);
    }
    return true;
}

/**
 * Securely load and validate a snapshot selected by basename.
 *
 * @param array<string, mixed> $context Shared library context.
 * @return array{account:array<string, mixed>, prefs:array<string, mixed>}|null
 */
function charrestore_snapshot_load(string $basename, array $context): ?array
{
    if ($basename === '' || basename($basename) !== $basename || str_contains($basename, '/') || str_contains($basename, '\\')) {
        return null;
    }
    $directory = realpath(charrestore_snapshot_directory($context));
    $file = realpath(charrestore_snapshot_directory($context) . $basename);
    if ($directory === false || $file === false || is_link(charrestore_snapshot_directory($context) . $basename)) {
        return null;
    }
    $prefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($file, $prefix) || !is_file($file) || !is_readable($file)) {
        return null;
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        return null;
    }
    set_error_handler(static fn (): bool => true);
    try {
        $snapshot = unserialize($contents, array('allowed_classes' => false));
    } finally {
        restore_error_handler();
    }
    if (!is_array($snapshot) || !isset($snapshot['account']) || !is_array($snapshot['account'])) {
        return null;
    }
    $snapshot['prefs'] = isset($snapshot['prefs']) && is_array($snapshot['prefs']) ? $snapshot['prefs'] : array();
    return $snapshot;
}

/**
 * Enumerate validated archive metadata.
 *
 * Login and date filename fields are used as a cheap compatibility prefilter so
 * large legacy archives do not need to deserialize every entry. Returned account
 * metadata always comes from the validated payload rather than from the filename.
 *
 * @param array<string, mixed> $context Shared library context.
 * @param array{login?:string,start?:int|false,end?:int|false,email?:string} $filters Search filters.
 * @return array<int, array<string, mixed>>
 */
function charrestore_snapshot_metadata(array $context, array $filters = array()): array
{
    $directory = charrestore_snapshot_directory($context);
    $entries = is_dir($directory) && is_readable($directory) ? scandir($directory) : false;
    $result = array();
    foreach ($entries === false ? array() : $entries as $entry) {
        $parts = explode('|', $entry);
        if (count($parts) < 2) {
            continue;
        }
        $datePart = ($context['filename_strategy'] ?? 'charrestore') === 'reset' ? ($parts[1] ?? '') : ($parts[2] ?? $parts[1] ?? '');
        $date = strtotime($datePart);
        $filenameLogin = str_replace('_', ' ', $parts[0]);
        if (
            ($filters['login'] ?? '') !== ''
            && stripos($filenameLogin, (string) $filters['login']) === false
        ) {
            continue;
        }
        if (($filters['start'] ?? false) !== false && ($date === false || $date < $filters['start'])) {
            continue;
        }
        if (($filters['end'] ?? false) !== false && ($date === false || $date > $filters['end'])) {
            continue;
        }

        $snapshot = charrestore_snapshot_load($entry, $context);
        if ($snapshot === null) {
            continue;
        }
        $account = $snapshot['account'];
        if (
            ($filters['email'] ?? '') !== ''
            && stripos((string) ($account['emailaddress'] ?? ''), (string) $filters['email']) === false
        ) {
            continue;
        }
        $result[] = array('entry' => $entry, 'date' => $date === false ? 0 : $date,
            'name' => (string) ($account['login'] ?? ''), 'email' => (string) ($account['emailaddress'] ?? ''),
            'acctid' => (int) ($account['acctid'] ?? 0), 'dragonkills' => (int) ($account['dragonkills'] ?? 0));
    }
    return $result;
}
