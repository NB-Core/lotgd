<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerNoticeDebugTest extends TestCase
{
    private int $originalErrorReporting;

    protected function setUp(): void
    {
        global $settings, $session, $output;

        $this->originalErrorReporting = error_reporting(E_ALL);
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

        Output::getInstance()->resetOutput();
    }

    protected function tearDown(): void
    {
        error_reporting($this->originalErrorReporting);
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['output']);
    }

    public function testNoticeDebugOutputContainsNoticeText(): void
    {
        ErrorHandler::handleError(E_NOTICE, 'Test notice', 'file.php', 123);

        $outputText = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Test notice', $outputText);
    }
}
