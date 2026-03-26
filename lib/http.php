<?php

// translator ready
// addnews ready
// mail ready
use Lotgd\Http;

/**
 * Legacy compatibility escape helper for lib/http.php wrappers.
 *
 * The legacy wrappers intentionally preserve historical behaviour by returning
 * addslashes()-escaped scalar values. Core/refactored code must use
 * Lotgd\Http directly, which intentionally returns raw values.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function legacy_http_escape(mixed $value): mixed
{
    if (is_string($value)) {
        return addslashes($value);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = legacy_http_escape($item);
        }
    }

    return $value;
}

function httpget($var)
{
    return legacy_http_escape(Http::get($var));
}
function httpallget()
{
    return legacy_http_escape(Http::allGet());
}
function httpset($var, $val, $force = false)
{
    Http::set($var, $val, $force);
}
function httppost($var)
{
    return legacy_http_escape(Http::post($var));
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
    return legacy_http_escape(Http::allPost());
}
function postparse($verify = false, $subval = false)
{
    [$columns, $placeholders, $parameters] = Http::postParse($verify, $subval);

    return [$columns, $placeholders, legacy_http_escape($parameters)];
}
