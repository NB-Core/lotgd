<?php
require_once(__DIR__ . '/vendor/autoload.php'); // Start autoload 

use Jaxon\Jaxon;                      // Use the jaxon core class
use Jaxon\Response\Response;          // and the Response class
use function Jaxon\jaxon;

require_once(__DIR__ . '/ajax_settings.php'); 

// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', 'ext/ajax_process.php');

#$jaxon->setOption('core.debug.on',1);
#$jaxon->setOption('core.debug.verbose',1);


// Register an instance of the class with Jaxon
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'mail_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_text', ['class'=>'lotgdAjax']);
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'timeout_status', ['class'=>'lotgdAjax']);
