<?php

require_once __DIR__ . '/autoload.php';

use Lotgd\BootstrapErrorHandler;
use Lotgd\ErrorHandler;
use Lotgd\Mail;
use Lotgd\Newday;
use Lotgd\Settings;

define('CRON_NEWDAY', 1);
define('CRON_DBCLEANUP', 2);
define('CRON_COMMENTCLEANUP', 4);
define('CRON_CHARCLEANUP', 8);

define("ALLOW_ANONYMOUS", true);

if (!($settings instanceof Settings)) {
    $settings = new Settings('settings');
}

BootstrapErrorHandler::register();

$result = chdir(__DIR__);
if (!defined('CRON_TEST')) {
    try {
        require_once 'common.php';
    } catch (\Throwable $e) {
        $message = sprintf(
            '[%s] Cron common.php failure: %s in %s on line %d%s',
            date('c'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL
        );
        error_log($message, 3, __DIR__ . '/logs/bootstrap.log');

        $email = $settings->getSetting('gameadminemail', '');
        if ($email !== '') {
            $body = sprintf(
                'Cronjob at %s failed to load common.php: %s',
                $settings->getSetting('serverurl', ''),
                $e->getMessage()
            );
            $mailResult = Mail::send([$email => $email], $body, 'Cronjob Error', [$email => $email], false, 'text/plain', true);

            if (is_array($mailResult) && ! $mailResult['success']) {
                error_log('Cron notification mail failed: ' . $mailResult['error']);
            } elseif ($mailResult === false) {
                error_log('Cron notification mail failed to send.');
            }
        }

        exit(1);
    }
}

ErrorHandler::register();

$cron_args = $argv;
array_shift($cron_args);

if (is_array($cron_args) && count($cron_args) < 1) {
    $executionstyle = CRON_NEWDAY | CRON_DBCLEANUP | CRON_COMMENTCLEANUP | CRON_CHARCLEANUP;
} else {
    //write in the first argument the style - the defines above will guide your way. No argument means "do all now"
    $executionstyle = (int)$cron_args[0];
}

if (!$result) {
    //ERROR, could not change the directory or directory empty
    $email = $settings->getSetting('gameadminemail', '');
    if ($email === '') {
        if (!defined('CRON_TEST')) {
            exit(0); //well, we can't go further
        }
        return;
    }
    $body = sprintf(
        "Sorry, but the gamedir is not set for your cronjob setup at your game at %s.\n\nPlease correct the error or you will have *NO* server newdays.",
        $settings->getSetting('serverurl', '')
    );
    $mailResult = Mail::send([$email => $email], $body, 'Cronjob Setup Screwed', [$email => $email], false, 'text/plain', true);

    if (is_array($mailResult) && ! $mailResult['success']) {
        error_log('Cron setup warning mail failed: ' . $mailResult['error']);
    } elseif ($mailResult === false) {
        error_log('Cron setup warning mail failed to send.');
    }
    if (!defined('CRON_TEST')) {
        exit(0); //that's it.
    }
    return;
}

/* Prevent execution if no value has been entered... if it is a wrong value, it will still break!*/
if ($result) {
    $settings->saveSetting('newdaySemaphore', gmdate('Y-m-d H:i:s'));
    if ($executionstyle & CRON_NEWDAY) {
        Newday::runOnce();
    }
    if ($executionstyle & CRON_DBCLEANUP) {
        //db optimization every day, I think we should leave it here
        //edit: we may force this issue by setting the second argument to 1 in the commandline
        $force_db = 0;
        if (isset($cron_args[1])) {
            $force_db = (((int)$cron_args[1]) ? 1 : 0);
        }
        if (strtotime($settings->getSetting('lastdboptimize', date('Y-m-d H:i:s', strtotime('-1 day')))) < strtotime('-1 day') || $force_db) {
            Newday::dbCleanup();
        }
    }
    if ($executionstyle & CRON_COMMENTCLEANUP) {
        Newday::commentCleanup();
    }
    if ($executionstyle & CRON_CHARCLEANUP) {
        Newday::charCleanup();
    }
}
