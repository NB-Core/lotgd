<?php

// addnews ready
// translator ready
// mail ready

use Lotgd\DateTime as LotgdDateTime;

function reltime($date, $short = true)
{
    return LotgdDateTime::relTime($date, $short);
}

function readabletime($date, $short = true)
{
    return LotgdDateTime::readableTime($date, $short);
}

function relativedate($indate)
{
    return LotgdDateTime::relativeDate($indate);
}

function checkday()
{
    LotgdDateTime::checkDay();
}

function is_new_day($now = 0)
{
    return LotgdDateTime::isNewDay($now);
}

function getgametime()
{
    return LotgdDateTime::getGameTime();
}

function gametime()
{
    return LotgdDateTime::gameTime();
}

function convertgametime($intime, $debug = false)
{
    return LotgdDateTime::convertGameTime($intime, $debug);
}

function gametimedetails()
{
    return LotgdDateTime::gameTimeDetails();
}

function secondstonextgameday($details = false)
{
    return LotgdDateTime::secondsToNextGameDay($details);
}

function getmicrotime()
{
    return LotgdDateTime::getMicroTime();
}

function datedifference($date_1, $date_2 = DATETIME_TODAY, $differenceFormat = '%R%a')
{
    return LotgdDateTime::dateDifference($date_1, $date_2, $differenceFormat);
}

function datedifference_events($date_1, $abs = false)
{
    return LotgdDateTime::dateDifferenceEvents($date_1, $abs);
}
