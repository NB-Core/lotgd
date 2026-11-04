<?php

/**
 * One request's worth of a guarded action, in a process of its own, against a
 * real database -- for GuardedActionMariaDbTest, which runs several of these
 * at once and kills some of them.
 *
 * Run from an installed copy of the game (its dbconnect.php names the
 * database), the way a page would: the connection comes from the Doctrine
 * bootstrap, and the account is read the way ForcedNavigation reads it.
 *
 * The argument is a JSON spec:
 *   root      the installed game
 *   acctid    the player
 *   mode      'guarded' (Action::run) or 'classic' (run, then the page-end save)
 *   booking   'deposit' or 'transfer'
 *   action    the action id; amount; to (recipient acctid, for a transfer)
 *   loaded    a file to create once the account is read
 *   go        a file to wait for before booking (to act on a stale page)
 *   holding   a file to create inside the transaction, once it has written
 *   hold      seconds to stay inside the transaction after writing
 *   holdBefore for a transfer, wait that long before crediting instead
 */

declare(strict_types=1);

use Lotgd\Accounts;
use Lotgd\Action;
use Lotgd\Action\ActionAborted;
use Lotgd\MySQL\Database;

$spec = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
chdir($spec['root']);
require $spec['root'] . '/autoload.php';

$touch = static function (?string $file): void {
    if ($file !== null && $file !== '') {
        file_put_contents($file, (string) getmypid());
    }
};
$waitFor = static function (?string $file): void {
    if ($file === null || $file === '') {
        return;
    }
    $until = microtime(true) + 30;
    while (!file_exists($file) && microtime(true) < $until) {
        usleep(10000);
    }
};

$conn = Database::getDoctrineConnection();
$acctid = (int) $spec['acctid'];
$amount = (int) $spec['amount'];

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

$booking = static function () use ($conn, $spec, $amount, $touch): array {
    global $session;

    if ($spec['booking'] === 'transfer') {
        $session['user']['gold'] -= $amount;
        if (!empty($spec['holdBefore'])) {
            // Hold the own row a moment before reaching for the other's.
            usleep((int) ($spec['hold'] * 1000000));
        }
        $credited = $conn->executeStatement(
            'UPDATE accounts SET goldinbank = goldinbank + :amount, transferredtoday = transferredtoday + 1,'
            . ' version = version + 1 WHERE acctid = :acctid AND locked = 0',
            ['amount' => $amount, 'acctid' => (int) $spec['to']]
        );
        if ($credited !== 1) {
            throw new ActionAborted('recipient-changed');
        }
    } else {
        $session['user']['gold'] -= $amount;
        $session['user']['goldinbank'] += $amount;
    }

    if (($spec['hold'] ?? 0) > 0 && empty($spec['holdBefore'])) {
        // Written, not committed: what a crash or a slow page leaves open.
        if ($spec['mode'] === 'guarded') {
            Accounts::persist(true);
        }
        $touch($spec['holding'] ?? null);
        usleep((int) ($spec['hold'] * 1000000));
    }

    return ['amount' => $amount, 'gold' => (int) $session['user']['gold']];
};

try {
    if ($spec['mode'] === 'guarded') {
        $result = Action::run('test.' . $spec['booking'], $spec['action'], ['amount' => $amount], $booking);
        $answer = ['status' => $result->status, 'reason' => $result->reason, 'data' => $result->data];
    } else {
        $data = $booking();
        Accounts::persist();
        $answer = ['status' => 'classic', 'reason' => '', 'data' => $data];
    }
} catch (\Throwable $error) {
    $answer = ['status' => 'error', 'reason' => get_class($error) . ': ' . $error->getMessage(), 'data' => []];
}

echo json_encode($answer), "\n";
