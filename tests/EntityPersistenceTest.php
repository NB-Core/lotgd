<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Common\Cache\ArrayCache;
use Lotgd\Entity\Account;
use Lotgd\Entity\Setting;
use Lotgd\Entity\ExtendedSetting;
use PHPUnit\Framework\TestCase;

final class EntityPersistenceTest extends TestCase
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

        $config = Setup::createAnnotationMetadataConfiguration([
            __DIR__ . '/../src/Lotgd/Entity'
        ], true, null, new ArrayCache(), false);
        $this->em = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testAccountPersistAndRetrieve(): void
    {
        $account = new Account();
        $account->setLogin('tester')
            ->setEmailaddress('tester@example.com')
            ->setPassword('secret')
            ->setName('Tester')
            ->setLevel(2)
            ->setGems(5);
        $account->setLaston(new \DateTime());
        $this->em->persist($account);
        $this->em->flush();
        $id = $account->getAcctid();
        $this->em->clear();

        $found = $this->em->find(Account::class, $id);
        $this->assertNotNull($found);
        $this->assertSame('tester@example.com', $found->getEmailaddress());
        $this->assertSame(5, $found->getGems());
    }

    public function testAccountUpdatePersistsChanges(): void
    {
        $account = new Account();
        $account->setLogin('update')
            ->setEmailaddress('user@example.com')
            ->setPassword('secret')
            ->setName('User')
            ->setLevel(1)
            ->setGems(1);
        $account->setLaston(new \DateTime());
        $this->em->persist($account);
        $this->em->flush();

        $account->setGems(10);
        $this->em->flush();
        $id = $account->getAcctid();
        $this->em->clear();

        $found = $this->em->find(Account::class, $id);
        $this->assertSame(10, $found->getGems());
    }

    public function testSettingPersistAndRetrieve(): void
    {
        $setting = new Setting();
        $setting->setSetting('foo')->setValue('bar');
        $this->em->persist($setting);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(Setting::class, 'foo');
        $this->assertSame('bar', $found->getValue());
    }

    public function testExtendedSettingPersistAndRetrieve(): void
    {
        $setting = new ExtendedSetting();
        $setting->setSetting('long')->setValue('value');
        $this->em->persist($setting);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(ExtendedSetting::class, 'long');
        $this->assertSame('value', $found->getValue());
    }
}
