<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use ErrorException;
use Lotgd\Moderate;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class ModeratedCommentaryFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        global $session;

        $session = [];
        $_SERVER['REQUEST_URI'] = '/moderate.php';
        $_SERVER['SCRIPT_NAME'] = 'moderate.php';
        $_GET = [];

        require_once __DIR__ . '/bootstrap.php';

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        // reset Output content
        $outputObj = Output::getInstance();
        $ref = new \ReflectionProperty(Output::class, 'output');
        $ref->setAccessible(true);
        $ref->setValue($outputObj, '');

        // clear navigation
        Nav::getInstance()->clearNavTree();

        Database::$mockResults = [];
    }

    public function testModerationHandlesGamePostWithoutName(): void
    {
        global $session;

        $session['user'] = [
            'superuser' => SU_EDIT_COMMENTS | SU_EDIT_USERS,
            'prefs' => ['timeoffset' => 0, 'timestamp' => 0],
            'recentcomments' => '2000-01-01 00:00:00',
            'loggedin' => true,
            'name' => 'Tester',
        ];

        $commentRows = [[
            'commentid' => 1,
            'comment' => '/game A mysterious echo',
            'acctid' => 0,
            'author' => 0,
            'name' => '',
            'clanrank' => 0,
            'clanshort' => '',
            'postdate' => '2000-01-01 00:00:00',
            'section' => 'test-section',
        ]];

        $settingsRows = [[
            'setting' => 'charset',
            'value' => 'UTF-8',
        ]];

        Database::$mockResults = [$settingsRows, true, $commentRows];
        Settings::getInstance();

        set_error_handler(static function (int $errno, string $errstr): void {
            throw new ErrorException($errstr, 0, $errno);
        });

        Moderate::viewmoderatedcommentary('test-section', 'X');

        restore_error_handler();

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('user.php?op=setupban&userid=0', $output);
    }
}
