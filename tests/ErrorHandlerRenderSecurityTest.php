<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerRenderSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session, $output, $mail_sent_count, $last_subject, $last_body;

        $session = [];
        $mail_sent_count = 0;
        $last_subject = '';
        $last_body = '';

        $settings = new DummySettings([
            'debug' => 0,
            'notify_on_error' => 1,
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);

        // Ensure the PHPMailer stub aliases are available during test execution.
        new PHPMailer();

        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    public function testAnonymousUserSeesGenericErrorOnly(): void
    {
        ob_start();
        ErrorHandler::renderError('Top secret exception text', '/var/www/secret.php', 42, '<pre>secret stack</pre>');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('An unexpected error occurred. Please try again later.', $html);
        $this->assertStringNotContainsString('Top secret exception text', $html);
        $this->assertStringNotContainsString('/var/www/secret.php', $html);
        $this->assertStringNotContainsString('secret stack', $html);
    }

    public function testMegauserSeesDetailedErrorOutput(): void
    {
        global $session;

        $session['user']['superuser'] = SU_MEGAUSER;

        ob_start();
        ErrorHandler::renderError('Top secret exception text', '/var/www/secret.php', 42, '<pre>secret stack</pre>');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Top secret exception text', $html);
        $this->assertStringContainsString('/var/www/secret.php', $html);
        $this->assertStringContainsString('secret stack', $html);
    }

    public function testDebugModeShowsDetailedErrorForAnonymousUser(): void
    {
        global $settings;

        $settings = new DummySettings([
            'debug' => 1,
        ]);

        ob_start();
        ErrorHandler::renderError('Top secret exception text', '/var/www/secret.php', 42, '<pre>secret stack</pre>');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Top secret exception text', $html);
        $this->assertStringContainsString('/var/www/secret.php', $html);
        $this->assertStringContainsString('secret stack', $html);
    }

    public function testExceptionNotificationStillContainsTechnicalDetailsInPublicMode(): void
    {
        global $settings;

        $settings = new DummySettings([
            'debug' => 0,
            'notify_on_error' => 1,
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);

        $_SERVER['HTTP_HOST'] = 'example.com';
        ob_start();
        @ErrorHandler::handleException(new \RuntimeException('Boom detail'));
        ob_end_clean();

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertStringContainsString('Boom detail', $GLOBALS['last_body']);
        $this->assertStringContainsString('ErrorHandlerRenderSecurityTest.php', $GLOBALS['last_body']);
    }
}
