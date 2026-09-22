<?php

declare(strict_types=1);

namespace Lotgd\Tests\Common;

use Lotgd\Tests\Support\RootDbConnect;
use PHPUnit\Framework\TestCase;

final class CharsetFailureTest extends TestCase
{
    public function testCommonExitsOnCharsetFailure(): void
    {
        $root = dirname(__DIR__, 2);
        $dbconnect = RootDbConnect::takeOver();

$script = <<<'PHP'
<?php
require __DIR__ . '/tests/Stubs/DbMysqli.php';
require __DIR__ . '/autoload.php';
class FailDb extends Lotgd\MySQL\DbMysqli {
    public function setCharset(string $charset): bool { return false; }
}
Lotgd\MySQL\Database::setInstance(new FailDb());
include __DIR__ . '/common.php';
echo "AFTER\n";
PHP;
        $scriptFile = $root . '/charset_failure_runner.php';

        // Everything between the borrow and the restore lives in here, the
        // fixture write included: both of these things sit at the repository
        // root, and both are given back whatever happens. The assertions below
        // already run after the restore, so a failing one was never the risk
        // -- a throw from write(), file_put_contents() or exec() was, and it
        // would have left the borrow open, which makes the *next* takeOver()
        // refuse rather than this test report.
        try {
            $dbconnect->write(
                "<?php return ['DB_HOST'=>'','DB_USER'=>'','DB_PASS'=>'','DB_NAME'=>'','DB_PREFIX'=>''];"
            );

            file_put_contents($scriptFile, $script);

            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile) . ' 2>&1';
            exec($cmd, $output, $status);
        } finally {
            if (is_file($scriptFile)) {
                unlink($scriptFile);
            }

            $dbconnect->restore();
        }

        $outputText = implode("", $output);

        $this->assertSame(0, $status);
        $this->assertStringContainsString(
            'Error setting db connection charset to UTF-8...please check your db connection!',
            $outputText
        );
        $this->assertStringNotContainsString('AFTER', $outputText);
    }
}
