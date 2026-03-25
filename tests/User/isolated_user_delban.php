<?php

declare(strict_types=1);

namespace Lotgd {
    if (!class_exists(__NAMESPACE__ . '\\Redirect', false)) {
        class Redirect
        {
            public static function redirect(string $location, string|bool $reason = false): void
            {
            }
        }
    }
}

namespace {
    use Lotgd\MySQL\Database;
    Database::$doctrineConnection = null;
    Database::$instance = null;
    Database::$mockResults = [];

    require LOTGD_TEST_ROOT . '/pages/user/user_delban.php';

    $conn = Database::getDoctrineConnection();
    $statement = $conn->executeStatements[0] ?? null;
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

    echo json_encode(['statement' => $normalize($statement)], JSON_THROW_ON_ERROR);
}
