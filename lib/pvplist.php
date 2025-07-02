<?php
use Lotgd\Pvp;

$pvptime = getsetting('pvptimeout', 600);
$pvptimeout = date('Y-m-d H:i:s', strtotime("-$pvptime seconds"));

function pvplist($location = false, $link = false, $extra = false, $sql = false)
{
    Pvp::playerList($location, $link, $extra, $sql);
}
