<?php

declare(strict_types=1);

namespace {
    if (!function_exists('set_module_objpref')) {
        function set_module_objpref(string $objtype, $objid, string $name, mixed $value, ?string $module = null): void
        {
            \Lotgd\Modules\HookHandler::setObjPref($objtype, $objid, $name, $value, $module);
        }
    }
    if (!function_exists('get_module_objpref')) {
        function get_module_objpref(string $type, $objid, string $name, ?string $module = null)
        {
            return \Lotgd\Modules\HookHandler::getObjPref($type, $objid, $name, $module);
        }
    }
    if (!function_exists('increment_module_objpref')) {
        function increment_module_objpref(string $objtype, $objid, string $name, int|float $value = 1, ?string $module = null): void
        {
            \Lotgd\Modules\HookHandler::incrementObjPref($objtype, $objid, $name, $value, $module);
        }
    }
    if (!function_exists('module_delete_objprefs')) {
        function module_delete_objprefs(string $objtype, $objid): void
        {
            \Lotgd\Modules\HookHandler::deleteObjPrefs($objtype, $objid);
        }
    }
}

namespace Lotgd\Tests\Modules\Prefs {

    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DoctrineConnection;
    use Lotgd\Tests\Stubs\DoctrineResult;
    use PHPUnit\Framework\TestCase;

/**
 * @group prefs
 */
    final class ModuleObjPrefsTest extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);
            Database::$queryCacheResults = [];
            Database::$lastSql           = '';
            Database::$affected_rows     = 0;

            $conn = new class extends DoctrineConnection {
                public array $objprefs = [];

                public function executeQuery(string $sql): DoctrineResult
                {
                    $this->queries[] = $sql;
                    return new DoctrineResult([]);
                }

                public function executeStatement(string $sql, array $params = []): int
                {
                    $this->queries[] = $sql;

                    if (preg_match("/REPLACE INTO module_objprefs\\(modulename,objtype,setting,objid,value\\) VALUES \\('(.*?)', '(.*?)', '(.*?)', '(.*?)', '(.*?)'\\)/", $sql, $m)) {
                        $key = "objpref-{$m[2]}-{$m[4]}-{$m[3]}-{$m[1]}";
                        $this->objprefs[$key]                 = $m[5];
                        Database::$queryCacheResults[$key]    = [['value' => $m[5]]];
                        Database::$affected_rows              = 1;
                        return 1;
                    }

                    if (preg_match("/UPDATE module_objprefs SET value=value\\+(-?[0-9.]+) WHERE modulename='([^']+)' AND setting='([^']+)' AND objtype='([^']+)' AND objid=([0-9]+)/", $sql, $m)) {
                        $increment = (float) $m[1];
                        $key       = "objpref-{$m[4]}-{$m[5]}-{$m[3]}-{$m[2]}";
                        if (isset($this->objprefs[$key])) {
                            $new                               = (string) ((float) $this->objprefs[$key] + $increment);
                            $this->objprefs[$key]              = $new;
                            Database::$queryCacheResults[$key] = [['value' => $new]];
                            Database::$affected_rows           = 1;
                            return 1;
                        }
                        Database::$affected_rows = 0;
                        return 0;
                    }

                    if (preg_match("/INSERT INTO module_objprefs\\(modulename,objtype,setting,objid,value\\) VALUES \\('(.*?)', '(.*?)', '(.*?)', '(.*?)', '(.*?)'\\)/", $sql, $m)) {
                        $key = "objpref-{$m[2]}-{$m[4]}-{$m[3]}-{$m[1]}";
                        $this->objprefs[$key]                 = $m[5];
                        Database::$queryCacheResults[$key]    = [['value' => $m[5]]];
                        Database::$affected_rows              = 1;
                        return 1;
                    }

                    if (preg_match("/DELETE FROM module_objprefs WHERE objtype='([^']+)' AND objid='([^']+)'/", $sql, $m)) {
                        foreach (array_keys($this->objprefs) as $key) {
                            if (str_starts_with($key, "objpref-{$m[1]}-{$m[2]}-")) {
                                unset($this->objprefs[$key]);
                                unset(Database::$queryCacheResults[$key]);
                            }
                        }
                        Database::$affected_rows = 1;
                        return 1;
                    }

                    Database::$affected_rows = 0;
                    return 0;
                }
            };

            Database::$doctrineConnection        = $conn;
            \Lotgd\Doctrine\Bootstrap::$conn = $conn;
        }

        protected function tearDown(): void
        {
            Database::$doctrineConnection = null;
            \Lotgd\Doctrine\Bootstrap::$conn = null;
        }

        public function testObjectPrefsLifecycle(): void
        {
            set_module_objpref('creature', 1, 'key', 'val', 'testmod');
            self::assertSame('val', get_module_objpref('creature', 1, 'key', 'testmod'));

            increment_module_objpref('creature', 1, 'key', 1, 'testmod');
            increment_module_objpref('creature', 1, 'key', 1, 'testmod');
            self::assertSame(2, (int) get_module_objpref('creature', 1, 'key', 'testmod'));

            increment_module_objpref('creature', 1, 'key', -1, 'testmod');
            self::assertSame(1.0, (float) get_module_objpref('creature', 1, 'key', 'testmod'));

            increment_module_objpref('creature', 1, 'key', 1.5, 'testmod');
            self::assertSame(2.5, (float) get_module_objpref('creature', 1, 'key', 'testmod'));

            $conn     = Database::$doctrineConnection;
            $preCache = Database::$queryCacheResults;
            $prePrefs = $conn->objprefs;
            module_delete_objprefs('creature', 2);
            self::assertSame($preCache, Database::$queryCacheResults);
            self::assertSame($prePrefs, $conn->objprefs);

            module_delete_objprefs('creature', 1);
            self::assertNull(get_module_objpref('creature', 1, 'key', 'testmod'));
        }
    }
}
