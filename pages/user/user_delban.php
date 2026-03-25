<?php

declare(strict_types=1);

use Lotgd\Redirect;
use Lotgd\MySQL\Database;
use Lotgd\Http;
use Doctrine\DBAL\ParameterType;

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
