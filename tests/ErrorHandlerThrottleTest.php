<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerThrottleTest extends TestCase
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

        $ref = new \ReflectionClass(DataCache::class);
        foreach (['cache' => [], 'path' => '', 'checkedOld' => false] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $val);
        }

        $settings = new DummySettings([
            'notify_on_error' => 1,
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 1,
            'datacachepath' => $this->cacheDir,
            'notify_every' => 30,
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

    public function testErrorNotificationIsThrottled(): void
    {
        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');
        $this->assertSame(1, $GLOBALS['mail_sent_count']);

        $cacheFile = DataCache::getInstance()->makecachetempname('error_notify');
        $this->assertFileExists($cacheFile);

        $contents = file_get_contents($cacheFile);
        $this->assertNotFalse($contents);
        $data = json_decode($contents, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('Test error', $data['errors']);
        $this->assertLessThanOrEqual(5, time() - $data['errors']['Test error']);

        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');
        $this->assertSame(1, $GLOBALS['mail_sent_count']);
    }
}
