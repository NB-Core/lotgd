<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Jaxon\Jaxon;                      // Use the jaxon core class
use function Jaxon\jaxon;

// Load asynchronous configuration settings
require_once __DIR__ . '/settings.php';

global $jaxon;
// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', '/async/process.php');
// Use empty prefix to get clean namespace: Lotgd.Async.Handler.*
$jaxon->setOption('core.prefix.class', '');

// Configure the Jaxon client library - disable auto-export since we manage our own JS files
$jaxon->setOption('js.app.export', false);
$jaxon->setOption('js.app.dir', __DIR__ . '/../js');
$jaxon->setOption('js.app.uri', '/async/js');

// DISABLE debug mode to prevent writeDebugMessage errors
$jaxon->setOption('core.debug.on', false);
$jaxon->setOption('core.debug.verbose', false);

/**
 * Register async handlers from the directory to keep the historical Jaxon
 * client export shape (Lotgd.Async.Handler.*) stable for runtime bridge code.
 *
 * Security note: passkey method allowlisting is enforced server-side in
 * async/process.php so hardening does not alter this client namespace contract.
 */
$jaxon->register(Jaxon::CALLABLE_DIR, __DIR__ . '/../../src/Lotgd/Async/Handler', [
    'namespace' => 'Lotgd\\Async\\Handler',
]);
