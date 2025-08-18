<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../../autoload.php';

use Jaxon\Jaxon;
use function Jaxon\jaxon;

require_once __DIR__ . '/../server.php';

global $jaxon;
// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', 'async/process.php');

// Register callable functions
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'mail_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_text');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'timeout_status');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'commentary_refresh');
$jaxon->register(Jaxon::CALLABLE_FUNCTION, 'poll_updates');
