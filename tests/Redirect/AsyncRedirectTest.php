<?php

declare(strict_types=1);

namespace Lotgd\Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * A redirect inside an async request (async/process.php, async/stream.php)
 * must not save: it would replace the links of the page on screen, and the
 * player's next click would end on badnav.php. A hook on everyhit that
 * redirected the stream of a two-factor challenge page did exactly that.
 *
 * Runs in a child process: Redirect exits.
 */
final class AsyncRedirectTest extends TestCase
{
    public function testAsyncRedirectAnswersJsonAndKeepsTheLinks(): void
    {
        $root = var_export(dirname(__DIR__, 2), true);
        $script = <<<PHP
<?php
declare(strict_types=1);
chdir($root);
require 'vendor/autoload.php';
define('AJAX_MODE', true);
\$session = &\\Lotgd\\PhpGenericEnvironment::getSession();
\$session['user'] = ['acctid' => 7, 'loggedin' => true];
\$session['allowednavs'] = ['runmodule.php?module=twofactorauth&op=verify' => true];
register_shutdown_function(static function (): void {
    \$session = &\\Lotgd\\PhpGenericEnvironment::getSession();
    echo "\\nNAVS=" . json_encode(array_keys(\$session['allowednavs']));
    echo "\\nSTATUS=" . http_response_code();
});
\\Lotgd\\Redirect::redirect("runmodule.php?module=twofactorauth&op=challenge\\r\\nX: y", '2FA jail redirect');
echo "\\nNOT REACHED";
PHP;
        $file = tempnam(sys_get_temp_dir(), 'redirect');
        self::assertNotFalse($file);
        file_put_contents($file, $script);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
        } finally {
            unlink($file);
        }
        $output = implode("\n", $lines);

        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('NOT REACHED', $output);
        self::assertStringContainsString(
            '{"status":"error","error":"redirect","location":"runmodule.php?module=twofactorauth&op=challengeX: y"}',
            $output
        );
        // The page's links are still there: nothing replaced or saved them.
        self::assertStringContainsString('NAVS=["runmodule.php?module=twofactorauth&op=verify"]', $output);
    }
}
