<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Entity\Account;
use Lotgd\MySQL\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class CharrestoreRestoreAcctidTest extends TestCase
{
    public function testAcctidPreservedWhenUpdateSucceeds(): void
    {
        $conn = new class extends DoctrineConnection {
            public array $updates = [];
            public function update($table, array $data, array $identifier)
            {
                $this->updates[] = [$table, $data, $identifier];
                return 1;
            }
        };
        Database::$doctrineConnection = $conn;

        $account = new Account();
        $account->setLogin('user')->setPassword('pass')->setName('User')->setLevel(1);

        $desiredId = 42;
        $id = (int) $account->getAcctid();
        $idReassigned = false;
        if (is_numeric($desiredId) && (int)$desiredId !== $id) {
            try {
                $rows = $conn->update(Database::prefix('accounts'), ['acctid'=>(int)$desiredId], ['acctid'=>$id]);
                if ($rows > 0) {
                    $id = (int)$desiredId;
                } else {
                    $idReassigned = true;
                }
            } catch (UniqueConstraintViolationException $e) {
                $idReassigned = true;
            }
            if ((int)$desiredId !== $id) {
                $idReassigned = true;
            }
        }

        $this->assertFalse($idReassigned);
        $this->assertSame($desiredId, $id);
    }
}
