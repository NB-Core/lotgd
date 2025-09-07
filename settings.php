<?php

/**
 * Settings for CLI cron execution.
 *
 * cron.php reads this file to know where the game is installed.
 * Set $GAME_DIR to the absolute path to your installation.
 */

$GAME_DIR = '/PATH/TO/GAME';
// enter here the directory of your datacache, this cannot be done elsewhere

/* The bundled **aurora** template is used when no skin is configured or the
database is unavailable. Change the template by adjusting the `defaultskin`
setting in your game configuration.
*/
