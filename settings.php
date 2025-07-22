<?php

/**
 * Settings for CLI cron execution.
 *
 * cron.php reads this file to know where the game is installed.
 * Set $GAME_DIR to the absolute path to your installation.
 */

$GAME_DIR = '/PATH/TO/GAME';
// enter here the directory of your datacache, this cannot be done elsewhere

$gz_handler_on = 0;
/* set to 1 if you want to enable gzip compression to save bandwidth (~30-50%), but it costs slightly more processor power for PHP to get it done. z_lib in apache is favoured if you have direct access to your machine.
Actually, if you can set this to 0 and add these lines in i.e. /etc/php5/apache2/conf.d into a randomly named .ini file:
zlib.output_compression = 1
zlib.output_compression_level = 7
for instance. And then do an "apache2 -k graceful" and check with phpinfo() to see if it worked.
*/

/* The bundled **aurora** template is used when no skin is configured or the
database is unavailable. Change the template by adjusting the `defaultskin`
setting in your game configuration.
*/
