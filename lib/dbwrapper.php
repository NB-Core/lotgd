<?php
// addnews ready
// translator ready
// mail ready
require_once("lib/errorhandling.php");
require_once("lib/datacache.php");
require_once("settings.php");

$dbinfo = array();
$dbinfo['queriesthishit']=0;
$dbinfo['querytime']=0;
$dbinfo['DB_DATACACHEPATH']="";

require_once("lib/dbmysqli.php");
?>
