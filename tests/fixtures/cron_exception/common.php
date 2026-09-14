<?php

/**
 * Stands in for common.php while tests/cron_common_exception.php runs cron.php.
 *
 * The message is deliberately not the one cron.php puts in front of it. It used
 * to be the same words -- "Cron common.php failure" -- so the logged line
 * carried the phrase twice and the test could not tell cron.php's own prefix
 * from the exception text it quotes. Rewording cron.php's half left the test
 * green, which is how that was noticed.
 */

declare(strict_types=1);

throw new \RuntimeException('the stand-in common.php refused to load');
