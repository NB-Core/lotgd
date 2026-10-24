<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

/**
 * A tabbed form is markup only: its sections travel as data for
 * assets/js/lotgd.showform.js, so a page with one can be swapped in place.
 */
final class ShowFormTabbedMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        Output::setInstance(null);
        $GLOBALS['session'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/prefs.php';
    }

    public function testTheSectionsAreDataAndNoScriptIsWritten(): void
    {
        Forms::showFormTabbed([
            'First <b>Section</b>,title',
            'flag' => 'Flag,checkbox',
            "It's second,title",
            'name' => 'Name,string',
        ], ['flag' => 1, 'name' => 'x']);
        $html = Output::getInstance()->getRawOutput();

        self::assertStringNotContainsString('<script', $html);
        self::assertSame(1, preg_match("/<span hidden data-lotgd-showform='([^']*)'><\\/span>/", $html, $match));
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertIsArray($config);
        self::assertStringContainsString("id='showFormSection{$config['id']}'", $html);
        self::assertStringContainsString("id='showFormTable{$config['id']}'", $html);
        self::assertSame(['First <b>Section</b>', "It's second"], array_values($config['sections']));
        self::assertSame(0, $config['start']);
        self::assertSame('All', $config['all']);
        self::assertSame('Search', $config['search']);
    }
}
