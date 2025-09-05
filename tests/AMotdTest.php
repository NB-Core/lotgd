<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Motd;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

final class AMotdTest extends TestCase
{
    protected function setUp(): void
    {
        global $forms_output, $session;
        $forms_output = '';
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'superuser' => 0]];
        $ref = new \ReflectionClass(Output::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$onlineCounter = 0;
        \Lotgd\MySQL\Database::$affected_rows = 0;
        \Lotgd\MySQL\Database::$lastSql = '';
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session'], $GLOBALS['forms_output']);
        $_POST = [];
    }

    public function testPollItemShowsRadioButtonsForLoggedInUser(): void
    {
        $data = ['body' => 'Question?', 'opt' => ['Yes', 'No']];
        $body = serialize($data);

        Motd::pollItem(1, 'Subject', $body, 'Author', '2024-01-01 00:00:00');

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("type='radio' name='choice'", $output);
    }

    public function testPollItemUnserializesSlashedData(): void
    {
        $data = ['body' => 'Question?', 'opt' => ['Yes', 'No']];
        $body = addslashes(serialize($data));

        Motd::pollItem(1, 'Subject', $body, 'Author', '2024-01-01 00:00:00');

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("type='radio' name='choice'", $output);
    }

    public function testSavePollSerializesData(): void
    {
        $_POST['motdtitle'] = 'Title';
        $_POST['motdbody'] = 'Question?';
        $_POST['opt'] = ['Yes', 'No'];

        Motd::savePoll();

        $expected = addslashes(serialize(['body' => 'Question?', 'opt' => ['Yes', 'No']]));
        $this->assertStringContainsString($expected, \Lotgd\MySQL\Database::$lastSql);
    }
}
