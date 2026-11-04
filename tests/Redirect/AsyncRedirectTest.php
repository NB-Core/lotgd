<?php

declare(strict_types=1);

namespace Lotgd\Tests\Redirect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A redirect inside an async request (async/process.php, async/stream.php)
 * must not save: it would replace the links of the page on screen, and the
 * player's next click would end on badnav.php. A hook on everyhit that
 * redirected the stream of a two-factor challenge page did exactly that.
 *
 * Redirect exits, and the status code only exists in a web request, so the
 * redirect runs behind PHP's built-in server.
 */
final class AsyncRedirectTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function targets(): iterable
    {
        yield 'a page' => [
            'runmodule.php?module=twofactorauth&op=challenge',
            'runmodule.php?module=twofactorauth&op=challenge',
        ];
        yield 'line breaks are dropped' => [
            "runmodule.php?op=challenge\r\nX-Injected: y",
            'runmodule.php?op=challengeX-Injected: y',
        ];
        yield 'not valid UTF-8' => ["village.php?name=\xC3\x28", "village.php?name=\u{FFFD}("];
    }

    #[DataProvider('targets')]
    public function testAnAsyncRedirectAnswers409WithJsonAndKeepsTheLinks(string $target, string $location): void
    {
        $root = var_export(dirname(__DIR__, 2), true);
        $targetCode = var_export($target, true);
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
    // After the body, so the body stays the JSON the client reads.
    echo "\\nNAVS=" . json_encode(array_keys(\$session['allowednavs']));
});
\\Lotgd\\Redirect::redirect($targetCode, '2FA jail redirect');
echo "\\nNOT REACHED";
PHP;
        $dir = sys_get_temp_dir() . '/lotgd-redirect-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir));
        file_put_contents($dir . '/router.php', $script);
        $port = $this->freePort();
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $dir
        );
        self::assertIsResource($server);
        try {
            [$status, $headers, $body] = $this->get('http://127.0.0.1:' . $port . '/async/process.php');
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($dir . '/router.php');
            rmdir($dir);
        }

        self::assertSame(409, $status, $body);
        self::assertContains('content-type: application/json; charset=utf-8', $headers);
        [$json, $after] = explode("\nNAVS=", $body, 2) + [1 => ''];
        self::assertSame(['status' => 'error', 'error' => 'redirect', 'location' => $location], json_decode($json, true), $json);
        self::assertStringNotContainsString('NOT REACHED', $body);
        // The page's links are still there: nothing replaced or saved them.
        self::assertSame('["runmodule.php?module=twofactorauth&op=verify"]', $after);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @return array{0:int,1:list<string>,2:string}
     */
    private function get(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $deadline = microtime(true) + 5;
        do {
            $body = @file_get_contents($url, false, $context);
            if ($body !== false) {
                $headers = array_map('strtolower', $http_response_header);
                preg_match('#^http/\S+ (\d{3})#', $headers[0] ?? '', $match);

                return [(int) ($match[1] ?? 0), $headers, $body];
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        self::fail('The test server did not answer.');
    }
}
