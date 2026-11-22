<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use PHPUnit\Framework\TestCase;

final class ExpbarTest extends TestCase
{
    public function testBarCompletionAgreesWithTheExperienceRequirement(): void
    {
        // Use the production wrappers in a fresh process, outside the suite's
        // global function and Database stand-ins.
        $script = <<<'PHP'
define('DB_NODB', true);
$root = $argv[1];
chdir($root);
require $root . '/autoload.php';
require $root . '/src/Lotgd/Config/constants.php';
foreach (['output', 'translator', 'settings', 'modules', 'pageparts', 'tempstat'] as $lib) {
    require_once $root . '/lib/' . $lib . '.php';
}
require $root . '/modules/expbar.php';
\Lotgd\Modules\ModuleManager::setMostRecentModule('expbar');
\Lotgd\Modules\ModuleManager::setSettings([
    'expbar' => ['showexpnumber' => 0, 'shownextgoal' => 0, 'showbar' => 1],
]);
$session = ['user' => ['alive' => 1, 'level' => 14, 'dragonkills' => 1]];
$temp_user_stats = [];
$result = [];
foreach ([29761, 29762, 33092, 36354, 36355, 36411, 36420, 36421, 36422] as $xp) {
    $session['user']['experience'] = $xp;
    wipe_charstats();
    setcharstat('Character Info', 'Experience', (string) $xp);
    expbar_dohook('charstats', []);
    $result[$xp] = getcharstat('Character Info', 'Experience');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, dirname(__DIR__, 2)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);
        self::assertSame('', $stderr);
        $bars = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        // Default thresholds at level 14 with one DK: min 29762, required 36421.
        foreach ([
            29761 => [0, 'white'],
            29762 => [0, 'white'],
            33092 => [50, 'white'],
            36354 => [98, 'white'],
            36355 => [99, 'white'],
            36411 => [99, 'white'],
            36420 => [99, 'white'],
            36421 => [100, 'blue'],
            36422 => [100, 'blue'],
        ] as $xp => [$filled, $color]) {
            self::assertStringContainsString("width='$filled%' bgcolor='$color'", $bars[$xp], "XP: $xp");
            $remaining = 100 - $filled;
            self::assertStringContainsString("<td width='$remaining%'>", $bars[$xp], "XP: $xp");
        }
    }
}
