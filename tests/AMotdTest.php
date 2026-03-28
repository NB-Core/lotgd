<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\DBAL\ParameterType;
use Lotgd\Motd;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

final class AMotdTest extends TestCase
{
    protected function setUp(): void
    {
        global $forms_output, $session;
        $forms_output = '';
        $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'superuser' => 0]];
        Output::setInstance(null);
        \Lotgd\MySQL\Database::$settings_table = [];
        \Lotgd\MySQL\Database::$onlineCounter = 0;
        \Lotgd\MySQL\Database::$affected_rows = 0;
        Database::resetDoctrineConnection();
        Database::getDoctrineConnection()->executeStatements = [];
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

    public function testSavePollSerializesDataWithTypedParameters(): void
    {
        $_POST['motdtitle'] = 'Title';
        $_POST['motdbody'] = 'Question?';
        $_POST['opt'] = ['Yes', 'No'];

        $connection = Database::getDoctrineConnection();
        Motd::savePoll();

        $statement = $connection->executeStatements[0] ?? null;
        $this->assertNotNull($statement);
        $this->assertSame(addslashes(serialize(['body' => 'Question?', 'opt' => ['Yes', 'No']])), $statement['params']['body'] ?? null);
        $this->assertSame(ParameterType::STRING, $statement['types']['body'] ?? null);
        $this->assertSame(ParameterType::INTEGER, $statement['types']['author'] ?? null);
    }
}
