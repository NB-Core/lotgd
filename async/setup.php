<?php

declare(strict_types=1);

/**
 * Base setup for AJAX requests, including the Jaxon library and
 * initial JavaScript dependencies. This file prepares the page for
 * asynchronous features like mail and commentary updates.
 */

require_once __DIR__ . '/common/jaxon.php';

global $jaxon;

$s_js = $jaxon->getJs();
$s_script = $jaxon->getScript();

$pre_headscript = ($pre_headscript ?? '')
    . $jaxon->getCss()
    . $s_js
    . "<script src='/async/js/lotgd.jaxon.js'></script>"
    . $s_script
    . "<script src='/async/js/jquery.min.js' defer></script>"
    . "<script src='/async/js/ajax_polling.js' defer></script>";

addnav("", "async/process.php");

