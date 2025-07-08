<?php
require_once(__DIR__ . '/ajax_common.php');

global $jaxon;
$s_css=$jaxon->getCss();

if (isset($pre_headscript)) {
    $pre_headscript.=$s_css."<script src=\"/ext/js/jquery-3.6.3.min.js\"></script>";
} else {
	$pre_headscript = "";
}
//$pre_headscript.="ha$s_js ha";
addnav("","ext/ajax_process.php");
