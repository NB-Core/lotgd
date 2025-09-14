<?php

use Lotgd\ErrorHandling;

ErrorHandling::configure();
require_once "settings.php";

// Legacy compatibility - database functions now reside in Lotgd\MySQL
require_once 'lib/dbmysqli.php';
