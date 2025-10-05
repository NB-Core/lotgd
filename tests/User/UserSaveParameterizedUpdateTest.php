<?php

declare(strict_types=1);

namespace {
    if (!function_exists('httpallpost')) {
        function httpallpost(): array
        {
            return $_POST ?? [];
        }
    }
    if (!function_exists('httppost')) {
        function httppost(string $name): string
        {
            return $_POST[$name] ?? '';
        }
    }
    if (!function_exists('httpget')) {
        function httpget(string $name): string
        {
            return $_GET[$name] ?? '';
        }
    }
    if (!function_exists('getsetting')) {
        function getsetting(string $setting, string $default = ''): string
        {
            return $default;
        }
    }
    if (!function_exists('httpset')) {
        function httpset(mixed ...$args): void
        {
        }
    }
    if (!function_exists('debuglog')) {
        function debuglog(string $message, mixed $userid = null): void
        {
        }
    }
    if (!function_exists('debug')) {
        function debug(mixed ...$args): void
        {
        }
    }
    if (!function_exists('sanitize_colorname')) {
        function sanitize_colorname(mixed ...$args): string
        {
            return $args[1] ?? '';
        }
    }
    if (!function_exists('sanitize_html')) {
        function sanitize_html(string $str): string
        {
            return $str;
        }
    }
    if (!function_exists('soap')) {
        function soap(string $str): string
        {
            return $str;
        }
    }
    if (!function_exists('show_bitfield')) {
        function show_bitfield(mixed $value): string
        {
            return (string) $value;
        }
    }
}


namespace Lotgd\Tests\User {
    use Lotgd\Names;
    use Lotgd\Settings;
    use Lotgd\MySQL\Database;
    use PHPUnit\Framework\TestCase;

