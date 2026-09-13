<?php

/**
 * Runs one of the game's root pages for real, in its own process.
 *
 * Everything about this file is dictated by one requirement: the page has to
 * actually execute. A CSRF test that asserts "the state change did not happen"
 * proves nothing unless the same harness can show the state change happening
 * when the token is right -- and the first four versions of this could not,
 * because the request never reached the page body at all. Each obstacle below
 * is one of those failures, fixed rather than asserted around.
 *
 * Reads a JSON spec on argv[1], writes a JSON result between @@@ markers on
 * stderr. Markers because the page writes its own HTML to stdout, and a
 * redirect or a footer ends the process wherever it likes -- the result is
 * emitted from a shutdown function so it survives every exit path the page
 * has, which is most of them.
 *
 * @see PageRunner for the side that builds the spec and reads the result.
 */

declare(strict_types=1);

/** Kept in step with PageRunner::RESULT_MARKER. */
const RESULT_MARKER = '@@@LOTGD-PAGE-RESULT@@@';

$spec = json_decode((string) ($argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
$root = (string) $spec['root'];
$farm = (string) $spec['farm'];

require $root . '/autoload.php';
require $root . '/src/Lotgd/Config/constants.php';
require_once $root . '/tests/Stubs/EmptyResult.php';
require_once $root . '/tests/Stubs/DbMysqli.php';
require_once $root . '/tests/Stubs/Database.php';
require_once $root . '/tests/Stubs/DoctrineBootstrap.php';
require_once $root . '/install/data/tables.php';

// common.php compares $logd_version against this and renders "Upgrade Needed"
// -- which ends in pageFooter(), which exits -- whenever they differ. An
// unseeded settings table differs from every version there has ever been, so
// without this the page body is unreachable by construction.
\Lotgd\Tests\Stubs\Database::$settings_table = [
    'installer_version' => (string) $spec['version'],
    'charset' => 'UTF-8',
] + (array) ($spec['settings'] ?? []);

$_SERVER['REQUEST_METHOD'] = (string) ($spec['method'] ?? 'POST');
$_SERVER['SCRIPT_NAME'] = '/' . basename((string) $spec['page']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_GET = (array) ($spec['get'] ?? []);
$_POST = (array) ($spec['post'] ?? []);

// The key forced navigation looks for, and the REQUEST_URI the page is asked
// for, built from one source so they cannot disagree.
//
// PhpGenericEnvironment::sanitizeUri() has two paths, and only one of them
// encodes anything: it builds a query string out of $_GET with URLEncode()
// *when REQUEST_URI is empty*, and otherwise keeps the REQUEST_URI it was given
// and only reduces it to basename + query. This harness always supplies one, so
// the encoding path is never taken and the reduced form is whatever is built
// here. Both halves below come from the same $parts, so they match whichever
// encoder is used -- checked by running a value with a space in it, where
// urlencode and rawurlencode differ, under both.
//
// Copilot read the encoders as a live mismatch. It is not one, for the reason
// above; what was wrong was the comment that used to sit here, which said this
// is "built the same way sanitizeUri() builds it" when sanitizeUri does not
// build it at all in this harness. urlencode() is kept anyway, so that the
// fallback path would agree if REQUEST_URI ever went missing.
$uri = basename((string) $spec['page']);
if ($_GET !== []) {
    $parts = [];
    foreach ($_GET as $key => $value) {
        $parts[] = $key . '=' . urlencode((string) $value);
    }
    $uri .= '?' . implode('&', $parts);
}
$_SERVER['REQUEST_URI'] = '/' . $uri;

/**
 * The account row, built from the schema rather than written out.
 *
 * common.php reads the whole row and hands it to code that unserializes
 * columns and type-hints others, so a hand-written fixture fails on whichever
 * column it forgot -- and then fails again on the next one. Deriving it from
 * get_all_tables() means a column added to the game is a column this harness
 * already has.
 *
 * The values are cast by declared type because the page is written against a
 * driver that hands back typed columns: Mounts::loadPlayerMount(int $horse)
 * takes a TypeError from the schema's own string default otherwise.
 */
$account = [];
foreach (get_all_tables()['accounts'] as $column => $definition) {
    if (!is_array($definition)) {
        continue;
    }
    $type = strtolower((string) ($definition['type'] ?? ''));
    $value = $definition['default'] ?? null;
    if (str_contains($type, 'int')) {
        $value = (int) $value;
    } elseif (str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'decimal')) {
        $value = (float) $value;
    } elseif ($value === null) {
        $value = '';
    }
    $account[$column] = $value;
}

$GLOBALS['accounts_table'] = [
    1 => [
        'acctid' => 1,
        'name' => 'Admin',
        'login' => 'admin',
        'superuser' => (int) $spec['superuser'],
        'loggedin' => 1,
        'laston' => date('Y-m-d H:i:s'),
        'locked' => 0,
        'emailaddress' => 'admin@example.invalid',
        'lastip' => '127.0.0.1',
        'uniqueid' => 'harness',
        'restorepage' => 'village.php',
        'translatorlanguages' => '',
        'prefs' => serialize([]),
        'bufflist' => serialize([]),
        'dragonpoints' => serialize([]),
        'companions' => serialize([]),
        // Forced navigation sends any request the previous page did not offer
        // to badnav.php, so the URI under test has to be one this account was
        // allowed to follow. That is the game's first line of defence and it
        // sits in front of the token check; a harness that skipped it would be
        // measuring the redirect.
        'allowednavs' => serialize([$uri => true]),
    ] + $account,
];

// Opened here only so the seeded data lands in the session store, then closed
// again before common.php opens it for real.
//
// Leaving it open worked, but common.php's RuntimeHardening then calls
// session_set_cookie_params() on an already-active session, which is a warning
// -- and the bootstrap error handler appends every warning to logs/bootstrap.log.
// tests/CronCommonExceptionTest deletes that same file, runs a subprocess, and
// asserts the file exists with its own marker in it, so this harness was putting
// dozens of lines per suite run into a file another test owns exclusively.
// Closing the session here removes the warning at its source rather than muting
// it: common.php's own session_start() reopens the same session id and reads
// the seeded data straight back.
session_start();
$_SESSION['session'] = [
    'loggedin' => true,
    'lasthit' => strtotime('now'),
    'user' => [
        'acctid' => 1,
        'loggedin' => true,
        'laston' => date('Y-m-d H:i:s'),
        'superuser' => (int) $spec['superuser'],
    ],
];

if (($spec['token'] ?? null) !== null) {
    $token = (string) $spec['token'];
    // Written into the session array directly rather than through
    // Csrf::seed(): seed() resolves the session through the global $session,
    // which common.php has not yet bound to $_SESSION['session'] at this point.
    $_SESSION['session']['csrf'][\Lotgd\Forms::csrfScope()] = $token;
    $_POST[\Lotgd\Security\Csrf::FORM_FIELD] = $token;
    $_POST[\Lotgd\Security\Csrf::FIELD] = $token;
}

// Everything seeded; hand the session back so common.php can open it itself.
session_write_close();

register_shutdown_function(static function (): void {
    // Whether the process is ending because the page finished or because it
    // died. PHP runs shutdown handlers after a fatal error too, so without
    // this the harness happily emits a well-formed payload with an empty
    // statement list for a page that never loaded -- and every refusal
    // assertion in this suite accepts that as "the page changed nothing".
    //
    // Copilot reported the include/require half of this. Changing it to
    // require was not enough, which a probe showed rather than reasoning:
    // the failed require is fatal, the handler still runs, and the result
    // still decodes. So the fatal itself has to be carried out, and then it
    // covers every fatal the page can take, not only an unresolvable path.
    $last = error_get_last();
    $fatal = null;
    if ($last !== null && (($last['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0) {
        $fatal = sprintf('%s in %s on line %d', $last['message'], $last['file'], $last['line']);
    }

    $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
    $statements = array_map(
        static fn ($statement) => is_array($statement) ? (string) ($statement['sql'] ?? '') : (string) $statement,
        $connection->executeStatements ?? []
    );

    fwrite(STDERR, RESULT_MARKER . json_encode([
        'statements' => $statements,
        'queries' => array_map('strval', $connection->queries ?? []),
        'status' => http_response_code(),
        'fatal' => $fatal,
    ], JSON_THROW_ON_ERROR) . RESULT_MARKER);
});

// From the farm, so that the page's own relative lookups resolve the way they
// do in an installed game -- above all file_exists('installer.php'), which
// common.php answers with a "Major Security Risk" page that exits.
chdir($farm);

// require, not include: a page path that does not resolve must stop the
// process, so the harness reports nothing and fails loudly. With include it
// is a warning, the shutdown handler still runs, and the result is a valid
// payload with an empty statement list -- which every refusal assertion in
// this suite would accept as "the page changed nothing". Reported by Copilot,
// and the fourth instance of that same failure mode in this file.
require $root . '/' . ltrim((string) $spec['page'], '/');
