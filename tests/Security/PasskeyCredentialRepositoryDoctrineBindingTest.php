<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PasskeyCredentialRepositoryDoctrineBindingTest extends TestCase
{
    private PasskeyCredentialRepository $repository;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

        Database::$doctrineConnection = null;
        Database::$instance = null;
        DoctrineBootstrap::$conn = null;
        Database::$mockResults = [];

        $this->repository = new PasskeyCredentialRepository();
    }

    public function testInsertUsesExecuteStatementWithTypedParameters(): void
    {
        $conn = Database::getDoctrineConnection();
        $conn->executeStatements = [];

        $this->repository->insert(42, "cred'Ω", 'pem-data', 7, 'Primary Key', 'usb,nfc', 1700000000);

        $statement = $conn->executeStatements[0] ?? null;
        $this->assertNotNull($statement);
        $this->assertStringContainsString(':credential_id', $statement['sql']);
        $this->assertSame("cred'Ω", $statement['params']['credential_id']);
        $this->assertSame(ParameterType::STRING, $statement['types']['credential_id']);
    }

    public function testDeleteReturnsTrueWhenExecuteStatementAffectsRows(): void
    {
        $conn = Database::getDoctrineConnection();
        $deleted = $this->repository->deleteForAccount(99, 'cred-1');

        $statement = end($conn->executeStatements);
        $this->assertTrue($deleted);
        $this->assertStringContainsString('DELETE FROM', $statement['sql']);
        $this->assertSame(99, $statement['params']['acctid']);
        $this->assertSame(ParameterType::INTEGER, $statement['types']['acctid']);
    }
}
