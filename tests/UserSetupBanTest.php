<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class UserSetupBanTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $forms_output;

        $session = [];
        $_GET = [];
        $_POST = [];
        $forms_output = '';

        require_once __DIR__ . '/bootstrap.php';

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        if (! defined('DB_NODB')) {
            define('DB_NODB', true);
        }

        $outputObj = Output::getInstance();
        $ref = new \ReflectionProperty(Output::class, 'output');
        $ref->setAccessible(true);
        $ref->setValue($outputObj, '');

        Database::$mockResults = [];
        Database::$queries = [];

        if (! function_exists('reltime')) {
            eval('function reltime(int $timestamp): string { return "ago"; }');
        }
    }

    public function testReasonPrefilledFromSessionCache(): void
    {
        global $session, $userid;

        $session['moderation']['ban_reasons'][5] = 'cached reason text';
        $userid = 42;

        Database::$mockResults = [[[
            'name' => 'Victim',
            'lastip' => '192.0.2.10',
            'uniqueid' => 'unique-123',
        ]]];

        $_GET['commentid'] = '5';

        require __DIR__ . '/../pages/user/user_setupban.php';

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('value="cached reason text"', $output);
        $this->assertCount(1, Database::$queries);
    }

    public function testReasonFallbacksToCommentLookup(): void
    {
        global $session, $userid;

        $userid = 77;
        $longComment = str_repeat('Long reason ', 40);

        Database::$mockResults = [[[
            'name' => 'Poster',
            'lastip' => '203.0.113.20',
            'uniqueid' => 'unique-777',
        ]], [[
            'comment' => $longComment,
            'name' => 'Poster',
        ]]];

        $_GET['commentid'] = '8';

        require __DIR__ . '/../pages/user/user_setupban.php';

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Long reason Long reason', $output);
        $this->assertCount(2, Database::$queries);
    }
}
