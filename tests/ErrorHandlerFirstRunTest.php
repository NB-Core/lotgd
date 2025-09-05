<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerFirstRunTest extends TestCase
{
    private string $cacheDir;
    private string $cacheFile;
    private array $initialData;

    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject;

        $mail_sent_count = 0;
        $last_subject = '';

        $this->cacheDir = sys_get_temp_dir() . '/lotgd_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);

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

        $this->initialData = ['firstrun' => true, 'errors' => []];
        DataCache::getInstance()->updatedatacache('error_notify', $this->initialData);
        $this->cacheFile = DataCache::getInstance()->makecachetempname('error_notify');

        new PHPMailer();

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

    public function testFirstRunDoesNotSendNotification(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');

        $this->assertSame(0, $GLOBALS['mail_sent_count']);
        $data = json_decode(file_get_contents($this->cacheFile), true);
        $this->assertSame($this->initialData, $data);
    }
}
