<?php

declare(strict_types=1);

namespace {
    use Lotgd\MySQL\Database;
    use Lotgd\Translator;

    if (!function_exists('modulehook')) {
        /**
         * Test shim for module validation hooks.
         *
         * @param array<mixed> $args
         * @return array<mixed>
         */
        function modulehook(string $hookname, array $args = [], bool $allowinactive = false, string $modulename = ''): array
        {
            return $args;
        }
    }

    if (!function_exists('get_module_info')) {
        /**
         * Test shim returning declared preference keys for allowlist behaviour.
         *
         * @return array<string,mixed>
         */
        function get_module_info(string $shortname, bool $with_db = true): array
        {
            if (isset($GLOBALS['__test_module_info']) && is_array($GLOBALS['__test_module_info'])) {
                return $GLOBALS['__test_module_info'];
            }

            return [
                'prefs' => [
                    'display_name' => 'Display Name|string|',
                    'theme' => 'Theme|string|',
                ],
            ];
        }
    }

    if (!function_exists('httpset')) {
        function httpset(string $var, mixed $value, bool $force = false): void
        {
        }
    }

    $output = new class {
        public function outputNotl(string $format, mixed ...$args): void
        {
        }

        public function output(string $format, mixed ...$args): void
        {
        }
    };

    Database::$doctrineConnection = null;
    Database::$instance = null;
    Database::$mockResults = [];
    Translator::enableTranslation(false);

    require LOTGD_TEST_ROOT . '/pages/user/user_savemodule.php';

    $conn = Database::getDoctrineConnection();
    $statements = $conn->executeStatements;
    $statement = $statements[0] ?? null;
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
        }

        return $value;
    };

    echo json_encode(['statement' => $normalize($statement), 'statements' => $normalize($statements)], JSON_THROW_ON_ERROR);
}
