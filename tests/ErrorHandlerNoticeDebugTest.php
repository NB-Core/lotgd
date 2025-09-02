<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerNoticeDebugTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session, $output, $forms_output;

        $settings = new DummySettings([
            'show_notices' => 1,
        ]);
        $session = [
            'user' => [
                'superuser' => SU_SHOW_PHPNOTICE,
            ],
        ];
        $forms_output = '';
        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['output'], $GLOBALS['forms_output']);
    }

    public function testNoticeDebugOutputContainsNoticeText(): void
    {
        ErrorHandler::handleError(E_NOTICE, 'Test notice', 'file.php', 123);

        $this->assertStringContainsString('Test notice', $GLOBALS['forms_output']);
    }
}
