<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Redirect;

$conn = Database::getDoctrineConnection();
$conn->executeStatement(
    'DELETE FROM ' . Database::prefix('bans') . ' WHERE ipfilter = :ip AND uniqueid = :id',
    [
        'ip' => Http::get('ipfilter'),
        'id' => Http::get('uniqueid'),
    ],
    [
        'ip' => ParameterType::STRING,
        'id' => ParameterType::STRING,
    ]
);
Redirect::redirect('bans.php?op=removeban');
