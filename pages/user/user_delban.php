<?php
declare(strict_types=1);

use Lotgd\Redirect;

$sql = "DELETE FROM " . db_prefix("bans") . " WHERE ipfilter = '" . httpget("ipfilter") . "' AND uniqueid = '" . httpget("uniqueid") . "'";
db_query($sql);
Redirect::redirect("user.php?op=removeban");
