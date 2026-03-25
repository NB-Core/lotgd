<?php

declare(strict_types=1);

use Lotgd\Redirect;
use Lotgd\MySQL\Database;
use Lotgd\Http;
use Doctrine\DBAL\ParameterType;

$conn = Database::getDoctrineConnection();
$ipFilter = (string) (Http::get('ipfilter') ?: '');
$uniqueId = (string) (Http::get('uniqueid') ?: '');

$conn->executeStatement(
    'DELETE FROM ' . Database::prefix('bans') . ' WHERE ipfilter = :ip AND uniqueid = :id',
    [
        'ip' => $ipFilter,
        'id' => $uniqueId,
    ],
    [
        'ip' => ParameterType::STRING,
        'id' => ParameterType::STRING,
    ]
);
Redirect::redirect('bans.php?op=removeban');
