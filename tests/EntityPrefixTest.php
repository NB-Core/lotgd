<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Lotgd\Doctrine\TablePrefixSubscriber;
use Lotgd\Entity\Account;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Lotgd\Doctrine\TablePrefixSubscriber
 */
final class EntityPrefixTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension not installed');
        }

        if (!class_exists('Doctrine\\Common\\Annotations\\AnnotationReader')) {
            $this->markTestSkipped('Doctrine annotations not installed');
        }

        global $DB_PREFIX;
        $DB_PREFIX = 'pre_';

        $config = Setup::createAnnotationMetadataConfiguration([
            __DIR__ . '/../src/Lotgd/Entity'
        ], true, null, new ArrayCache(), false);

        $evm = new EventManager();
        $evm->addEventSubscriber(new TablePrefixSubscriber($DB_PREFIX));

        $this->em = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config, $evm);
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testAccountPersistAndRetrieveWithPrefix(): void
    {
        $meta = $this->em->getClassMetadata(Account::class);
        $this->assertSame('pre_accounts', $meta->getTableName());

        $account = new Account();
        $account->setLogin('tester')
            ->setEmailaddress('tester@example.com')
            ->setPassword('secret')
            ->setName('Tester')
            ->setLevel(1)
            ->setGems(0);
        $account->setLaston(new \DateTime());
        $this->em->persist($account);
        $this->em->flush();
        $id = $account->getAcctid();
        $this->em->clear();

        $found = $this->em->find(Account::class, $id);
        $this->assertNotNull($found);
        $this->assertSame('tester@example.com', $found->getEmailaddress());
    }
}
