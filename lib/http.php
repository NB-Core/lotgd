<?php

// translator ready
// addnews ready
// mail ready
use Lotgd\Http;

function httpget($var)
{
    return Http::get($var);
}
function httpallget()
{
    return Http::allGet();
}
function httpset($var, $val, $force = false)
{
    Http::set($var, $val, $force);
}
function httppost($var)
{
    return Http::post($var);
}
function httppostisset($var)
{
    return Http::postIsset($var);
}
function httppostset($var, $val, $sub = false)
{
    Http::postSet($var, $val, $sub);
}
function httpallpost()
{
    return Http::allPost();
}
function postparse($verify = false, $subval = false)
{
    return Http::postParse($verify, $subval);
}
