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

final class RepositoryDoctrineTest extends TestCase
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

        $config = Setup::createAnnotationMetadataConfiguration(
            [__DIR__ . '/../src/Lotgd/Entity'],
            true,
            null,
            new ArrayCache(),
            false
        );
        $this->em = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);
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

        if (!class_exists(\Lotgd\Repository\AccountRepository::class, false)) {
            require __DIR__ . '/../src/Lotgd/Repository/AccountRepository.php';
        }
        $repo = new \Lotgd\Repository\AccountRepository(
            $this->em,
            $this->em->getClassMetadata(Account::class)
        );
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

        if (!class_exists(\Lotgd\Repository\SettingRepository::class, false)) {
            require __DIR__ . '/../src/Lotgd/Repository/SettingRepository.php';
        }
        $repo = new \Lotgd\Repository\SettingRepository(
            $this->em,
            $this->em->getClassMetadata(Setting::class)
        );

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

        if (!class_exists(\Lotgd\Repository\ExtendedSettingRepository::class, false)) {
            require __DIR__ . '/../src/Lotgd/Repository/ExtendedSettingRepository.php';
        }
        $repo = new \Lotgd\Repository\ExtendedSettingRepository(
            $this->em,
            $this->em->getClassMetadata(ExtendedSetting::class)
        );

        $this->assertSame('qux', $repo->findValue('baz'));
        $this->assertNull($repo->findValue('nope'));
    }
}
