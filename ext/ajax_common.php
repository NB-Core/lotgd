<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../autoload.php'; // Start autoload

use Jaxon\Jaxon;                      // Use the jaxon core class
use Jaxon\Response\Response;          // and the Response class
use function Jaxon\jaxon;

require_once(__DIR__ . '/ajax_settings.php');
global $jaxon;
// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', 'ext/ajax_process.php');

//$jaxon->setOption('core.debug.on',1);
//$jaxon->setOption('core.debug.verbose',1);


// Register an instance of the class with Jaxon
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'mail_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_text');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'timeout_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_refresh');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'poll_updates');