    final class UserSaveParameterizedUpdateTest extends TestCase
    {
        public function testUpdateBindsParametersForQuotedAndMultibyteNames(): void
        {
            Database::resetDoctrineConnection();
            $connection = Database::getDoctrineConnection();
            $connection->executeStatements = [];

            $initialOldvalues = [
                'name33'     => 'Baron Old Hero`0',
                'title'      => 'Baron',
                'ctitle'     => '',
                'playername' => 'Old Hero',
                'name'       => 'Baron Old Hero`0',
                'superuser'  => 0,
            ];

            $postValues = [
                'name33'     => '《蒼き》 "Hero"',
                'title'      => 'Sir "Quotes"',
                'ctitle'     => '《龍の守護者》',
                'playername' => '勇者"タロウ"',
                'superuser'  => ['1' => '1'],
            ];

            $serialized = htmlentities(serialize($initialOldvalues), ENT_COMPAT, 'UTF-8');

            $expectedOldvalues = $initialOldvalues;
            $expectedParams = [];

            $spaceInName = Settings::getInstance()->getSetting('spaceinname', 0);

            $tmpName33 = sanitize_colorname($spaceInName, $postValues['name33'], true);
            $tmpName33 = preg_replace('/[`][cHw]/', '', $tmpName33);
            $tmpName33 = sanitize_html($tmpName33);
            $newName33 = Names::changePlayerName($tmpName33, $expectedOldvalues);
            $expectedParams['name33'] = $newName33;
            $expectedOldvalues['name'] = $newName33;

            $tmpTitle = sanitize_colorname(true, $postValues['title'], true);
            $tmpTitle = preg_replace('/[`][cHw]/', '', $tmpTitle);
            $tmpTitle = sanitize_html($tmpTitle);
            $newTitleName = Names::changePlayerTitle($tmpTitle, $expectedOldvalues);
            $expectedParams['title'] = $tmpTitle;
            $expectedOldvalues['title'] = $tmpTitle;
            if (!isset($expectedOldvalues['name']) || $newTitleName !== $expectedOldvalues['name']) {
                $expectedParams['name'] = $newTitleName;
                $expectedOldvalues['name'] = $newTitleName;
            }

            $tmpCtitle = sanitize_colorname(true, $postValues['ctitle'], true);
            $tmpCtitle = preg_replace('/[`][cHw]/', '', $tmpCtitle);
            $tmpCtitle = sanitize_html($tmpCtitle);
            $newCtitleName = Names::changePlayerCtitle($tmpCtitle, $expectedOldvalues);
            $expectedParams['ctitle'] = $tmpCtitle;
            $expectedOldvalues['ctitle'] = $tmpCtitle;
            if (!isset($expectedOldvalues['name']) || $newCtitleName !== $expectedOldvalues['name']) {
                $expectedParams['name'] = $newCtitleName;
                $expectedOldvalues['name'] = $newCtitleName;
            }

            $tmpPlayername = sanitize_colorname(true, $postValues['playername'], true);
            $tmpPlayername = preg_replace('/[`][cHw]/', '', $tmpPlayername);
            $tmpPlayername = sanitize_html($tmpPlayername);
            $newPlayerName = Names::changePlayerName($tmpPlayername, $expectedOldvalues);
            $expectedParams['playername'] = $tmpPlayername;
            $expectedOldvalues['playername'] = $tmpPlayername;
            if (!isset($expectedOldvalues['name']) || $newPlayerName !== $expectedOldvalues['name']) {
                $expectedParams['name'] = $newPlayerName;
                $expectedOldvalues['name'] = $newPlayerName;
            }

            $targetUserid = 7;

            $sessionState = [
                'user' => [
                    'acctid'     => 42,
                    'superuser'  => SU_MEGAUSER,
                    'name'       => 'Admin',
                    'password'   => 'hash',
                    'title'      => $initialOldvalues['title'],
                    'ctitle'     => $initialOldvalues['ctitle'],
                    'playername' => $initialOldvalues['playername'],
                ],
            ];

            $value = 0;
            foreach ($postValues['superuser'] as $k => $v) {
                if ($v) {
                    $value += (int) $k;
                }
            }
            $oldsup = (int) ($initialOldvalues['superuser'] ?? 0);
            $stripfield = ($oldsup | $sessionState['user']['superuser'] | SU_ANYONE_CAN_SET | ($sessionState['user']['superuser'] & SU_MEGAUSER ? 0xFFFFFFFF : 0));
            $value = $value & $stripfield;
            $unremovable = ~ ((int) $sessionState['user']['superuser'] | SU_ANYONE_CAN_SET | ($sessionState['user']['superuser'] & SU_MEGAUSER ? 0xFFFFFFFF : 0));
            $filteredunremovable = $oldsup & $unremovable;
            $value = $value | $filteredunremovable;
            if ((int) $value !== $oldsup) {
                $expectedParams['superuser'] = (int) $value;
            }

            $include = function () use ($postValues, $serialized, $initialOldvalues, $sessionState, $targetUserid): void {
                global $_POST, $_GET, $session, $userid, $userinfo;

                $_POST = $postValues + ['oldvalues' => $serialized];
                $_GET = [];

                $session = $sessionState;
                $userid = $targetUserid;
                $userinfo = $initialOldvalues;

                require __DIR__ . '/../../pages/user/user_save.php';
            };

            \Closure::bind($include, null, null)();

            $this->assertNotEmpty($connection->executeStatements);

            $statement = null;
            foreach (array_reverse($connection->executeStatements) as $entry) {
                if (str_contains($entry['sql'], 'UPDATE ' . Database::prefix('accounts'))) {
                    $statement = $entry;
                    break;
                }
            }

            $this->assertNotNull($statement, 'No UPDATE statement executed for accounts table');
            $params = $statement['params'];

            $this->assertArrayHasKey('acctid', $params);
            $this->assertSame($targetUserid, $params['acctid']);

            foreach ($expectedParams as $column => $expectedValue) {
                $this->assertArrayHasKey($column, $params, sprintf('Missing parameter for column %s', $column));
                $this->assertSame($expectedValue, $params[$column], sprintf('Unexpected value for %s', $column));
            }

            $this->assertSame($postValues['title'], $params['title']);
            $this->assertSame($postValues['ctitle'], $params['ctitle']);
            $this->assertSame($postValues['playername'], $params['playername']);
            $this->assertStringContainsString('"', $params['playername']);
            $this->assertStringContainsString('勇者', $params['playername']);
        }
    }
}

