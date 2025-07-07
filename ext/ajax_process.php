<?php
// File ajax_process.php
define("OVERRIDE_FORCED_NAV",true);

require_once(__DIR__ . '/lotgd_common.php');
require_once(__DIR__ . '/ajax_common.php');
require_once(__DIR__ . '/ajax_server.php');

// Call the Jaxon processing engine
if($jaxon->canProcessRequest()) {
    $jaxon->processRequest();
}
