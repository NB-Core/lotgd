<?php

declare(strict_types=1);

/**
 * Base setup for AJAX requests, including the Jaxon library and
 * initial JavaScript dependencies. This file prepares the page for
 * asynchronous features like mail and commentary updates.
 */

require_once __DIR__ . '/common/jaxon.php';

global $jaxon, $s_js;
$s_css = $jaxon->getCss();

if (isset($pre_headscript)) {
    $pre_headscript .= $s_css . $s_js . "<script src=\"/async/js/jquery.min.js\" defer></script>";
} else {
    $pre_headscript = $s_css . $s_js . "<script src=\"/async/js/jquery.min.js\" defer></script>";
}
addnav("", "async/process.php");

