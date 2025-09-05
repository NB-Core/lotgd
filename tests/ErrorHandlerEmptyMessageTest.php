<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerEmptyMessageTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject;

        $mail_sent_count = 0;
        $last_subject = '';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $this->cacheDir = sys_get_temp_dir() . '/lotgd_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);

        // Reset DataCache static properties
        $ref = new \ReflectionClass(DataCache::class);
        foreach (['cache' => [], 'path' => '', 'checkedOld' => false] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $val);
        }

        $settings = new DummySettings([
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 1,
            'datacachepath' => $this->cacheDir,
        ]);

        // Ensure the PHPMailer stub is loaded so Mail::send uses it
        new PHPMailer();

        // Provide minimal output handler for debug()
        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    protected function tearDown(): void
    {
        global $settings;
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->cacheDir);
        }
        unset($settings, $_SERVER['HTTP_HOST']);
    }

    public function testEmptyMessageDoesNothing(): void
    {
        ErrorHandler::errorNotify(E_ERROR, '', 'file.php', 1, '');

        $this->assertSame(0, $GLOBALS['mail_sent_count']);
        $this->assertFalse(DataCache::getInstance()->datacache('error_notify'));
        $this->assertSame([], glob($this->cacheDir . '/*'));
    }
}
