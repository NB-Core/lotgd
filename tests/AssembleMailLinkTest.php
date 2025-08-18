<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PageParts;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class AssembleMailLinkTest extends TestCase
{
    private string $asyncFile;
    private string $backupFile;

    protected function setUp(): void
    {
        $this->asyncFile = dirname(__DIR__) . '/async/maillink.php';
        $this->backupFile = $this->asyncFile . '.bak';
        Database::$queryCacheResults = [];
    }

    protected function tearDown(): void
    {
        global $session, $settings;
        unset($session, $settings);
        Database::$queryCacheResults = [];
        if (is_file($this->backupFile)) {
            if (is_file($this->asyncFile)) {
                unlink($this->asyncFile);
            }
            rename($this->backupFile, $this->asyncFile);
        }
    }

    public function testWithoutAjax(): void
    {
        global $session, $settings;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'prefs' => []]];
        $settings = new DummySettings(['ajax' => 0]);
        Database::$queryCacheResults['mail-1'] = [['seencount' => 0, 'notseen' => 0]];
        [$header] = PageParts::assembleMailLink('{mail}', '');
        $this->assertStringContainsString("<a href='mail.php'", $header);
        $this->assertStringNotContainsString("<div id='maillink'>", $header);
    }

    public function testWithAjax(): void
    {
        global $session, $settings;
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'prefs' => ['ajax' => 1]]];
        $settings = new DummySettings(['ajax' => 1]);
        Database::$queryCacheResults['mail-1'] = [['seencount' => 0, 'notseen' => 0]];
        if (file_exists($this->asyncFile)) {
            rename($this->asyncFile, $this->backupFile);
        }
        file_put_contents($this->asyncFile, "<?php\n\$maillink_add_pre='PRE';\n\$maillink_add_after='AFTER';\n");
        [$header] = PageParts::assembleMailLink('{mail}', '');
        $this->assertStringContainsString("PRE<div id='maillink'>", $header);
        $this->assertStringContainsString("</div>AFTER", $header);
    }
}
