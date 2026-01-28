<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Lotgd\Entity\Account;
use Lotgd\Entity\Setting;
use Lotgd\Entity\ExtendedSetting;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RepositoryDoctrineTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension not installed');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../src/Lotgd/Entity'],
            true,
            null,
            new ArrayAdapter(),
            true
        );
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->em = new EntityManager($connection, $config);
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testAccountRepositoryFindByLogin(): void
    {
        $account = new Account();
        $account->setLogin('john')
            ->setEmailaddress('john@example.com')
            ->setPassword('secret')
            ->setName('John');
        $account->setLevel(1)->setGems(0);
        $account->setLaston(new \DateTime());
        $this->em->persist($account);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(Account::class);
        $this->assertInstanceOf(\Lotgd\Repository\AccountRepository::class, $repo);
        $found = $repo->findByLogin('john');

        $this->assertInstanceOf(Account::class, $found);
        $this->assertSame('john', $found->getLogin());
        $this->assertNull($repo->findByLogin('missing'));
    }

    public function testSettingRepositoryFindValue(): void
    {
        $setting = new Setting();
        $setting->setSetting('foo')->setValue('bar');
        $this->em->persist($setting);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(Setting::class);
        $this->assertInstanceOf(\Lotgd\Repository\SettingRepository::class, $repo);

        $this->assertSame('bar', $repo->findValue('foo'));
        $this->assertNull($repo->findValue('unknown'));
    }

    public function testExtendedSettingRepositoryFindValue(): void
    {
        $setting = new ExtendedSetting();
        $setting->setSetting('baz')->setValue('qux');
        $this->em->persist($setting);
        $this->em->flush();
        $this->em->clear();

        $repo = $this->em->getRepository(ExtendedSetting::class);
        $this->assertInstanceOf(\Lotgd\Repository\ExtendedSettingRepository::class, $repo);

        $this->assertSame('qux', $repo->findValue('baz'));
        $this->assertNull($repo->findValue('nope'));
    }
}
