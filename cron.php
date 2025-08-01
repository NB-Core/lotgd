<?php

use Lotgd\Newday;

define('CRON_NEWDAY', 1);
define('CRON_DBCLEANUP', 2);
define('CRON_COMMENTCLEANUP', 4);
define('CRON_CHARCLEANUP', 8);

define("ALLOW_ANONYMOUS", true);
require("settings.php");
$result = chdir($GAME_DIR);
require_once("common.php");

$cron_args = $argv;
array_shift($cron_args);

if (is_array($cron_args) && count($cron_args) < 1) {
    $executionstyle = CRON_NEWDAY | CRON_DBCLEANUP | CRON_COMMENTCLEANUP | CRON_CHARCLEANUP;
} else {
    //write in the first argument the style - the defines above will guide your way. No argument means "do all now"
    $executionstyle = (int)$cron_args[0];
}

if (!$result || $GAME_DIR == '') {
    //ERROR, could not change the directory or directory empty
    $email = getsetting('gameadminemail', '');
    if ($email == '') {
        exit(0); //well, we can't go further
    }
    mail($email, "Cronjob Setup Screwed", sprintf("Sorry, but the gamedir is not set for your cronjob setup at your game at %s.\n\nPlease correct the error or you will have *NO* server newdays.", getsetting('serverurl', '')));
    exit(0); //that's it.
}

/* Prevent execution if no value has been entered... if it is a wrong value, it will still break!*/
if ($GAME_DIR != '') {
        savesetting("newdaySemaphore", gmdate("Y-m-d H:i:s"));
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
        if (strtotime(getsetting("lastdboptimize", date("Y-m-d H:i:s", strtotime("-1 day")))) < strtotime("-1 day") || $force_db) {
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
