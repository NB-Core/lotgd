<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Marrying Seth or Violet in the inn.
 *
 * lovers_seth.php and lovers_violet.php called AddNews::add() and
 * Buffs::applyBuff() without importing either class, so the wedding, and every
 * other branch that posts news or grants the lover's buff, ended in "Class not
 * found".
 *
 * Each case runs in a process of its own: the module needs get_module_pref()
 * and friends as plain functions, and other tests in the suite define those
 * with behaviour of their own.
 *
 * @group modules
 */
final class LoversWeddingTest extends TestCase
{
    private static function loadModule(): void
    {
        $helpers = [
            'get_module_pref' => 'function get_module_pref(string $name, ?string $module = null, ?int $user = null) { return $GLOBALS["lovers_prefs"][$name] ?? 0; }',
            'set_module_pref' => 'function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void { $GLOBALS["lovers_prefs"][$name] = $value; }',
            'httpget' => 'function httpget($name) { return $_GET[$name] ?? ""; }',
            'debuglog' => 'function debuglog($message, ...$rest): void {}',
        ];
        foreach ($helpers as $name => $definition) {
            if (!function_exists($name)) {
                eval($definition);
            }
        }

        $root = dirname(__DIR__, 2);
        require_once $root . '/lib/partner.php';
        require_once $root . '/modules/lovers.php';
        require_once $root . '/modules/lovers/lovers_seth.php';
        require_once $root . '/modules/lovers/lovers_violet.php';
    }

    /**
     * A player of 22 charm who has not seen their lover today asks to marry.
     */
    private static function proposeAs(int $sex, int $sexuality): void
    {
        global $session;
        class_exists(Database::class);
        Database::resetDoctrineConnection();
        Settings::setInstance(new DummySettings(['bard' => '`^Seth', 'barmaid' => '`%Violet']));
        $GLOBALS['lovers_prefs'] = ['seenlover' => 0];
        $session = [
            'user' => [
                'acctid' => 3, 'name' => 'Ann', 'sex' => $sex, 'charm' => 22, 'superuser' => 0,
                'marriedto' => 0, 'turns' => 10, 'prefs' => ['sexuality' => $sexuality],
            ],
            'bufflist' => [],
        ];
        $_GET = ['flirt' => '7'];
    }

    private static function postedWeddingNews(): bool
    {
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            if (str_contains((string) $statement['sql'], 'INSERT INTO news')
                && str_contains((string) ($statement['params']['newstext'] ?? ''), 'joined today in joyous matrimony')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The new day costs a married player charm, scaled by dragon kills. A
     * player at one charm is always left, which posts news from lovers.php.
     * The dragon kills arrive as a string here, as an older module can write
     * them back, and sqrt() takes no string under strict_types.
     */
    #[RunInSeparateProcess]
    public function testANewDayCanEndAMarriage(): void
    {
        self::loadModule();
        self::proposeAs(SEX_FEMALE, SEX_MALE);
        global $session;
        $session['user']['marriedto'] = 4294967295;
        $session['user']['charm'] = 1;
        $session['user']['dragonkills'] = '16';

        lovers_dohook('newday', []);

        $this->assertSame(0, $session['user']['marriedto']);
        $this->assertSame(0, $session['user']['charm']);
        $left = false;
        foreach (Database::getDoctrineConnection()->executeStatements as $statement) {
            $left = $left || str_contains((string) ($statement['params']['newstext'] ?? ''), 'has left');
        }
        $this->assertTrue($left);
    }

    #[RunInSeparateProcess]
    public function testMarryingSethPostsTheNewsAndGrantsTheBuff(): void
    {
        self::loadModule();
        self::proposeAs(SEX_FEMALE, SEX_MALE);

        lovers_seth();

        global $session;
        $this->assertSame(INT_MAX, $session['user']['marriedto']);
        $this->assertArrayHasKey('lover', $session['bufflist']);
        $this->assertTrue(self::postedWeddingNews());
    }

    #[RunInSeparateProcess]
    public function testMarryingVioletPostsTheNewsAndGrantsTheBuff(): void
    {
        self::loadModule();
        self::proposeAs(SEX_MALE, SEX_FEMALE);

        lovers_violet();

        global $session;
        $this->assertSame(INT_MAX, $session['user']['marriedto']);
        $this->assertArrayHasKey('lover', $session['bufflist']);
        $this->assertTrue(self::postedWeddingNews());
    }
}
