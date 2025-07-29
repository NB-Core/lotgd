<?php

declare(strict_types=1);

use Lotgd\Redirect;
use Lotgd\MySQL\Database;

$sql = "DELETE FROM " . Database::prefix("bans") . " WHERE ipfilter = '" . httpget("ipfilter") . "' AND uniqueid = '" . httpget("uniqueid") . "'";
Database::query($sql);
Redirect::redirect("user.php?op=removeban");
