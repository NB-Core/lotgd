<?php

declare(strict_types=1);

use Lotgd\Redirect;
use Lotgd\MySQL\Database;
use Lotgd\Http;
use Doctrine\DBAL\ParameterType;

$conn = Database::getDoctrineConnection();
$ipFilterRaw = Http::get('ipfilter');
$uniqueIdRaw = Http::get('uniqueid');

/**
 * Normalize only missing values (false) to empty strings.
 * Keep valid falsy string values (e.g. "0") unchanged.
 */
$ipFilter = $ipFilterRaw === false ? '' : (string) $ipFilterRaw;
$uniqueId = $uniqueIdRaw === false ? '' : (string) $uniqueIdRaw;

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
