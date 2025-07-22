<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\PlayerFunctions;
use Lotgd\DataCache;
use Lotgd\Tests\Stubs\DummySettingsExtra;

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/tempstat.php';

if (!defined('DATACACHE_FILENAME_PREFIX')) {
    define('DATACACHE_FILENAME_PREFIX', 'datacache-');
}

final class PlayerFunctionsExtraTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        global $settings, $session;
        $this->cacheDir = sys_get_temp_dir() . '/lotgd_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
        $settings = new DummySettingsExtra([
            'exp-array'     => '50,100,150',
            'usedatacache'  => 1,
            'datacachepath' => $this->cacheDir,
            'maxlevel'      => 3,
        ]);
        $session = ['user' => []];
        $this->resetDataCache();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $f) {
                unlink($f);
            }
            rmdir($this->cacheDir);
        }
        unset($GLOBALS['settings'], $GLOBALS['session']);
        $this->resetDataCache();
    }

    private function resetDataCache(): void
    {
        $ref = new \ReflectionClass(DataCache::class);
        foreach (['cache' => [], 'path' => '', 'checkedOld' => false] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($val);
        }
    }

    public function testGetPlayerSpeedFromSession(): void
    {
        global $session;
        $session['user'] = ['dexterity' => 10, 'intelligence' => 20];
        $this->assertSame(12.5, PlayerFunctions::getPlayerSpeed());
    }

    public function testDragonkillmodWithHitpoints(): void
    {
        global $session;
        $session['user'] = [
            'dragonpoints' => ['wis', 'str', 'de', 'dex', 'dex'],
            'maxhitpoints' => 30,
            'level'        => 2,
        ];
        $this->assertSame(4.1, PlayerFunctions::getPlayerDragonkillmod(true));
    }

    public function testExpForNextLevelUsesCustomArray(): void
    {
        $ref = new \ReflectionClass(DataCache::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(['exparraydk0' => [50.0, 100.0, 150.0]]);
        $exp = PlayerFunctions::expForNextLevel(2, 0);
        $this->assertSame(100.0, $exp);
        $this->assertSame([50.0, 100.0, 150.0], DataCache::datacache('exparraydk0'));
    }
}
