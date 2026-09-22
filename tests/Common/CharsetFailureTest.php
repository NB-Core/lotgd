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
        $dbconnect->write(
            "<?php return ['DB_HOST'=>'','DB_USER'=>'','DB_PASS'=>'','DB_NAME'=>'','DB_PREFIX'=>''];"
        );

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
        file_put_contents($scriptFile, $script);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile) . ' 2>&1';
        exec($cmd, $output, $status);

        unlink($scriptFile);
        $dbconnect->restore();

        $outputText = implode("", $output);

        $this->assertSame(0, $status);
        $this->assertStringContainsString(
            'Error setting db connection charset to UTF-8...please check your db connection!',
            $outputText
        );
        $this->assertStringNotContainsString('AFTER', $outputText);
    }
}
