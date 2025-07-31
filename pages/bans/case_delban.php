<?php

declare(strict_types=1);

use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Redirect;

$sql = 'DELETE FROM ' . Database::prefix('bans') . " WHERE ipfilter = '" . Http::get('ipfilter') . "' AND uniqueid = '" . Http::get('uniqueid') . "'";
Database::query($sql);
Redirect::redirect('bans.php?op=removeban');
