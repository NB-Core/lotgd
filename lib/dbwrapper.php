<?php
require_once "lib/errorhandling.php";
require_once "settings.php";

$dbinfo = [];
$dbinfo['queriesthishit'] = 0;
$dbinfo['querytime'] = 0;
$dbinfo['DB_DATACACHEPATH'] = '';

// Legacy compatibility - database functions now reside in Lotgd\MySQL
require_once 'lib/dbmysqli.php';

