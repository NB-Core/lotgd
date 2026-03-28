<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\Async\Handler\Timeout;
use Lotgd\ForcedNavigation;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class SessionHardeningDoctrineBindingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

        Database::$doctrineConnection = null;
        Database::$instance = null;
        DoctrineBootstrap::$conn = null;
        Database::$mockResults = [];
        Database::$settings_table = [
            'charset' => 'UTF-8',
            'LOGINTIMEOUT' => 900,
            'enabletranslation' => true,
            'collecttexts' => '',
        ];

        Settings::setInstance(new DummySettings(Database::$settings_table));

        $_SERVER['REQUEST_URI'] = '/village.php';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['PHP_SELF'] = '/index.php';

        global $session;
        $session = [
            'loggedin' => true,
            'user' => [
                'acctid' => 22,
                'laston' => date('Y-m-d H:i:s'),
                'loggedin' => 1,
                'allowednavs' => serialize(['/village.php' => true]),
                'bufflist' => serialize([]),
                'dragonpoints' => serialize([]),
                'prefs' => serialize([]),
            ],
            'allowednavs' => ['/village.php' => true],
        ];
    }

    public function testForcedNavigationLoadsAccountWithBoundAcctId(): void
    {
        $conn = Database::getDoctrineConnection();
        $conn->fetchAllResults = [[[
            'acctid' => 22,
            'laston' => date('Y-m-d H:i:s'),
            'loggedin' => 1,
            'allowednavs' => serialize(['/village.php' => true]),
            'bufflist' => serialize([]),
            'dragonpoints' => serialize([]),
            'prefs' => serialize([]),
        ]]];

        ForcedNavigation::doForcedNav(false, true);

        $params = $conn->executeQueryParams[0] ?? [];
        $types = $conn->executeQueryTypes[0] ?? [];
        $this->assertSame(22, $params['acctid'] ?? null);
        $this->assertSame(ParameterType::INTEGER, $types['acctid'] ?? null);
    }

    public function testTimeoutStatusUsesBoundParamsWhenKeepingSessionAlive(): void
    {
        global $session;

        $conn = Database::getDoctrineConnection();
        Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(true);
        $response = Timeout::getInstance()->timeoutStatus(true);

        $this->assertNotNull($response);
        $statement = end($conn->executeStatements);
        $this->assertStringContainsString('UPDATE', $statement['sql']);
        $this->assertSame(22, $statement['params']['acctid'] ?? null);
        $this->assertSame(ParameterType::INTEGER, $statement['types']['acctid'] ?? null);

        $session['loggedin'] = false;
        Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(false);
    }
}
