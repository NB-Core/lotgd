<?php

declare(strict_types=1);

/**
 * Base setup for AJAX requests, including the Jaxon library and
 * initial JavaScript dependencies. This file prepares the page for
 * asynchronous features like mail and commentary updates.
 */

require_once(__DIR__ . '/ajax_common.php');

global $jaxon;
$s_css = $jaxon->getCss();

if (isset($pre_headscript)) {
    $pre_headscript .= $s_css . "<script src=\"/ext/js/jquery-3.6.3.min.js\"></script>";
} else {
    $pre_headscript = "";
}
//$pre_headscript.="ha$s_js ha";
addnav("", "ext/ajax_process.php");
