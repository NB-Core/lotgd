<?php

/**
 * One officer's request in a grant test against a real database, for
 * GrantMariaDbTest: accept a clan applicant through a grant, inside a guarded
 * action, the way pages/clan/clan_grant.php does.
 *
 * Run from an installed copy of the game. The argument is a JSON spec:
 *   root    the installed game
 *   acctid  the officer
 *   grant   the grant id
 *   loaded  a file to create once the account is read
 *   go      a file to wait for before accepting
 *   hold    seconds to stay inside the transaction after accepting
 *   fail    throw after accepting, so the action rolls back
 */

declare(strict_types=1);

use Lotgd\Action;
use Lotgd\Clan\Application;
use Lotgd\MySQL\Database;
use Lotgd\Settings;

$spec = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
chdir($spec['root']);
require $spec['root'] . '/autoload.php';
require_once $spec['root'] . '/src/Lotgd/Config/constants.php';

$touch = static function (?string $file): void {
    if ($file !== null && $file !== '') {
        file_put_contents($file, (string) getmypid());
    }
};
$waitFor = static function (?string $file): void {
    $until = microtime(true) + 30;
    while ($file !== null && $file !== '' && !file_exists($file) && microtime(true) < $until) {
        usleep(10000);
    }
};

$conn = Database::getDoctrineConnection();
Settings::setInstance(new Settings('settings'));
$acctid = (int) $spec['acctid'];
$grant = (int) $spec['grant'];
$actionId = bin2hex(random_bytes(16));

$GLOBALS['session'] = [
    'loggedin' => true,
    'user' => $conn->fetchAssociative('SELECT * FROM accounts WHERE acctid = :acctid', ['acctid' => $acctid]),
    'allowednavs' => [],
    'bufflist' => [],
];
$GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
$GLOBALS['companions'] = [];
$touch($spec['loaded'] ?? null);
$waitFor($spec['go'] ?? null);

try {
    $result = Action::run(Application::ACTION, $actionId, ['grant' => $grant], static function () use ($grant, $actionId, $spec): array {
        global $session;

        $accepted = Application::accept($grant, $session['user'], $actionId);
        if (($spec['hold'] ?? 0) > 0) {
            usleep((int) ($spec['hold'] * 1000000));
        }
        if (!empty($spec['fail'])) {
            throw new \DomainException('failed after accepting');
        }

        return $accepted;
    });
    $answer = ['status' => $result->status, 'reason' => $result->reason];
} catch (\Throwable $error) {
    $answer = ['status' => 'error', 'reason' => get_class($error) . ': ' . $error->getMessage()];
}

echo json_encode($answer), "\n";
