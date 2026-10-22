<?php

/**
 * One player's request in an encounter test against a real database, for
 * EncounterMariaDbTest: a challenge, an answer or a withdrawal inside a
 * guarded action, the way encounter.php runs them.
 *
 * Run from an installed copy of the game. The argument is a JSON spec:
 *   root    the installed game
 *   acctid  the player
 *   op      challenge, answer or cancel
 *   target  the opponent (challenge)
 *   id      the encounter (answer, cancel)
 *   accept  true to accept (answer)
 *   loaded  a file to create once the account is read
 *   go      a file to wait for before acting
 *   hold    seconds to stay inside the transaction after acting
 */

declare(strict_types=1);

use Lotgd\Action;
use Lotgd\Encounter;
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
$actionId = bin2hex(random_bytes(16));
$_POST[Action::FIELD] = $actionId;

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
    $result = Action::run('encounter.' . $spec['op'], $actionId, $spec, static function () use ($spec): array {
        global $session;

        $done = match ($spec['op']) {
            'challenge' => Encounter::challenge($session['user'], (int) $spec['target']),
            'answer' => Encounter::answer($session['user'], (int) $spec['id'], !empty($spec['accept'])),
            default => Encounter::cancel($session['user'], (int) $spec['id']),
        };
        if (($spec['hold'] ?? 0) > 0) {
            usleep((int) ($spec['hold'] * 1000000));
        }

        return $done;
    });
    $answer = ['status' => $result->status, 'reason' => $result->reason, 'id' => (int) ($result->data['id'] ?? 0)];
} catch (\Throwable $error) {
    $answer = ['status' => 'error', 'reason' => get_class($error) . ': ' . $error->getMessage(), 'id' => 0];
}

echo json_encode($answer), "\n";
