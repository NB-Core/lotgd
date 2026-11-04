<?php

/**
 * One process's part in a realtime test against a real database, for
 * RealtimeMariaDbTest. Run from an installed copy of the game.
 *
 * The argument is a JSON spec:
 *   root     the installed game
 *   mode     'hold-event' (write an event in an open transaction, commit on 'go'),
 *            'notify' (Realtime::notify: write and relay), 'drain' (Relay::drain),
 *            'crash-relay' (start relaying by hand, then wait to be killed)
 *   channel  the channel; holding/go signal files
 */

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Realtime;
use Lotgd\Realtime\Relay;
use Lotgd\Settings;

$spec = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
chdir($spec['root']);
require $spec['root'] . '/autoload.php';

$signal = static function (?string $file): void {
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
$payload = ['region' => 'mail', 'version' => (int) ($spec['version'] ?? 1)];

try {
    switch ($spec['mode']) {
        case 'hold-event':
            $conn->beginTransaction();
            Realtime::announce($spec['channel'], 'mail', $payload);
            $signal($spec['holding'] ?? null);
            $waitFor($spec['go'] ?? null);
            $conn->commit();
            $answer = ['done' => true];
            break;
        case 'notify':
            $started = microtime(true);
            Realtime::notify($spec['channel'], 'mail', $payload);
            $answer = ['done' => true, 'seconds' => microtime(true) - $started];
            break;
        case 'drain':
            $waitFor($spec['go'] ?? null);
            $answer = ['relayed' => Relay::drain()];
            break;
        case 'crash-relay':
            // What Relay::run() does up to its first delivery, then nothing:
            // the process is killed with the sequence row locked.
            $conn->beginTransaction();
            $next = (int) $conn->fetchOne('SELECT next_seq FROM ' . Database::prefix('relay_sequence') . ' WHERE id = 1 FOR UPDATE');
            $event = $conn->fetchAssociative('SELECT event_id, channel, type, payload FROM ' . Database::prefix('events') . ' WHERE relayed_at IS NULL ORDER BY id LIMIT 1');
            $conn->executeStatement(
                'INSERT INTO ' . Database::prefix('event_deliveries') . ' (seq, event_id, channel, type, payload, created) VALUES (?, ?, ?, ?, ?, NOW())',
                [$next, $event['event_id'], $event['channel'], $event['type'], $event['payload']]
            );
            $signal($spec['holding'] ?? null);
            sleep(30);
            $answer = ['done' => false];
            break;
        default:
            $answer = ['error' => 'mode'];
    }
} catch (\Throwable $error) {
    $answer = ['error' => get_class($error) . ': ' . $error->getMessage()];
}

echo json_encode($answer), "\n";
