<?php
// addnews ready
// translator ready
// mail ready

use Lotgd\DateTime as LotgdDateTime;

function reltime($date, $short = true)
{
    return LotgdDateTime::reltime($date, $short);
}

function readabletime($date, $short = true)
{
    return LotgdDateTime::readabletime($date, $short);
}

function relativedate($indate)
{
    return LotgdDateTime::relativedate($indate);
}

function checkday()
{
    LotgdDateTime::checkday();
}

function is_new_day($now = 0)
{
    return LotgdDateTime::is_new_day($now);
}

function getgametime()
{
    return LotgdDateTime::getgametime();
}

function gametime()
{
    return LotgdDateTime::gametime();
}

function convertgametime($intime, $debug = false)
{
    return LotgdDateTime::convertgametime($intime, $debug);
}

function gametimedetails()
{
    return LotgdDateTime::gametimedetails();
}

function secondstonextgameday($details = false)
{
    return LotgdDateTime::secondstonextgameday($details);
}

function getmicrotime()
{
    return LotgdDateTime::getmicrotime();
}

function datedifference($date_1, $date_2 = DATETIME_TODAY, $differenceFormat = '%R%a')
{
    return LotgdDateTime::datedifference($date_1, $date_2, $differenceFormat);
}

function datedifference_events($date_1, $abs = false)
{
    return LotgdDateTime::datedifference_events($date_1, $abs);
}
