<?php
require_once(__DIR__ . '/ajax_common.php');

// Get the Jaxon singleton object
//$jaxon = jaxon();

$s_css=$jaxon->getCss();
$s_js=($jaxon->getJs());
$s_script=($jaxon->getScript());
if (isset($pre_headscript)) {
    $pre_headscript.=$s_css."<script src=\"/ext/js/jquery-3.6.3.min.js\"></script>";
} else {
	$pre_headscript = "";
}
addnav("","ext/ajax_process.php");
