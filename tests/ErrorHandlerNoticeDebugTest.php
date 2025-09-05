<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerNoticeDebugTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session, $output;

        $settings = new DummySettings([
            'show_notices' => 1,
        ]);
        $session = [
            'user' => [
                'superuser' => SU_SHOW_PHPNOTICE,
            ],
        ];
        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };

        $outputObj = Output::getInstance();
        $ref = new \ReflectionProperty(Output::class, 'output');
        $ref->setAccessible(true);
        $ref->setValue($outputObj, '');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['output']);
    }

    public function testNoticeDebugOutputContainsNoticeText(): void
    {
        ErrorHandler::handleError(E_NOTICE, 'Test notice', 'file.php', 123);

        $outputText = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Test notice', $outputText);
    }
}
