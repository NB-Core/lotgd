<?php

declare(strict_types=1);

// addnews ready
// translator ready
// mail ready

function arraytourl(array $array): string
{
       // takes an array and encodes it in key=val&key=val form.
       reset($array);
       $url = "";
       $i = 0;
    foreach ($array as $key => $val) {
        if ($i > 0) {
                   $url .= "&";
        }
            $i++;
            $url .= rawurlencode((string)$key) . "=" . rawurlencode((string)$val);
    }

       return $url;
}

function urltoarray(string $url): array
{
       // takes a URL and returns its arguments in array form.
    if (strpos($url, "?") !== false) {
            $url = substr($url, strpos($url, "?") + 1);
    }

       $a = explode("&", $url);
       $array = [];
    foreach ($a as $pair) {
            $b = explode("=", $pair);
            $array[urldecode($b[0])] = isset($b[1]) ? urldecode($b[1]) : '';
    }

       return $array;
}
