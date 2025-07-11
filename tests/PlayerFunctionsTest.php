<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\PlayerFunctions;
use Lotgd\Settings;

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/tempstat.php';

class DummySettings extends Settings
{
    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function getSetting(string|int $settingname, mixed $default = false): mixed
    {
        return $this->values[$settingname] ?? $default;
    }

    public function loadSettings(): void {}
    public function clearSettings(): void {}
    public function saveSetting(string|int $settingname, mixed $value): bool
    {
        $this->values[$settingname] = $value;
        return true;
    }
    public function getArray(): array
    {
        return $this->values;
    }
}

final class PlayerFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session, $temp_user_stats;
        $settings = new DummySettings([
            'moneydecimalpoint' => '.',
            'moneythousandssep' => ',',
        ]);
        $session = ['user' => []];
        $temp_user_stats = ['add' => [], 'is_suspended' => false];
    }

    public function testCheckTempStatUsesDefaultFormatting(): void
    {
        global $session;
        $session['user']['strength'] = 1000.0;
        apply_temp_stat('strength', 10.4);
        $result = PlayerFunctions::checkTempStat('strength', 1);
        $this->assertSame(' `&(1,000.0`@+10.4`&)', $result);
    }

    public function testCheckTempStatHonorsCustomLocale(): void
    {
        $this->setCustomLocale(',', '.');
        global $session;
        $session['user']['strength'] = 1000.0;
        apply_temp_stat('strength', 10.4);
        $result = PlayerFunctions::checkTempStat('strength', 1);
        $this->assertSame(' `&(1.000,0`@+10,4`&)', $result);
    }
}
